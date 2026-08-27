<?php

namespace justinholtweb\cleanair\services;

use Craft;
use craft\db\Query as DbQuery;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\cleanair\migrations\Install;
use justinholtweb\cleanair\models\Edition;
use justinholtweb\cleanair\models\FilterSet;
use justinholtweb\cleanair\models\SavedFilter;
use justinholtweb\cleanair\Plugin;
use justinholtweb\cleanair\records\FilterRecord;
use yii\base\Component;

/**
 * Saving, finding and sharing filters.
 *
 * CP Filters saved filters per user with no way to share one, which meant every editor
 * rebuilt the same three filters by hand. A shared filter here is visible to everyone who
 * can use Clean Air but still belongs to its author — being able to see a colleague's filter
 * and being able to rewrite it are separate things.
 */
class SavedFilters extends Component
{
    public function getById(int $id): ?SavedFilter
    {
        $record = FilterRecord::findOne($id);
        return $record ? $this->toModel($record) : null;
    }

    public function getByHandle(string $handle): ?SavedFilter
    {
        $record = FilterRecord::findOne(['handle' => $handle]);
        return $record ? $this->toModel($record) : null;
    }

    public function getByUid(string $uid): ?SavedFilter
    {
        $record = FilterRecord::findOne(['uid' => $uid]);
        return $record ? $this->toModel($record) : null;
    }

    /**
     * Filters a user may see: their own, plus everyone's shared ones.
     *
     * @return SavedFilter[]
     */
    public function all(?User $user = null, ?string $elementType = null): array
    {
        $user ??= Craft::$app->getUser()->getIdentity();

        $query = FilterRecord::find()
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC]);

        if ($elementType !== null) {
            $query->andWhere(['elementType' => $elementType]);
        }

        if ($user && !$user->admin) {
            $query->andWhere([
                'or',
                ['userId' => $user->id],
                ['visibility' => SavedFilter::VISIBILITY_SHARED],
            ]);
        }

        $filters = [];
        foreach ($query->all() as $record) {
            /** @var FilterRecord $record */
            $filters[] = $this->toModel($record);
        }

        return $filters;
    }

    /**
     * @return SavedFilter[]
     */
    public function pinned(?User $user = null): array
    {
        return array_values(array_filter($this->all($user), fn(SavedFilter $filter) => $filter->pinned));
    }

    /** How many filters a user owns — the Lite cap counts per person, not per site. */
    public function countForUser(?int $userId): int
    {
        return (int)(new DbQuery())
            ->from([Install::TABLE_FILTERS])
            ->where(['userId' => $userId])
            ->count();
    }

    /**
     * @param string[] $errors
     */
    public function save(SavedFilter $filter, array &$errors = []): bool
    {
        if (!$filter->handle) {
            $filter->handle = $this->uniqueHandle($filter->name, $filter->id);
        }

        if (!$filter->validate()) {
            foreach ($filter->getErrorSummary(true) as $error) {
                $errors[] = $error;
            }
            return false;
        }

        $isNew = $filter->id === null;
        $isPro = Plugin::getInstance()->isPro();

        if ($isNew) {
            $max = Edition::maxSavedFilters($isPro);
            if ($max !== null && $this->countForUser($filter->userId) >= $max) {
                $errors[] = Craft::t('cleanair', 'Clean Air Lite keeps up to {max} saved filters per person. Upgrade to Pro for unlimited filters.', ['max' => $max]);
                return false;
            }
        }

        if ($filter->isShared() && !Edition::allowsSharing($isPro)) {
            $filter->visibility = SavedFilter::VISIBILITY_PRIVATE;
        }

        $record = $isNew ? new FilterRecord() : FilterRecord::findOne($filter->id);

        if (!$record) {
            $errors[] = Craft::t('cleanair', 'That filter no longer exists.');
            return false;
        }

        $record->name = $filter->name;
        $record->handle = $filter->handle;
        $record->elementType = $filter->elementType;
        $record->source = $filter->source;
        $record->criteria = json_encode($filter->filter->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $record->userId = $filter->userId;
        $record->visibility = $filter->visibility;
        $record->pinned = $filter->pinned;
        $record->sortOrder = $filter->sortOrder;

        if (!$record->save()) {
            foreach ($record->getErrorSummary(true) as $error) {
                $errors[] = $error;
            }
            return false;
        }

        $filter->id = $record->id;
        $filter->uid = $record->uid;

        return true;
    }

    public function delete(int $id): bool
    {
        $record = FilterRecord::findOne($id);
        return $record ? (bool)$record->delete() : false;
    }

    public function duplicate(SavedFilter $filter, ?User $user = null): SavedFilter
    {
        $user ??= Craft::$app->getUser()->getIdentity();

        $copy = new SavedFilter([
            'name' => Craft::t('cleanair', '{name} (copy)', ['name' => $filter->name]),
            'elementType' => $filter->elementType,
            'source' => $filter->source,
            'filter' => FilterSet::fromArray($filter->filter->toArray()),
            'userId' => $user?->id,
            'visibility' => SavedFilter::VISIBILITY_PRIVATE,
        ]);

        $copy->handle = $this->uniqueHandle($copy->name);

        return $copy;
    }

    /** Records that a filter was run, so the list can be ordered by what people actually use. */
    public function touch(int $id): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(Install::TABLE_FILTERS, ['dateLastRun' => Db::prepareDateForDb(new DateTime())], ['id' => $id])
            ->execute();
    }

    /**
     * Handles are what templates and console commands use to name a filter, so they have to
     * be unique across the site — including across two people who both saved "Recent posts".
     */
    public function uniqueHandle(string $name, ?int $exceptId = null): string
    {
        $base = StringHelper::toKebabCase($name) ?: 'filter';
        $base = mb_substr($base, 0, 56);
        $handle = $base;
        $suffix = 1;

        while ($this->handleTaken($handle, $exceptId)) {
            $suffix++;
            $handle = "$base-$suffix";
        }

        return $handle;
    }

    private function handleTaken(string $handle, ?int $exceptId): bool
    {
        $query = FilterRecord::find()->where(['handle' => $handle]);

        if ($exceptId !== null) {
            $query->andWhere(['not', ['id' => $exceptId]]);
        }

        return $query->exists();
    }

    private function toModel(FilterRecord $record): SavedFilter
    {
        $criteria = $record->criteria ? json_decode($record->criteria, true) : [];

        if (!is_array($criteria)) {
            $criteria = [];
        }

        // Older rows, and rows written by the CP Filters importer, may not carry the element
        // type inside the criteria blob — the column is the authority either way.
        $criteria['elementType'] = $record->elementType;
        $criteria['source'] = $record->source;

        return new SavedFilter([
            'id' => $record->id,
            'uid' => $record->uid,
            'name' => $record->name,
            'handle' => $record->handle,
            'elementType' => $record->elementType,
            'source' => $record->source,
            'filter' => FilterSet::fromArray($criteria),
            'userId' => $record->userId,
            'visibility' => $record->visibility,
            'pinned' => (bool)$record->pinned,
            'sortOrder' => (int)$record->sortOrder,
            // DateTimeHelper reads a bare database string as UTC and hands back the system
            // timezone; `new DateTime()` would assume the system timezone for both and shift
            // every date by the offset.
            'dateCreated' => DateTimeHelper::toDateTime($record->dateCreated) ?: null,
            'dateUpdated' => DateTimeHelper::toDateTime($record->dateUpdated) ?: null,
            'dateLastRun' => $record->dateLastRun ? (DateTimeHelper::toDateTime($record->dateLastRun) ?: null) : null,
        ]);
    }
}
