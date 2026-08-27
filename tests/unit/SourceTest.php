<?php

namespace justinholtweb\cleanair\tests\unit;

use justinholtweb\cleanair\models\Source;
use PHPUnit\Framework\TestCase;

/**
 * Source keys are `type:id`, and they end up in saved filters and in URLs — so parsing them
 * has to survive whatever arrives, including the keys CP Filters wrote.
 */
class SourceTest extends TestCase
{
    public function testMakeBuildsTheKey(): void
    {
        $source = Source::make('entryType', 12, 'News');

        self::assertSame('entryType:12', $source->key);
        self::assertSame(12, $source->id);
        self::assertSame('News', $source->label);
    }

    public function testParseSplitsTypeAndId(): void
    {
        self::assertSame(['entryType', 12], Source::parse('entryType:12'));
        self::assertSame(['volume', 3], Source::parse('volume:3'));
    }

    public function testParseTreatsEmptyAndWildcardAsNoSource(): void
    {
        foreach ([null, '', '*'] as $value) {
            self::assertSame(['', null], Source::parse($value), var_export($value, true));
        }
    }

    public function testParseHandlesAKeyWithNoId(): void
    {
        self::assertSame(['group', null], Source::parse('group:'));
        self::assertSame(['group', null], Source::parse('group'));
    }
}
