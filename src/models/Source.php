<?php

namespace justinholtweb\cleanair\models;

use craft\base\Model;

/**
 * A filterable subdivision of an element type — an entry type, a section, a volume, a
 * category group, a product type.
 *
 * The key is `type:id`, which is what ends up in saved filters and URLs. IDs rather than
 * UIDs, because that is what CP Filters stored and what makes the Craft 4 import land in the
 * right place without a lookup table.
 */
class Source extends Model
{
    public string $key = '';
    public string $type = '';
    public ?int $id = null;
    public ?string $uid = null;
    public string $label = '';

    /** Optional heading to group the option under in the source menu. */
    public ?string $group = null;

    /** Small suffix shown after the label — the section a nested entry type belongs to, say. */
    public ?string $hint = null;

    public static function make(string $type, ?int $id, string $label, ?string $uid = null, ?string $group = null, ?string $hint = null): self
    {
        return new self([
            'key' => $id !== null ? "$type:$id" : $type,
            'type' => $type,
            'id' => $id,
            'uid' => $uid,
            'label' => $label,
            'group' => $group,
            'hint' => $hint,
        ]);
    }

    /** @return array{0: string, 1: int|null} */
    public static function parse(?string $key): array
    {
        if ($key === null || $key === '' || $key === '*') {
            return ['', null];
        }
        $parts = explode(':', $key, 2);
        return [$parts[0], isset($parts[1]) && $parts[1] !== '' ? (int)$parts[1] : null];
    }
}
