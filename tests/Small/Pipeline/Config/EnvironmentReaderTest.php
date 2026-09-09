<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Config;

use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(EnvironmentReader::class)]
#[Small]
final class EnvironmentReaderTest extends TestCase
{
    private const string COMPOSER_ALLOWED = 'true';

    #[Test]
    public function anAbsentOrEmptyVariableIsNull(): void
    {
        $env = new EnvironmentReader(['EMPTY' => '']);

        self::assertNull($env->string('EMPTY'));
        self::assertNull($env->string('ABSENT'));
        self::assertSame('x', new EnvironmentReader(['A' => 'x'])->string('A'));
    }

    #[Test]
    #[DataProvider('provideBoolSpellings')]
    public function boolAcceptsTheOneZeroTrueFalseSpellings(string $value, bool $default, bool $expected): void
    {
        self::assertSame($expected, new EnvironmentReader(['V' => $value])->bool('V', $default));
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function provideBoolSpellings(): iterable
    {
        yield '1 is true' => ['1', false, true];
        yield 'true is true' => [self::COMPOSER_ALLOWED, false, true];
        yield 'TRUE is true' => ['TRUE', false, true];
        yield '0 is false' => ['0', true, false];
        yield 'false is false' => ['false', true, false];
        yield 'garbage keeps the default' => ['yes', true, true];
    }

    #[Test]
    public function boolOrNullDistinguishesAbsentFromFalse(): void
    {
        self::assertNull(new EnvironmentReader([])->boolOrNull('V'));
        self::assertFalse(new EnvironmentReader(['V' => '0'])->boolOrNull('V'));
        self::assertTrue(new EnvironmentReader(['V' => self::COMPOSER_ALLOWED])->boolOrNull('V'));
        self::assertNull(new EnvironmentReader(['V' => 'maybe'])->boolOrNull('V'));
    }

    #[Test]
    public function intOnlyAcceptsDigits(): void
    {
        self::assertSame(8, new EnvironmentReader(['N' => '8'])->int('N', 4));
        self::assertSame(4, new EnvironmentReader(['N' => '8G'])->int('N', 4));
        self::assertSame(4, new EnvironmentReader([])->int('N', 4));
        self::assertNull(new EnvironmentReader(['N' => '-1'])->intOrNull('N'));
        self::assertSame(12, new EnvironmentReader(['N' => '12'])->intOrNull('N'));
    }

    #[Test]
    public function ciIsExplicitCiOrClaudeCodeOrNoTty(): void
    {
        self::assertTrue(new EnvironmentReader(['CI' => self::COMPOSER_ALLOWED])->isCi(true, true));
        self::assertTrue(new EnvironmentReader(['CLAUDECODE' => '1'])->isCi(true, true));
        self::assertTrue(new EnvironmentReader([])->isCi(false, true));
        self::assertTrue(new EnvironmentReader([])->isCi(true, false));
        self::assertFalse(new EnvironmentReader([])->isCi(true, true));
        self::assertFalse(new EnvironmentReader(['CI' => 'false'])->isCi(true, true));
    }

    #[Test]
    public function readOnlyIsExplicitQaReadonlyElseGithubActionsElseWritable(): void
    {
        self::assertTrue(new EnvironmentReader(['QA_READONLY' => '1'])->isReadOnly());
        self::assertFalse(new EnvironmentReader(['QA_READONLY' => '0', 'GITHUB_ACTIONS' => self::COMPOSER_ALLOWED])->isReadOnly());
        self::assertTrue(new EnvironmentReader(['GITHUB_ACTIONS' => self::COMPOSER_ALLOWED])->isReadOnly());
        self::assertFalse(new EnvironmentReader([])->isReadOnly());
    }

    #[Test]
    public function aggregateFollowsReadOnlyUnlessFailFastIsForced(): void
    {
        self::assertTrue(new EnvironmentReader([])->isAggregate(true));
        self::assertFalse(new EnvironmentReader(['QA_FAIL_FAST' => '1'])->isAggregate(true));
        self::assertFalse(new EnvironmentReader([])->isAggregate(false));
    }

    #[Test]
    public function fromProcessReadsTheRealEnvironment(): void
    {
        $env = EnvironmentReader::fromProcess();

        self::assertArrayHasKey('PATH', $env);
    }
}
