<?php

namespace justinholtweb\cleanair\models;

/**
 * Where the Lite/Pro line falls.
 *
 * Pure and static, taking `$isPro` rather than reaching for the plugin, so the boundary is
 * testable without a Craft install and every gate reads the same way.
 */
abstract class Edition
{
    /** How many saved filters a Lite install may keep. */
    public const LITE_MAX_SAVED_FILTERS = 10;

    /** Element types Lite can filter. Everything else — Commerce, Addresses, third-party
     *  element types — is Pro. */
    public const LITE_TYPE_KEYS = ['entries', 'assets', 'categories', 'tags', 'users'];

    /** Export formats Lite can produce. */
    public const LITE_FORMATS = ['csv'];

    public static function maxSavedFilters(bool $isPro): ?int
    {
        return $isPro ? null : self::LITE_MAX_SAVED_FILTERS;
    }

    public static function allowsTypeKey(bool $isPro, string $key): bool
    {
        return $isPro || in_array($key, self::LITE_TYPE_KEYS, true);
    }

    public static function allowsFormat(bool $isPro, string $format): bool
    {
        return $isPro || in_array($format, self::LITE_FORMATS, true);
    }

    /** Filters visible to other users. Lite filters are always private to their author. */
    public static function allowsSharing(bool $isPro): bool
    {
        return $isPro;
    }

    /** Choosing which columns appear in the table and the export. */
    public static function allowsColumnChooser(bool $isPro): bool
    {
        return $isPro;
    }

    /** `matchMode: any` — the OR pass, which costs one query per criterion. */
    public static function allowsAnyMatch(bool $isPro): bool
    {
        return $isPro;
    }

    /** Handing a big export to the queue instead of the request. */
    public static function allowsQueuedExports(bool $isPro): bool
    {
        return $isPro;
    }

    /** `craft.cleanair.*` on the front end, and the console commands. */
    public static function allowsProgrammaticAccess(bool $isPro): bool
    {
        return $isPro;
    }

    /** The Clean Air Filter field type. */
    public static function allowsFilterField(bool $isPro): bool
    {
        return $isPro;
    }
}
