<?php

namespace justinholtweb\cleanair;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\log\MonologTarget;
use craft\services\Fields as CraftFields;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\cleanair\fields\FilterField;
use justinholtweb\cleanair\models\Edition;
use justinholtweb\cleanair\models\Settings;
use justinholtweb\cleanair\services\Columns;
use justinholtweb\cleanair\services\Exports;
use justinholtweb\cleanair\services\Fields;
use justinholtweb\cleanair\services\Filters;
use justinholtweb\cleanair\services\Importer;
use justinholtweb\cleanair\services\Operators;
use justinholtweb\cleanair\services\Permissions;
use justinholtweb\cleanair\services\Query;
use justinholtweb\cleanair\services\SavedFilters;
use justinholtweb\cleanair\services\Types;
use justinholtweb\cleanair\variables\CleanAirVariable;
use yii\base\Event;

/**
 * Clean Air — advanced control panel filtering for every element type.
 *
 * A replacement for CP Filters, which stopped at Craft 4 and was discontinued in 2024. It
 * does what that did — pick an element type and a source, stack conditions, look at the
 * results, export them, save the filter — and then the things it never got to: any element
 * type rather than seven, OR as well as AND, relative dates, relation counts, shared
 * filters, chosen columns, four export formats, queued exports, console commands and Twig
 * access. And it imports the filters you already had.
 *
 * @property-read Types $types
 * @property-read Fields $fields
 * @property-read Operators $operators
 * @property-read Query $query
 * @property-read Filters $filters
 * @property-read Columns $columns
 * @property-read SavedFilters $savedFilters
 * @property-read Exports $exports
 * @property-read Importer $importer
 * @property-read Permissions $permissions
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'cleanair';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'types' => Types::class,
                'fields' => Fields::class,
                'operators' => Operators::class,
                'query' => Query::class,
                'filters' => Filters::class,
                'columns' => Columns::class,
                'savedFilters' => SavedFilters::class,
                'exports' => Exports::class,
                'importer' => Importer::class,
                'permissions' => Permissions::class,
            ],
        ];
    }

    /** Whether the Pro feature set is available. Every edition check goes through here. */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    public function init(): void
    {
        parent::init();

        $this->registerLogging();
        $this->registerCpUrlRules();
        $this->registerPermissions();
        $this->registerTwigVariable();
        $this->registerFieldType();
    }

    protected function createSettingsModel(): ?\craft\base\Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        $types = [];
        foreach ($this->types->all() as $key => $type) {
            if (!$type->isAvailable()) {
                continue;
            }
            $types[$key] = $type;
        }

        $elementTypeOptions = [];
        foreach ($types as $type) {
            $elementTypeOptions[] = ['label' => $type->label(), 'value' => $type->elementType()];
        }

        return Craft::$app->getView()->renderTemplate('cleanair/_settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'types' => $types,
            'elementTypeOptions' => $elementTypeOptions,
            'isPro' => $this->isPro(),
        ]);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        if ($item === null) {
            return null;
        }

        $item['subnav']['filter'] = [
            'label' => Craft::t('cleanair', 'Filter'),
            'url' => 'cleanair',
        ];

        $item['subnav']['saved'] = [
            'label' => Craft::t('cleanair', 'Saved filters'),
            'url' => 'cleanair/saved',
        ];

        if ($this->permissions->canExport()) {
            $item['subnav']['exports'] = [
                'label' => Craft::t('cleanair', 'Exports'),
                'url' => 'cleanair/exports',
            ];
        }

        // The import screen only appears while there is something to import, so it doesn't
        // sit in the nav forever on a site that never had CP Filters.
        if ($this->permissions->canImport() && $this->importer->isAvailable()) {
            $item['subnav']['import'] = [
                'label' => Craft::t('cleanair', 'Import'),
                'url' => 'cleanair/import',
            ];
        }

        return $item;
    }

    /**
     * Clean Air's own log target, so "who exported the user list, and when" is answerable
     * without reading through everything else in `web.log`.
     */
    private function registerLogging(): void
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => self::LOG_CATEGORY,
            'categories' => [self::LOG_CATEGORY],
            'level' => $settings->logLevel,
            'logContext' => false,
            'allowLineBreaks' => true,
            'maxFiles' => 10,
        ]);
    }

    private function registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['cleanair'] = 'cleanair/filters/index';
                $event->rules['cleanair/saved'] = 'cleanair/saved-filters/index';
                $event->rules['cleanair/saved/<filterId:\d+>'] = 'cleanair/saved-filters/run';
                $event->rules['cleanair/exports'] = 'cleanair/exports/index';
                $event->rules['cleanair/import'] = 'cleanair/import/index';
                // Listed last so the named screens above win — an element type key can be
                // anything, including "saved".
                $event->rules['cleanair/type/<typeKey:[\w\-]+>'] = 'cleanair/filters/index';
            },
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $typePermissions = [];

                foreach ($this->types->all() as $key => $type) {
                    if (!$type->isAvailable()) {
                        continue;
                    }
                    $typePermissions[Permissions::typePermission($key)] = [
                        'label' => Craft::t('cleanair', 'Filter {type}', ['type' => $type->label()]),
                    ];
                }

                $event->permissions[] = [
                    'heading' => Craft::t('cleanair', 'Clean Air'),
                    'permissions' => [
                        Permissions::VIEW => [
                            'label' => Craft::t('cleanair', 'Use Clean Air'),
                            'info' => Craft::t('cleanair', 'Clean Air never shows anyone elements they couldn’t already see — this controls access to the tool itself.'),
                            'nested' => array_merge($typePermissions, [
                                Permissions::EXPORT => [
                                    'label' => Craft::t('cleanair', 'Export results'),
                                ],
                                Permissions::SAVE => [
                                    'label' => Craft::t('cleanair', 'Save filters'),
                                    'nested' => [
                                        Permissions::SHARE => [
                                            'label' => Craft::t('cleanair', 'Share filters with everyone'),
                                        ],
                                        Permissions::MANAGE_SHARED => [
                                            'label' => Craft::t('cleanair', 'Edit and delete other people’s shared filters'),
                                        ],
                                    ],
                                ],
                                Permissions::IMPORT => [
                                    'label' => Craft::t('cleanair', 'Import filters from CP Filters'),
                                ],
                            ]),
                        ],
                    ],
                ];
            },
        );
    }

    private function registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('cleanair', CleanAirVariable::class);
            },
        );
    }

    /**
     * The Clean Air Filter field, which points an entry at a saved filter and hands templates
     * back a live element query. Pro only — it's the front-end half of the plugin.
     */
    private function registerFieldType(): void
    {
        if (!Edition::allowsFilterField($this->isPro())) {
            return;
        }

        Event::on(
            CraftFields::class,
            CraftFields::EVENT_REGISTER_FIELD_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = FilterField::class;
            },
        );
    }
}
