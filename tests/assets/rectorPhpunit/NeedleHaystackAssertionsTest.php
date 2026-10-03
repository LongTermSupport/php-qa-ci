<?php

declare(strict_types=1);

namespace RectorPhpunitFixture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Correct needle/haystack assertions whose haystack is the constant. Every call
 * here is already right, so the shipped PHPUnit Rector configuration must leave
 * this file byte-for-byte unchanged; any rewrite swaps needle and haystack.
 *
 * @internal
 */
#[CoversNothing]
final class NeedleHaystackAssertionsTest extends TestCase
{
    private const string HAYSTACK = 'the quick brown fox';

    public function testHaystackIsAClassConstant(): void
    {
        $needle = 'quick';
        $absent = 'slow';
        $prefix = 'the';
        $suffix = 'fox';

        self::assertStringContainsString($needle, self::HAYSTACK);
        self::assertStringContainsStringIgnoringCase($needle, self::HAYSTACK);
        self::assertStringNotContainsString($absent, self::HAYSTACK);
        self::assertStringNotContainsStringIgnoringCase($absent, self::HAYSTACK);
        self::assertStringStartsWith($prefix, self::HAYSTACK);
        self::assertStringStartsNotWith($absent, self::HAYSTACK);
        self::assertStringEndsWith($suffix, self::HAYSTACK);
        self::assertStringEndsNotWith($absent, self::HAYSTACK);
    }

    public function testHaystackIsAStringLiteral(): void
    {
        $needle = 'quick';
        $absent = 'slow';
        $prefix = 'the';
        $suffix = 'fox';

        self::assertStringContainsString($needle, 'the quick brown fox');
        self::assertStringContainsStringIgnoringCase($needle, 'the quick brown fox');
        self::assertStringNotContainsString($absent, 'the quick brown fox');
        self::assertStringNotContainsStringIgnoringCase($absent, 'the quick brown fox');
        self::assertStringStartsWith($prefix, 'the quick brown fox');
        self::assertStringStartsNotWith($absent, 'the quick brown fox');
        self::assertStringEndsWith($suffix, 'the quick brown fox');
        self::assertStringEndsNotWith($absent, 'the quick brown fox');
    }
}
