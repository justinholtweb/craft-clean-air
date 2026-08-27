<?php

namespace justinholtweb\cleanair\types;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\elements\User;
use craft\models\FieldLayout;
use justinholtweb\cleanair\models\FieldDefinition;
use justinholtweb\cleanair\models\Source;
use yii\base\BaseObject;

/**
 * What Clean Air needs to know about one element type.
 *
 * Everything type-specific lives behind this: which sources exist, which field layouts a
 * source uses, which native attributes are worth filtering on, and how to narrow a query to
 * a source. The filter builder, the query builder, the exporter and the importer all talk to
 * this interface and never to Entry or Asset directly, which is what lets a third-party
 * element type join in with one small class.
 */
abstract class BaseType extends BaseObject
{
    /** URL-safe key: `entries`, `assets`, `commerce-orders`. Also what CP Filters stored. */
    abstract public function key(): string;

    /** @return class-string<ElementInterface> */
    abstract public function elementType(): string;

    public function label(): string
    {
        /** @var class-string<ElementInterface> $class */
        $class = $this->elementType();
        return $class::pluralDisplayName();
    }

    /** Whether the element type exists on this install at all. */
    public function isAvailable(): bool
    {
        return class_exists($this->elementType());
    }

    /**
     * Whether a source has to be picked before fields can be listed. Entries and assets do —
     * their fields depend on it. Users and orders have one field layout, so they don't.
     */
    public function requiresSource(): bool
    {
        return true;
    }

    /** @return Source[] */
    public function sources(): array
    {
        return [];
    }

    /**
     * The field layouts a source covers. More than one when the source is a section spanning
     * several entry types, which is why a field that only exists in some of them gets flagged
     * as partial rather than quietly excluding half the section.
     *
     * @return FieldLayout[]
     */
    public function fieldLayouts(?string $sourceKey): array
    {
        $layout = Craft::$app->getFields()->getLayoutByType($this->elementType());
        return $layout->id || !empty($layout->getCustomFields()) ? [$layout] : [];
    }

    /** Narrows a query to a source. */
    public function applySource(ElementQuery $query, ?string $sourceKey): void
    {
    }

    /**
     * Native attributes worth filtering on, beyond the common ones every element has.
     *
     * @return FieldDefinition[]
     */
    public function nativeAttributes(): array
    {
        return [];
    }

    /** @return string[] Column keys shown when the user hasn't chosen any. */
    public function defaultColumns(): array
    {
        return ['id', 'title', 'status', 'dateUpdated'];
    }

    /**
     * Whether the current user may filter this element type at all. Clean Air never widens
     * anybody's access: if Craft wouldn't show them the elements, Clean Air won't either.
     */
    public function canView(?User $user): bool
    {
        return true;
    }

    /** Whether the current user may see a particular source. */
    public function canViewSource(?User $user, Source $source): bool
    {
        return $this->canView($user);
    }

    /** Statuses this element type supports, as value => label. */
    public function statusOptions(): array
    {
        /** @var class-string<ElementInterface> $class */
        $class = $this->elementType();
        if (!$class::hasStatuses()) {
            return [];
        }

        $options = [];
        foreach ($class::statuses() as $status => $config) {
            $label = is_array($config) ? ($config['label'] ?? $status) : $config;
            $options[$status] = (string)$label;
        }
        return $options;
    }

    // Small builders, so each concrete type reads as a list of attributes rather than a wall
    // of array literals.
    // =========================================================================

    protected function text(string $handle, string $label, ?string $column = null, bool $sortable = true): FieldDefinition
    {
        return new FieldDefinition([
            'handle' => $handle,
            'label' => $label,
            'kind' => FieldDefinition::KIND_TEXT,
            'group' => 'Attributes',
            'native' => true,
            'column' => $column,
            'sortable' => $sortable,
        ]);
    }

    protected function number(string $handle, string $label, ?string $column = null): FieldDefinition
    {
        return new FieldDefinition([
            'handle' => $handle,
            'label' => $label,
            'kind' => FieldDefinition::KIND_NUMBER,
            'group' => 'Attributes',
            'native' => true,
            'column' => $column,
            'sortable' => true,
        ]);
    }

    protected function money(string $handle, string $label, ?string $column = null): FieldDefinition
    {
        return new FieldDefinition([
            'handle' => $handle,
            'label' => $label,
            'kind' => FieldDefinition::KIND_MONEY,
            'group' => 'Attributes',
            'native' => true,
            'column' => $column,
            'sortable' => true,
        ]);
    }

    protected function date(string $handle, string $label, ?string $column = null): FieldDefinition
    {
        return new FieldDefinition([
            'handle' => $handle,
            'label' => $label,
            'kind' => FieldDefinition::KIND_DATE,
            'group' => 'Attributes',
            'native' => true,
            'column' => $column,
            'sortable' => true,
        ]);
    }

    protected function boolean(string $handle, string $label, ?string $column = null): FieldDefinition
    {
        return new FieldDefinition([
            'handle' => $handle,
            'label' => $label,
            'kind' => FieldDefinition::KIND_BOOLEAN,
            'group' => 'Attributes',
            'native' => true,
            'column' => $column,
        ]);
    }

    protected function options(string $handle, string $label, array $options, ?string $column = null): FieldDefinition
    {
        return new FieldDefinition([
            'handle' => $handle,
            'label' => $label,
            'kind' => FieldDefinition::KIND_OPTIONS,
            'group' => 'Attributes',
            'native' => true,
            'options' => $options,
            'column' => $column,
        ]);
    }

    protected function relation(string $handle, string $label, string $relationElementType): FieldDefinition
    {
        return new FieldDefinition([
            'handle' => $handle,
            'label' => $label,
            'kind' => FieldDefinition::KIND_RELATION,
            'group' => 'Attributes',
            'native' => true,
            'relationElementType' => $relationElementType,
        ]);
    }
}
