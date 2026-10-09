<?php

namespace justinholtweb\cleanair\models;

use craft\base\Model;

/**
 * One row of a filter: a field, an operator, and whatever the operator needs — or, in Pro, a
 * group of such rows with its own `all`/`any`.
 *
 * Groups go one level deep. "In Products AND (no image OR no price)" is one filter rather than
 * two; a group inside a group is dropped when the filter is read, because nesting deeper buys
 * very little and makes a filter nobody can read back.
 *
 * `field` is a handle — either a native attribute of the element type (`title`, `postDate`,
 * `filename`) or a custom field handle. Which one it is gets resolved at query time against
 * the field layouts the chosen source actually uses, so a criterion that survives a content
 * model change but no longer resolves is reported rather than silently dropped.
 */
class Criterion extends Model
{
    public const TYPE_CONDITION = 'condition';
    public const TYPE_GROUP = 'group';

    /** `condition` or `group`. */
    public string $type = self::TYPE_CONDITION;

    /** For a group: `all` ANDs its conditions, `any` ORs them. */
    public string $matchMode = FilterSet::MATCH_ALL;

    /** @var Criterion[] For a group: its conditions. Never groups themselves. */
    public array $criteria = [];

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

    public static function fromArray(array $config, bool $allowGroup = true): self
    {
        if (($config['type'] ?? null) === self::TYPE_GROUP) {
            $children = [];

            // One level deep: a group's children are read as plain conditions, and a nested
            // group (which has no field) falls out as incomplete.
            foreach ((array)($config['criteria'] ?? []) as $row) {
                $child = self::fromArray((array)$row, false);
                if ($child->isComplete()) {
                    $children[] = $child;
                }
            }

            return new self([
                'type' => $allowGroup ? self::TYPE_GROUP : self::TYPE_CONDITION,
                'matchMode' => ($config['matchMode'] ?? FilterSet::MATCH_ALL) === FilterSet::MATCH_ANY
                    ? FilterSet::MATCH_ANY
                    : FilterSet::MATCH_ALL,
                'criteria' => $allowGroup ? $children : [],
            ]);
        }

        return new self([
            'field' => isset($config['field']) ? (string)$config['field'] : null,
            'operator' => isset($config['operator']) ? (string)$config['operator'] : null,
            'value' => $config['value'] ?? null,
            'value2' => $config['value2'] ?? null,
            'caseInsensitive' => (bool)($config['caseInsensitive'] ?? true),
        ]);
    }

    public function isGroup(): bool
    {
        return $this->type === self::TYPE_GROUP;
    }

    /** @return Criterion[] A group's conditions that can be applied. */
    public function completeCriteria(): array
    {
        return array_values(array_filter($this->criteria, fn(Criterion $c) => !$c->isGroup() && $c->isComplete()));
    }

    public function isComplete(): bool
    {
        if ($this->isGroup()) {
            return $this->completeCriteria() !== [];
        }

        return $this->field !== null && $this->field !== '' && $this->operator !== null && $this->operator !== '';
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        if ($this->isGroup()) {
            return [
                'type' => self::TYPE_GROUP,
                'matchMode' => $this->matchMode,
                'criteria' => array_map(fn(Criterion $c) => $c->toArray(), $this->criteria),
            ];
        }

        // No `type` key on a plain condition, so filters saved before groups existed and filters
        // saved after read back identically.
        return [
            'field' => $this->field,
            'operator' => $this->operator,
            'value' => $this->value,
            'value2' => $this->value2,
            'caseInsensitive' => $this->caseInsensitive,
        ];
    }
}
