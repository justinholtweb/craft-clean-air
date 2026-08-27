<?php

namespace justinholtweb\cleanair\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\fields\BaseOptionsField;
use craft\fields\BaseRelationField;
use craft\models\FieldLayout;
use justinholtweb\cleanair\events\DefineFieldDefinitionEvent;
use justinholtweb\cleanair\models\FieldDefinition;
use justinholtweb\cleanair\Plugin;
use justinholtweb\cleanair\types\BaseType;
use ReflectionClass;
use Throwable;
use yii\base\Component;

/**
 * What you can filter on, for a given element type and source.
 *
 * The central decision is mapping a field class to a *kind* — the shape of its value. Kinds
 * drive the operator list, the value input and the column renderer, so teaching Clean Air
 * about a new field type is one line in the map (or one event handler), not a new branch in
 * five places. CP Filters kept an explicit class list and an explicit operator list per
 * class, which is why every unlisted field type simply vanished from its UI.
 */
class Fields extends Component
{
    /**
     * @event DefineFieldDefinitionEvent Fired for each custom field, so its kind can be
     *        adjusted or the field hidden entirely.
     */
    public const EVENT_DEFINE_FIELD_DEFINITION = 'defineFieldDefinition';

    /**
     * Field class => value kind. Checked in order, and only after the interface tests below
     * have had a go, so a subclass of a relation field is a relation without being listed.
     */
    private const CLASS_KINDS = [
        'craft\\fields\\PlainText' => FieldDefinition::KIND_TEXT,
        'craft\\fields\\Email' => FieldDefinition::KIND_TEXT,
        'craft\\fields\\Url' => FieldDefinition::KIND_TEXT,
        'craft\\fields\\Link' => FieldDefinition::KIND_TEXT,
        'craft\\fields\\Color' => FieldDefinition::KIND_TEXT,
        'craft\\fields\\Table' => FieldDefinition::KIND_TEXT,
        'craft\\fields\\Json' => FieldDefinition::KIND_TEXT,
        'craft\\fields\\Number' => FieldDefinition::KIND_NUMBER,
        'craft\\fields\\Range' => FieldDefinition::KIND_NUMBER,
        'craft\\fields\\Money' => FieldDefinition::KIND_MONEY,
        'craft\\fields\\Date' => FieldDefinition::KIND_DATE,
        'craft\\fields\\Time' => FieldDefinition::KIND_DATE,
        'craft\\fields\\Lightswitch' => FieldDefinition::KIND_BOOLEAN,
        'craft\\fields\\Matrix' => FieldDefinition::KIND_NESTED,
        'craft\\ckeditor\\Field' => FieldDefinition::KIND_TEXT,
        'craft\\redactor\\Field' => FieldDefinition::KIND_TEXT,
        'verbb\\hyper\\fields\\HyperField' => FieldDefinition::KIND_TEXT,
        'verbb\\supertable\\fields\\SuperTableField' => FieldDefinition::KIND_NESTED,
    ];

    /**
     * Field classes with no value of their own to filter — containers and placeholders.
     */
    private const IGNORED_CLASSES = [
        'craft\\fields\\ContentBlock',
        'craft\\fields\\MissingField',
    ];

    /** @var array<string,FieldDefinition[]> */
    private array $_cache = [];

    /**
     * Every filterable attribute and field for a type and source, keyed by handle.
     *
     * @return FieldDefinition[]
     */
    public function definitions(BaseType $type, ?string $source = null): array
    {
        $cacheKey = $type->key() . '|' . ($source ?? '*');
        if (isset($this->_cache[$cacheKey])) {
            return $this->_cache[$cacheKey];
        }

        $definitions = [];

        foreach ($this->commonAttributes($type) as $definition) {
            $definitions[$definition->handle] = $definition;
        }

        foreach ($type->nativeAttributes() as $definition) {
            $definitions[$definition->handle] = $definition;
        }

        foreach ($this->customFields($type, $source) as $definition) {
            // A custom field never shadows a native attribute — filtering `title` must mean
            // the element's title, whatever somebody named a field.
            if (!isset($definitions[$definition->handle])) {
                $definitions[$definition->handle] = $definition;
            }
        }

        return $this->_cache[$cacheKey] = $definitions;
    }

    public function definition(BaseType $type, ?string $source, string $handle): ?FieldDefinition
    {
        return $this->definitions($type, $source)[$handle] ?? null;
    }

    /**
     * The definitions grouped by heading, for the field menu.
     *
     * @return array<string,FieldDefinition[]>
     */
    public function grouped(BaseType $type, ?string $source = null): array
    {
        $groups = [];

        foreach ($this->definitions($type, $source) as $definition) {
            $groups[$definition->group][] = $definition;
        }

        return $groups;
    }

    /**
     * Resolves a relation criterion's stored IDs back into elements, so the value input can
     * show what was picked rather than a row of numbers.
     *
     * @return \craft\base\ElementInterface[]
     */
    public function elementsForValue(?FieldDefinition $definition, mixed $value): array
    {
        if (!$definition || $definition->kind !== FieldDefinition::KIND_RELATION) {
            return [];
        }

        $ids = array_values(array_filter(array_map(
            'intval',
            is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]),
        )));

        if (!$ids) {
            return [];
        }

        /** @var class-string<ElementInterface>|null $class */
        $class = $definition->relationElementType;

        if (!$class || !class_exists($class)) {
            return [];
        }

        return $class::find()
            ->id($ids)
            ->status(null)
            ->limit(null)
            ->all();
    }

    /**
     * The attributes every element has, regardless of type.
     *
     * @return FieldDefinition[]
     */
    private function commonAttributes(BaseType $type): array
    {
        /** @var class-string<ElementInterface> $class */
        $class = $type->elementType();

        $definitions = [
            new FieldDefinition([
                'handle' => 'id',
                'label' => Craft::t('app', 'ID'),
                'kind' => FieldDefinition::KIND_NUMBER,
                'group' => 'Attributes',
                'native' => true,
                'sortable' => true,
            ]),
        ];

        if ($class::hasTitles()) {
            $definitions[] = new FieldDefinition([
                'handle' => 'title',
                'label' => Craft::t('app', 'Title'),
                'kind' => FieldDefinition::KIND_TEXT,
                'group' => 'Attributes',
                'native' => true,
                'sortable' => true,
            ]);
        }

        $statusOptions = $type->statusOptions();
        if ($statusOptions) {
            $definitions[] = new FieldDefinition([
                'handle' => 'status',
                'label' => Craft::t('app', 'Status'),
                'kind' => FieldDefinition::KIND_STATUS,
                'group' => 'Attributes',
                'native' => true,
                'options' => $statusOptions,
            ]);
        }

        foreach (['dateCreated' => Craft::t('app', 'Date Created'), 'dateUpdated' => Craft::t('app', 'Date Updated')] as $handle => $label) {
            $definitions[] = new FieldDefinition([
                'handle' => $handle,
                'label' => $label,
                'kind' => FieldDefinition::KIND_DATE,
                'group' => 'Attributes',
                'native' => true,
                'sortable' => true,
            ]);
        }

        return $definitions;
    }

    /**
     * Custom fields drawn from the field layouts the source covers.
     *
     * A source can span several layouts — a section with three entry types. A field present
     * in only some of them still gets offered, flagged `partial`, because "filter the whole
     * section by a field two of its three types have" is a reasonable thing to want as long
     * as you're told the third type can never match.
     *
     * @return FieldDefinition[]
     */
    private function customFields(BaseType $type, ?string $source): array
    {
        $layouts = array_filter($type->fieldLayouts($source));
        $layoutCount = count($layouts);

        /** @var array<string,FieldDefinition> $definitions */
        $definitions = [];
        /** @var array<string,int> $appearances */
        $appearances = [];

        foreach ($layouts as $layout) {
            /** @var FieldLayout $layout */
            foreach ($layout->getCustomFields() as $field) {
                $handle = $field->handle;
                if ($handle === null || $handle === '') {
                    continue;
                }

                $appearances[$handle] = ($appearances[$handle] ?? 0) + 1;

                if (isset($definitions[$handle])) {
                    continue;
                }

                $definition = $this->defineField($field);
                if ($definition !== null) {
                    $definitions[$handle] = $definition;
                }
            }
        }

        foreach ($definitions as $handle => $definition) {
            $definition->partial = $layoutCount > 1 && ($appearances[$handle] ?? 0) < $layoutCount;
        }

        uasort($definitions, fn(FieldDefinition $a, FieldDefinition $b) => strcasecmp($a->label, $b->label));

        return array_values($definitions);
    }

    /**
     * Works out what shape a field's value is. Returns null for fields that have no value
     * worth filtering.
     */
    public function defineField(FieldInterface $field): ?FieldDefinition
    {
        $class = get_class($field);

        if (in_array($class, self::IGNORED_CLASSES, true)) {
            return null;
        }

        $kind = $this->kindFor($field);

        $definition = $kind === null ? null : new FieldDefinition([
            'handle' => (string)$field->handle,
            'label' => (string)($field->name ?: $field->handle),
            'kind' => $kind,
            'group' => 'Fields',
            'native' => false,
            'field' => $field,
            'fieldUid' => $field->uid,
            'options' => $this->optionsFor($field, $kind),
            'relationElementType' => $this->relationElementType($field),
        ]);

        $event = new DefineFieldDefinitionEvent([
            'field' => $field,
            'definition' => $definition,
        ]);
        $this->trigger(self::EVENT_DEFINE_FIELD_DEFINITION, $event);

        return $event->definition;
    }

    /**
     * Interface tests first, then the class map, then the `additionalFieldTypes` setting —
     * which also accepts CP Filters' own format, so a `cpfilters.php` config carries over.
     */
    private function kindFor(FieldInterface $field): ?string
    {
        if ($field instanceof BaseRelationField) {
            return FieldDefinition::KIND_RELATION;
        }

        if ($field instanceof BaseOptionsField) {
            return $this->isMultiOptions($field)
                ? FieldDefinition::KIND_MULTI_OPTIONS
                : FieldDefinition::KIND_OPTIONS;
        }

        $class = get_class($field);

        if (isset(self::CLASS_KINDS[$class])) {
            return self::CLASS_KINDS[$class];
        }

        foreach (self::CLASS_KINDS as $mapped => $kind) {
            if (is_a($field, $mapped, false)) {
                return $kind;
            }
        }

        $additional = Plugin::getInstance()->getSettings()->additionalFieldTypes;
        if (isset($additional[$class])) {
            $configured = $additional[$class];
            // CP Filters listed operator labels here rather than a kind; anything that isn't
            // one of our kinds is treated as "it's text, give it the text operators", which
            // is what those lists always amounted to.
            return is_string($configured) && $this->isKind($configured)
                ? $configured
                : FieldDefinition::KIND_TEXT;
        }

        // An unrecognised field still has a value in the content column. Text operators are
        // the honest default: they work on whatever JSON is in there, and nothing is silently
        // dropped from the UI the way CP Filters dropped it.
        return $field::dbType() !== null ? FieldDefinition::KIND_TEXT : null;
    }

    private function isKind(string $value): bool
    {
        return in_array($value, [
            FieldDefinition::KIND_TEXT,
            FieldDefinition::KIND_NUMBER,
            FieldDefinition::KIND_MONEY,
            FieldDefinition::KIND_DATE,
            FieldDefinition::KIND_BOOLEAN,
            FieldDefinition::KIND_OPTIONS,
            FieldDefinition::KIND_MULTI_OPTIONS,
            FieldDefinition::KIND_RELATION,
            FieldDefinition::KIND_NESTED,
        ], true);
    }

    /**
     * Craft marks multi-value option fields with a protected static `$multi`. Reading it by
     * reflection catches third-party option fields too, which a hard-coded list of
     * Checkboxes and MultiSelect wouldn't.
     */
    private function isMultiOptions(FieldInterface $field): bool
    {
        try {
            $reflection = new ReflectionClass($field);
            if ($reflection->hasProperty('multi')) {
                $property = $reflection->getProperty('multi');
                $property->setAccessible(true);
                return (bool)$property->getValue();
            }
        } catch (Throwable) {
        }

        return false;
    }

    /**
     * @return array<string,string>
     */
    private function optionsFor(FieldInterface $field, string $kind): array
    {
        if ($kind === FieldDefinition::KIND_BOOLEAN) {
            return [];
        }

        if (!in_array($kind, [FieldDefinition::KIND_OPTIONS, FieldDefinition::KIND_MULTI_OPTIONS], true)) {
            return [];
        }

        $options = [];
        foreach ((array)($field->options ?? []) as $option) {
            if (!is_array($option) || !isset($option['value'])) {
                continue;
            }
            if (!empty($option['optgroup'])) {
                continue;
            }
            $options[(string)$option['value']] = (string)($option['label'] ?? $option['value']);
        }

        return $options;
    }

    /**
     * Which element type a relation field points at. Craft keeps `elementType()` protected
     * on relation fields, so this asks the field to build an empty query and reads the class
     * off that — accurate for third-party relation fields as well as Craft's own.
     */
    private function relationElementType(FieldInterface $field): ?string
    {
        if (!$field instanceof BaseRelationField) {
            return null;
        }

        try {
            $reflection = new ReflectionClass($field);
            $method = $reflection->getMethod('elementType');
            $method->setAccessible(true);
            $class = $method->invoke(null);
            return is_string($class) && class_exists($class) ? $class : null;
        } catch (Throwable) {
            return null;
        }
    }
}
