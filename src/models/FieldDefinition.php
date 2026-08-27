<?php

namespace justinholtweb\cleanair\models;

use craft\base\FieldInterface;
use craft\base\Model;

/**
 * Something you can filter on: a native attribute of the element type, or a custom field
 * that appears in one of the field layouts the chosen source uses.
 *
 * `kind` is the whole trick. Clean Air doesn't ask "what class is this field?" at filter
 * time — it asks "what shape is its value?", and the operator list, the value input and the
 * column renderer all follow from that. Adding support for a new field type is a matter of
 * mapping it to a kind.
 */
class FieldDefinition extends Model
{
    public const KIND_TEXT = 'text';
    public const KIND_NUMBER = 'number';
    public const KIND_MONEY = 'money';
    public const KIND_DATE = 'date';
    public const KIND_BOOLEAN = 'boolean';
    public const KIND_OPTIONS = 'options';
    public const KIND_MULTI_OPTIONS = 'multiOptions';
    public const KIND_RELATION = 'relation';
    public const KIND_STATUS = 'status';
    public const KIND_NESTED = 'nested';

    /** The handle used in criteria and in the query. */
    public string $handle = '';

    public string $label = '';

    /** One of the KIND_* constants. */
    public string $kind = self::KIND_TEXT;

    /** Heading the field sits under in the picker — "Attributes", "Fields", "Commerce". */
    public string $group = 'Fields';

    /** Whether this is an element-query attribute rather than a custom field. */
    public bool $native = false;

    /**
     * @var string|null The element query parameter to set, when it differs from the handle
     *                  (`authorId` filters on the `authorId` param; `section` on `sectionId`).
     *                  Native attributes without a query param are applied as raw conditions.
     */
    public ?string $param = null;

    /**
     * @var string|null A `[table.]column` expression, used when a native attribute has no
     *                  query parameter of its own and has to be filtered in SQL.
     */
    public ?string $column = null;

    /** @var array<string,string> value => label, for the option kinds. */
    public array $options = [];

    /** @var string|null The element class a relation field points at. */
    public ?string $relationElementType = null;

    /** @var FieldInterface|null The Craft field, when this is a custom field. */
    public ?FieldInterface $field = null;

    /** @var string|null The custom field's UID. */
    public ?string $fieldUid = null;

    /** Whether results can be sorted by this. */
    public bool $sortable = false;

    /** Whether it can be shown as a results/export column. */
    public bool $exportable = true;

    /**
     * @var bool Whether the field is only present in *some* of the layouts the current source
     *           covers. Filtering on it silently excludes everything that doesn't have it, so
     *           the UI says so.
     */
    public bool $partial = false;

    public function isCustomField(): bool
    {
        return !$this->native;
    }
}
