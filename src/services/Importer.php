<?php

namespace justinholtweb\cleanair\services;

use Craft;
use craft\db\Query as DbQuery;
use craft\elements\User;
use justinholtweb\cleanair\models\Criterion;
use justinholtweb\cleanair\models\FieldDefinition;
use justinholtweb\cleanair\models\FilterSet;
use justinholtweb\cleanair\models\SavedFilter;
use justinholtweb\cleanair\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Bringing CP Filters' saved filters across.
 *
 * CP Filters stopped at Craft 4 and was discontinued in 2024, so anybody on Craft 5 with a
 * shelf of saved filters has them stranded in a table no plugin reads any more. This reads
 * that table directly rather than asking the old plugin for anything — it doesn't need to be
 * installed, or even installable, for its filters to come across.
 *
 * The mapping is mostly mechanical, with three places it has to think:
 *
 *  - CP Filters kept its element types as short keys (`entries`) and its sources as bare IDs.
 *    Entry type and volume IDs survive a Craft 4 → 5 upgrade, so those land exactly.
 *  - `is greater than` meant one thing on a number and another on a date. The mapping resolves
 *    the field first and picks the operator that matches its kind.
 *  - A filter can name a field that no longer exists. That's reported against the filter
 *    rather than silently dropped, because a filter that quietly lost a condition returns
 *    more rows than it should — the dangerous direction.
 */
class Importer extends Component
{
    public const TABLE = '{{%cpfilters_savedfilters}}';

    /** CP Filters' element type keys, in its own vocabulary. */
    private const TYPE_MAP = [
        'entries' => 'craft\\elements\\Entry',
        'assets' => 'craft\\elements\\Asset',
        'categories' => 'craft\\elements\\Category',
        'tags' => 'craft\\elements\\Tag',
        'users' => 'craft\\elements\\User',
        'orders' => 'craft\\commerce\\elements\\Order',
        'products' => 'craft\\commerce\\elements\\Product',
    ];

    /** How its `filterGroupId` should be read, per type key. */
    private const SOURCE_MAP = [
        'entries' => 'entryType',
        'assets' => 'volume',
        'categories' => 'group',
        'tags' => 'group',
        'products' => 'productType',
    ];

    /** Its filter labels, which were stored verbatim in the criteria JSON. */
    private const OPERATOR_MAP = [
        'contains' => 'contains',
        'starts with' => 'startsWith',
        'ends with' => 'endsWith',
        'is equal to' => 'eq',
        'is assigned' => 'isAssigned',
        'is greater than' => 'gt',
        'is less than' => 'lt',
        'is empty' => 'empty',
        'is not empty' => 'notEmpty',
    ];

    /** Attribute handles that changed name between the two plugins. */
    private const HANDLE_MAP = [
        'orderStatus' => 'orderStatusId',
    ];

    /** Whether there is a CP Filters table to read. */
    public function isAvailable(): bool
    {
        return Craft::$app->getDb()->tableExists(self::TABLE);
    }

    public function countAvailable(bool $includeDeleted = false): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $query = (new DbQuery())->from([self::TABLE]);

        if (!$includeDeleted) {
            $query->where(['dateDeleted' => null]);
        }

        return (int)$query->count();
    }

    /**
     * Reads every CP Filters row and works out what it would become, without writing
     * anything. This is what the import screen shows, and what `--dry-run` prints.
     *
     * @return array<int,array{
     *     id: int, name: string, elementType: string|null, typeKey: string,
     *     source: string|null, sourceLabel: string|null, owner: string|null,
     *     criteria: Criterion[], warnings: string[], importable: bool
     * }>
     */
    public function preview(bool $includeDeleted = false): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $query = (new DbQuery())
            ->select(['id', 'userId', 'title', 'includeDrafts', 'filterElementType', 'filterGroupId', 'filterCriteria', 'dateCreated'])
            ->from([self::TABLE])
            ->orderBy(['id' => SORT_ASC]);

        if (!$includeDeleted) {
            $query->where(['dateDeleted' => null]);
        }

        $rows = [];
        foreach ($query->all() as $row) {
            $rows[] = $this->mapRow($row);
        }

        return $rows;
    }

    /**
     * Maps one CP Filters row.
     */
    private function mapRow(array $row): array
    {
        $typeKey = (string)($row['filterElementType'] ?? '');
        $warnings = [];

        $elementType = self::TYPE_MAP[$typeKey] ?? null;

        if ($elementType === null) {
            return [
                'id' => (int)$row['id'],
                'name' => (string)($row['title'] ?: Craft::t('cleanair', 'Untitled filter')),
                'elementType' => null,
                'typeKey' => $typeKey,
                'source' => null,
                'sourceLabel' => null,
                'owner' => $this->ownerName($row['userId'] ?? null),
                'criteria' => [],
                'warnings' => [Craft::t('cleanair', 'Clean Air doesn’t recognise the element type “{type}”.', ['type' => $typeKey])],
                'importable' => false,
            ];
        }

        $type = Plugin::getInstance()->types->forElementType($elementType);

        if (!$type || !$type->isAvailable()) {
            $warnings[] = Craft::t('cleanair', '{type} isn’t available on this install — Craft Commerce may not be installed.', ['type' => $elementType]);
        }

        $source = null;
        $sourceLabel = null;
        $groupId = $row['filterGroupId'] ?? null;

        if ($groupId && isset(self::SOURCE_MAP[$typeKey])) {
            $source = self::SOURCE_MAP[$typeKey] . ':' . (int)$groupId;
            $sourceLabel = $this->sourceLabel($type, $source);

            if ($sourceLabel === null) {
                $warnings[] = Craft::t('cleanair', 'The {kind} this filter pointed at (ID {id}) no longer exists, so the filter will run across every source.', [
                    'kind' => self::SOURCE_MAP[$typeKey],
                    'id' => (int)$groupId,
                ]);
                $source = null;
            }
        }

        $criteria = [];

        if ($type) {
            foreach ($this->decodeCriteria($row['filterCriteria'] ?? null) as $raw) {
                $mapped = $this->mapCriterion($type, $source, $raw, $warnings);
                if ($mapped !== null) {
                    $criteria[] = $mapped;
                }
            }
        }

        return [
            'id' => (int)$row['id'],
            'name' => (string)($row['title'] ?: Craft::t('cleanair', 'Untitled filter')),
            'elementType' => $elementType,
            'typeKey' => $typeKey,
            'source' => $source,
            'sourceLabel' => $sourceLabel,
            'owner' => $this->ownerName($row['userId'] ?? null),
            'userId' => $row['userId'] ?? null,
            'includeDrafts' => in_array((string)($row['includeDrafts'] ?? ''), ['y', '1'], true),
            'criteria' => $criteria,
            'warnings' => $warnings,
            'importable' => $type !== null && $type->isAvailable(),
        ];
    }

    /**
     * CP Filters double-encoded its criteria in places — the controller JSON-encoded a value
     * that had already been through `json_encode` on the way in — so a single decode isn't
     * always enough.
     */
    private function decodeCriteria(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Turns one `{fieldHandle, filterType, value}` row into a Clean Air criterion.
     */
    private function mapCriterion($type, ?string $source, mixed $raw, array &$warnings): ?Criterion
    {
        if (!is_array($raw)) {
            return null;
        }

        $handle = (string)($raw['fieldHandle'] ?? '');
        $filterType = (string)($raw['filterType'] ?? '');
        $value = $raw['value'] ?? null;

        if ($handle === '' || $filterType === '') {
            return null;
        }

        $handle = self::HANDLE_MAP[$handle] ?? $handle;
        $operator = self::OPERATOR_MAP[$filterType] ?? null;

        if ($operator === null) {
            $warnings[] = Craft::t('cleanair', 'Clean Air has no equivalent for the “{filter}” filter, so that condition was left out.', ['filter' => $filterType]);
            return null;
        }

        $definition = Plugin::getInstance()->fields->definition($type, $source, $handle);

        if (!$definition) {
            $warnings[] = Craft::t('cleanair', 'There is no longer a field or attribute called “{handle}”, so that condition was left out.', ['handle' => $handle]);
            return null;
        }

        $operator = $this->adjustOperator($definition, $operator, $value);

        if (!Plugin::getInstance()->operators->supports($definition, $operator)) {
            $warnings[] = Craft::t('cleanair', '“{filter}” can’t be applied to {field} in Clean Air, so that condition was left out.', [
                'filter' => $filterType,
                'field' => $definition->label,
            ]);
            return null;
        }

        return new Criterion([
            'field' => $handle,
            'operator' => $operator,
            'value' => $this->adjustValue($definition, $operator, $value),
        ]);
    }

    /**
     * CP Filters used one operator label for several meanings. `is greater than` on a date
     * meant "after"; `is equal to` on a lightswitch meant "is on".
     */
    private function adjustOperator(FieldDefinition $definition, string $operator, mixed $value): string
    {
        if ($definition->kind === FieldDefinition::KIND_DATE) {
            return match ($operator) {
                'gt' => 'after',
                'lt' => 'before',
                'eq' => 'on',
                default => $operator,
            };
        }

        if ($definition->kind === FieldDefinition::KIND_BOOLEAN && $operator === 'eq') {
            return in_array((string)$value, ['1', 'true', 'y', 'yes', 'on'], true) ? 'isTrue' : 'isFalse';
        }

        if ($definition->kind === FieldDefinition::KIND_RELATION && $operator === 'contains') {
            return 'isAssigned';
        }

        return $operator;
    }

    private function adjustValue(FieldDefinition $definition, string $operator, mixed $value): mixed
    {
        if (in_array($operator, ['empty', 'notEmpty', 'isTrue', 'isFalse'], true)) {
            return null;
        }

        if ($operator === 'isAssigned') {
            return is_array($value) ? $value : [$value];
        }

        return $value;
    }

    private function sourceLabel($type, string $sourceKey): ?string
    {
        if (!$type) {
            return null;
        }

        foreach ($type->sources() as $source) {
            if ($source->key === $sourceKey) {
                return $source->label;
            }
        }

        return null;
    }

    private function ownerName(mixed $userId): ?string
    {
        if (!$userId) {
            return null;
        }

        $user = Craft::$app->getUsers()->getUserById((int)$userId);

        return $user?->username ?? $user?->email;
    }

    /**
     * Writes the filters.
     *
     * @param array{
     *     ids?: int[]|null, includeDeleted?: bool, share?: bool,
     *     skipExisting?: bool, importSettings?: bool
     * } $options
     * @return array{imported: int, skipped: int, failed: int, messages: array<int,array{name:string,status:string,notes:string[]}>}
     */
    public function import(array $options = []): array
    {
        $ids = $options['ids'] ?? null;
        $includeDeleted = (bool)($options['includeDeleted'] ?? false);
        $share = (bool)($options['share'] ?? false);
        $skipExisting = (bool)($options['skipExisting'] ?? true);

        $report = ['imported' => 0, 'skipped' => 0, 'failed' => 0, 'messages' => []];

        foreach ($this->preview($includeDeleted) as $row) {
            if ($ids !== null && !in_array($row['id'], $ids, true)) {
                continue;
            }

            $notes = $row['warnings'];

            if (!$row['importable']) {
                $report['skipped']++;
                $report['messages'][] = ['name' => $row['name'], 'status' => 'skipped', 'notes' => $notes];
                continue;
            }

            if ($skipExisting && $this->alreadyImported($row['name'], $row['userId'] ?? null)) {
                $report['skipped']++;
                $notes[] = Craft::t('cleanair', 'A filter with this name already exists.');
                $report['messages'][] = ['name' => $row['name'], 'status' => 'skipped', 'notes' => $notes];
                continue;
            }

            $filterSet = new FilterSet([
                'elementType' => $row['elementType'],
                'source' => $row['source'],
                'criteria' => $row['criteria'],
                'includeDrafts' => (bool)($row['includeDrafts'] ?? false),
            ]);

            $saved = new SavedFilter([
                'name' => $row['name'],
                'elementType' => $row['elementType'],
                'source' => $row['source'],
                'filter' => $filterSet,
                'userId' => $this->resolveOwner($row['userId'] ?? null),
                'visibility' => $share ? SavedFilter::VISIBILITY_SHARED : SavedFilter::VISIBILITY_PRIVATE,
            ]);

            $errors = [];

            try {
                $success = Plugin::getInstance()->savedFilters->save($saved, $errors);
            } catch (Throwable $e) {
                $success = false;
                $errors[] = $e->getMessage();
            }

            if ($success) {
                $report['imported']++;
                $report['messages'][] = [
                    'name' => $row['name'],
                    'status' => $notes ? 'warning' : 'imported',
                    'notes' => $notes,
                ];
            } else {
                $report['failed']++;
                $report['messages'][] = [
                    'name' => $row['name'],
                    'status' => 'failed',
                    'notes' => array_merge($notes, $errors),
                ];
            }
        }

        if ($options['importSettings'] ?? false) {
            $settingsNotes = $this->importSettings();
            if ($settingsNotes) {
                $report['messages'][] = [
                    'name' => Craft::t('cleanair', 'CP Filters settings'),
                    'status' => 'imported',
                    'notes' => $settingsNotes,
                ];
            }
        }

        return $report;
    }

    /**
     * A user who has since been deleted leaves their filters ownerless rather than losing
     * them — an ownerless filter is still readable by admins and can be adopted.
     */
    private function resolveOwner(mixed $userId): ?int
    {
        if (!$userId) {
            return null;
        }

        $user = Craft::$app->getUsers()->getUserById((int)$userId);

        return $user instanceof User ? $user->id : null;
    }

    private function alreadyImported(string $name, mixed $userId): bool
    {
        return \justinholtweb\cleanair\records\FilterRecord::find()
            ->where(['name' => $name, 'userId' => $this->resolveOwner($userId)])
            ->exists();
    }

    /**
     * Carries CP Filters' own settings across: which sources were filterable, which extra
     * field types it had been taught about, and whether Commerce was in play.
     *
     * @return string[] Notes on what was brought over.
     */
    public function importSettings(): array
    {
        $old = Craft::$app->getProjectConfig()->get('plugins.cpfilters.settings') ?? [];

        // A `config/cpfilters.php` file overrode project config, and is the more likely place
        // for these to live — CP Filters documented the file, not the settings screen.
        $configFile = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . 'cpfilters.php';

        if (is_file($configFile)) {
            try {
                $fromFile = require $configFile;
                if (is_array($fromFile)) {
                    $old = array_merge($old, $fromFile);
                }
            } catch (Throwable) {
            }
        }

        if (!$old) {
            return [];
        }

        $settings = Plugin::getInstance()->getSettings();
        $notes = [];

        $sourceSettings = [
            'filterableEntryTypeIds' => ['entries', 'entryType'],
            'filterableAssetVolumeIds' => ['assets', 'volume'],
            'filterableCategoryGroupIds' => ['categories', 'group'],
            'filterableTagGroupIds' => ['tags', 'group'],
            'filterableProductTypeIds' => ['products', 'productType'],
        ];

        $filterableSources = $settings->filterableSources;

        foreach ($sourceSettings as $oldKey => [$typeKey, $sourceType]) {
            $ids = $old[$oldKey] ?? null;

            if (!is_array($ids) || !$ids) {
                continue;
            }

            $filterableSources[$typeKey] = array_map(fn($id) => "$sourceType:" . (int)$id, $ids);
            $notes[] = Craft::t('cleanair', '{count} filterable {type} carried over.', [
                'count' => count($ids),
                'type' => $typeKey,
            ]);
        }

        $settings->filterableSources = $filterableSources;

        if (!empty($old['additionalFieldTypes']) && is_array($old['additionalFieldTypes'])) {
            $settings->additionalFieldTypes = array_merge(
                $settings->additionalFieldTypes,
                $old['additionalFieldTypes'],
            );
            $notes[] = Craft::t('cleanair', '{count} additional field types carried over.', [
                'count' => count($old['additionalFieldTypes']),
            ]);
        }

        if (isset($old['includeCommerce'])) {
            $settings->includeCommerce = (bool)$old['includeCommerce'];
        }

        Craft::$app->getPlugins()->savePluginSettings(Plugin::getInstance(), $settings->toArray());

        return $notes;
    }
}
