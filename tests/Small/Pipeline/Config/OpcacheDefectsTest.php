<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Config;

use LTS\PHPQA\Pipeline\Config\Dto\OpcacheSettingsDto;
use LTS\PHPQA\Pipeline\Config\OpcacheDefects;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(OpcacheDefects::class)]
#[UsesClass(OpcacheSettingsDto::class)]
#[Small]
final class OpcacheDefectsTest extends TestCase
{
    /** The version the crash was captured on, and the one the advisory tests assert against. */
    private const string AFFECTED_VERSION = '8.5.10';

    /** PHP's own default, as `php -n -r 'echo ini_get("opcache.optimization_level");'` reports it. */
    private const string DEFAULT_LEVEL = '0x7FFEBFFF';

    /** The default with only bit 0x20 cleared. */
    private const string RECOMMENDED_LEVEL = '0x7FFEBFDF';

    /** A host that has turned other passes off itself, used to check the advice is host-relative. */
    private const string CUSTOM_LEVEL = '0x00000FFF';

    #[Test]
    #[DataProvider('provideVersions')]
    public function theAffectedRangeStartsAtTheFirstAffectedMinorAndIsOpenEnded(string $version, bool $affected): void
    {
        self::assertSame($affected, OpcacheDefects::affects($version));
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function provideVersions(): iterable
    {
        yield 'first affected minor' => ['8.5.0', true];

        yield 'the version the crash was taken on' => [self::AFFECTED_VERSION, true];

        yield 'a later patch release with no fix known' => ['8.5.99', true];

        yield 'the previous minor' => ['8.4.22', false];

        yield 'a dev build of the affected minor' => ['8.5.11-dev', true];
    }

    #[Test]
    #[DataProvider('provideIniLevels')]
    public function theIniValueIsParsedInEverySpellingPhpAccepts(string $ini, int $level): void
    {
        self::assertSame($level, OpcacheDefects::parseLevel($ini));
    }

    /** @return iterable<string, array{0: string, 1: int}> */
    public static function provideIniLevels(): iterable
    {
        yield 'hex, the documented spelling' => [self::DEFAULT_LEVEL, 0x7FFEBFFF];

        yield 'hex lower case' => ['0x7ffebfdf', 0x7FFEBFDF];

        yield 'decimal' => ['2147401727', 0x7FFEBFFF];

        yield 'unset, which PHP treats as the default' => ['', 0x7FFEBFFF];

        yield 'optimizer off' => ['0', 0];
    }

    #[Test]
    public function theDfaPassIsBitTwentyOfTheMask(): void
    {
        self::assertTrue(OpcacheDefects::dfaPassEnabled(OpcacheDefects::parseLevel(self::DEFAULT_LEVEL)));
        self::assertFalse(OpcacheDefects::dfaPassEnabled(OpcacheDefects::parseLevel(self::RECOMMENDED_LEVEL)));
        self::assertFalse(OpcacheDefects::dfaPassEnabled(0));
        // The recommendation must be the default mask with only that bit cleared:
        // clearing more would turn off passes that are not implicated.
        self::assertSame(OpcacheDefects::RECOMMENDED_LEVEL, OpcacheDefects::parseLevel(self::RECOMMENDED_LEVEL));
    }

    #[Test]
    public function theDefaultMaskIsPhpsOwnAndNotEveryBitSet(): void
    {
        // PHP ships these two passes off. A mask built by setting every bit would
        // enable optimisations no production host runs, so the lane would report
        // bytecode nothing ever compiles. The bits are asserted before the equality,
        // because asserting equality first narrows the value and makes them tautological.
        $default = OpcacheDefects::parseLevel(self::DEFAULT_LEVEL);

        self::assertSame(0, $default & 0x4000);
        self::assertSame(0, $default & 0x10000);
        self::assertSame(OpcacheDefects::DEFAULT_LEVEL, $default);
    }

    #[Test]
    public function theAdviceClearsTheDfaBitFromTheHostsOwnMaskNotFromTheDefault(): void
    {
        $custom = OpcacheDefects::parseLevel(self::CUSTOM_LEVEL);

        self::assertSame(0x00000FDF, OpcacheDefects::recommendedFor($custom));
        // Only the one bit moves: a host that turned other passes off keeps them off.
        self::assertSame(OpcacheDefects::DFA_PASS, $custom ^ OpcacheDefects::recommendedFor($custom));
        self::assertSame(OpcacheDefects::RECOMMENDED_LEVEL, OpcacheDefects::recommendedFor(OpcacheDefects::DEFAULT_LEVEL));
    }

    #[Test]
    public function theAdvisoryQuotesTheHostsMaskAndAdvisesThatMaskMinusTheDfaBit(): void
    {
        $text = implode("\n", OpcacheDefects::advisory(self::AFFECTED_VERSION, new OpcacheSettingsDto(true, self::CUSTOM_LEVEL)));

        self::assertStringContainsString('opcache.optimization_level=0xFFF)', $text);
        self::assertStringContainsString('opcache.optimization_level=0xFDF', $text);
        self::assertStringNotContainsString(self::RECOMMENDED_LEVEL, $text);
    }

    #[Test]
    public function anAffectedPhpWithTheDfaPassOnGetsTheAdvisoryWithTheIniLine(): void
    {
        $lines = OpcacheDefects::advisory(self::AFFECTED_VERSION, new OpcacheSettingsDto(true, self::DEFAULT_LEVEL));

        self::assertNotSame([], $lines);
        $text = implode("\n", $lines);
        self::assertStringContainsString('WARNING', $text);
        self::assertStringContainsString(self::AFFECTED_VERSION, $text);
        self::assertStringContainsString('opcache.optimization_level=' . self::RECOMMENDED_LEVEL, $text);
        self::assertStringContainsString('every SAPI', $text);
        self::assertStringContainsString(OpcacheDefects::IDENTIFIER, $text);
    }

    #[Test]
    public function noAdvisoryWhenThePassIsOffOrOpcacheIsAbsentOrThePhpIsUnaffected(): void
    {
        self::assertSame([], OpcacheDefects::advisory(self::AFFECTED_VERSION, new OpcacheSettingsDto(true, self::RECOMMENDED_LEVEL)));
        self::assertSame([], OpcacheDefects::advisory(self::AFFECTED_VERSION, new OpcacheSettingsDto(false, self::DEFAULT_LEVEL)));
        self::assertSame([], OpcacheDefects::advisory('8.4.22', new OpcacheSettingsDto(true, self::DEFAULT_LEVEL)));
    }
}
