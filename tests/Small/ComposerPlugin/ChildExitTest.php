<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\ComposerPlugin;

use LTS\PHPQA\ComposerPlugin\ChildExit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A child killed by a signal prints nothing about it, so a failure line that names only "the
 * output above" points a reader at output that does not exist. The exit code is the one record
 * of how the child ended, and a shell reports a signal kill as 128 + the signal's number.
 *
 * @internal
 */
#[CoversClass(ChildExit::class)]
#[Small]
final class ChildExitTest extends TestCase
{
    #[Test]
    #[DataProvider('exits')]
    public function anExitIsDescribedByItsCodeAndAnySignal(int $exitCode, string $description): void
    {
        self::assertSame($description, ChildExit::describe($exitCode));
    }

    /** @return iterable<string, array{int, string}> */
    public static function exits(): iterable
    {
        yield 'an ordinary failure' => [1, 'exit code 1'];
        yield '128, the last code that is not a signal' => [128, 'exit code 128'];
        yield 'a PHP fatal error, 255, which is no signal' => [255, 'exit code 255'];
        yield '193, past the highest signal' => [193, 'exit code 193'];
        yield 'SIGHUP' => [129, 'exit code 129, killed by signal 1 (SIGHUP)'];
        yield 'SIGINT' => [130, 'exit code 130, killed by signal 2 (SIGINT)'];
        yield 'SIGQUIT' => [131, 'exit code 131, killed by signal 3 (SIGQUIT)'];
        yield 'SIGILL' => [132, 'exit code 132, killed by signal 4 (SIGILL)'];
        yield 'SIGTRAP' => [133, 'exit code 133, killed by signal 5 (SIGTRAP)'];
        yield 'SIGABRT' => [134, 'exit code 134, killed by signal 6 (SIGABRT)'];
        yield 'signal 7, numbered differently per platform' => [135, 'exit code 135, killed by signal 7'];
        yield 'SIGFPE' => [136, 'exit code 136, killed by signal 8 (SIGFPE)'];
        yield 'SIGKILL, an out-of-memory kill' => [137, 'exit code 137, killed by signal 9 (SIGKILL, which is what the out-of-memory killer sends)'];
        yield 'SIGSEGV, a crash in a program that is not php-qa-ci\'s' => [139, 'exit code 139, killed by signal 11 (SIGSEGV): it crashed with a segmentation fault, a defect to debug and report where the crashing code lives, never to retry past (see ' . self::segfaultGuide() . ')'];
        yield 'SIGPIPE' => [141, 'exit code 141, killed by signal 13 (SIGPIPE)'];
        yield 'SIGALRM' => [142, 'exit code 142, killed by signal 14 (SIGALRM)'];
        yield 'SIGTERM' => [143, 'exit code 143, killed by signal 15 (SIGTERM)'];
        yield 'the highest signal' => [192, 'exit code 192, killed by signal 64'];
    }

    /**
     * A segfault is reported where the crashing code lives: php-qa-ci's tracker is the place only
     * when the program that crashed is one of php-qa-ci's own helpers.
     */
    #[Test]
    #[DataProvider('helperExits')]
    public function aPhpQaCiHelperSegfaultNamesPhpQaCisTracker(int $exitCode, string $description): void
    {
        self::assertSame($description, ChildExit::describePhpQaCiHelper($exitCode));
    }

    /** @return iterable<string, array{int, string}> */
    public static function helperExits(): iterable
    {
        yield 'an ordinary failure' => [1, 'exit code 1'];
        yield 'SIGTERM' => [143, 'exit code 143, killed by signal 15 (SIGTERM)'];
        yield 'SIGSEGV, a crash in php-qa-ci\'s own helper' => [139, 'exit code 139, killed by signal 11 (SIGSEGV): it crashed with a segmentation fault, a defect to report at https://github.com/LongTermSupport/php-qa-ci/issues and never to retry past'];
    }

    /** The guide a segfault in any other program points at is a page that ships. */
    #[Test]
    public function theSegfaultGuideIsAShippedPage(): void
    {
        self::assertFileExists(self::segfaultGuide());
        self::assertStringContainsString(self::segfaultGuide(), ChildExit::describe(139));
    }

    #[Test]
    #[DataProvider('signals')]
    public function onlyACodeAbove128ForARealSignalIsASignalKill(int $exitCode, bool $killed): void
    {
        self::assertSame($killed, ChildExit::killedBySignal($exitCode));
    }

    /** @return iterable<string, array{int, bool}> */
    public static function signals(): iterable
    {
        yield '128' => [128, false];
        yield '129' => [129, true];
        yield '192' => [192, true];
        yield '193' => [193, false];
        yield '255' => [255, false];
    }

    /** The consumer-facing segfault page, in the installed package, wherever that is. */
    private static function segfaultGuide(): string
    {
        return \dirname(__DIR__, 3) . '/docs/segfaults.md';
    }
}
