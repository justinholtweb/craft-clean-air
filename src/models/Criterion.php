<?php

namespace justinholtweb\cleanair\models;

use craft\base\Model;

/**
 * One row of a filter: a field, an operator, and whatever the operator needs.
 *
 * `field` is a handle — either a native attribute of the element type (`title`, `postDate`,
 * `filename`) or a custom field handle. Which one it is gets resolved at query time against
 * the field layouts the chosen source actually uses, so a criterion that survives a content
 * model change but no longer resolves is reported rather than silently dropped.
 */
class Criterion extends Model
{
    /** Native attribute or custom field handle. */
    public ?string $field = null;

    /** An operator handle from the Operators service. */
    public ?string $operator = null;

    /** The operator's first (usually only) value. */
    public mixed $value = null;

    /** The second value, for two-value operators — `between`, and the unit of a relative date. */
    public mixed $value2 = null;

    /**
     * @var bool Whether text comparisons ignore case. Craft's own behaviour depends on the
     *           column collation; this makes it explicit.
     */
    public bool $caseInsensitive = true;

    public static function fromArray(array $config): self
    {
        return new self([
            'field' => isset($config['field']) ? (string)$config['field'] : null,
            'operator' => isset($config['operator']) ? (string)$config['operator'] : null,
            'value' => $config['value'] ?? null,
            'value2' => $config['value2'] ?? null,
            'caseInsensitive' => (bool)($config['caseInsensitive'] ?? true),
        ]);
    }

    public function isComplete(): bool
    {
        return $this->field !== null && $this->field !== '' && $this->operator !== null && $this->operator !== '';
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'field' => $this->field,
            'operator' => $this->operator,
            'value' => $this->value,
            'value2' => $this->value2,
            'caseInsensitive' => $this->caseInsensitive,
        ];
    }
}
