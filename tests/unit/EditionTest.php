<?php

namespace justinholtweb\cleanair\tests\unit;

use justinholtweb\cleanair\models\Edition;
use PHPUnit\Framework\TestCase;

/**
 * Where the Lite/Pro line falls.
 *
 * Edition takes `$isPro` rather than reaching for the plugin, which is what makes the whole
 * boundary testable without a Craft install — and means every gate in the plugin is asking
 * the same question in the same place.
 */
class EditionTest extends TestCase
{
    public function testLiteCoversTheEverydayElementTypes(): void
    {
        foreach (['entries', 'assets', 'categories', 'tags', 'users'] as $key) {
            self::assertTrue(Edition::allowsTypeKey(false, $key), $key);
        }
    }

    public function testCommerceAndCustomElementTypesArePro(): void
    {
        foreach (['orders', 'products', 'variants', 'addresses', 'my-plugin-widget'] as $key) {
            self::assertFalse(Edition::allowsTypeKey(false, $key), $key);
            self::assertTrue(Edition::allowsTypeKey(true, $key), $key);
        }
    }

    public function testLiteExportsCsvOnly(): void
    {
        self::assertTrue(Edition::allowsFormat(false, 'csv'));

        foreach (['tsv', 'json', 'xml'] as $format) {
            self::assertFalse(Edition::allowsFormat(false, $format), $format);
            self::assertTrue(Edition::allowsFormat(true, $format), $format);
        }
    }

    public function testLiteCapsSavedFiltersAndProDoesNot(): void
    {
        self::assertSame(10, Edition::maxSavedFilters(false));
        self::assertNull(Edition::maxSavedFilters(true));
    }

    public function testTheProOnlyFeaturesAreProOnly(): void
    {
        foreach ([
            'sharing' => [Edition::allowsSharing(false), Edition::allowsSharing(true)],
            'column chooser' => [Edition::allowsColumnChooser(false), Edition::allowsColumnChooser(true)],
            'match any' => [Edition::allowsAnyMatch(false), Edition::allowsAnyMatch(true)],
            'queued exports' => [Edition::allowsQueuedExports(false), Edition::allowsQueuedExports(true)],
            'programmatic access' => [Edition::allowsProgrammaticAccess(false), Edition::allowsProgrammaticAccess(true)],
            'filter field' => [Edition::allowsFilterField(false), Edition::allowsFilterField(true)],
        ] as $feature => [$lite, $pro]) {
            self::assertFalse($lite, "$feature should be Pro only");
            self::assertTrue($pro, "$feature should be available in Pro");
        }
    }
}
