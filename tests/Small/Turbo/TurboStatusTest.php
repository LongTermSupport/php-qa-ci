<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Turbo;

use LTS\PHPQA\Turbo\TurboStateEnum;
use LTS\PHPQA\Turbo\TurboStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Whether PHPStan ran with Turbo, read from what `phpstan.phar diagnose` prints. PHPStan falls
 * back to plain PHP without a word when its binary is missing or stale, so the lane reports the
 * state on every run, and names the case php-qa-ci could have prevented: a host it ships a build
 * for, where the build is not running.
 *
 * @internal
 */
#[CoversClass(TurboStatus::class)]
#[CoversClass(TurboStateEnum::class)]
#[Small]
final class TurboStatusTest extends TestCase
{
    private const string ENABLED = "Turbo extension: enabled (version 6351afb)\nTurbo platform: linux-gnu-x86_64 (os: Linux, machine: x86_64, libc: gnu, php: 8.5, zts: no, debug: no)\nTurbo worker binary: /p/vendor-phar/turbo-ext/linux-gnu-x86_64/phpstan_turbo-8.5.so (loaded via process restart)\nTurbo trusted types: on (PHPStan's own argument and return type checks are dropped; --debug keeps them)\n";

    private const string NOT_LOADED = "Turbo extension: not loaded\nTurbo platform: linux-gnu-x86_64 (os: Linux, machine: x86_64, libc: gnu, php: 8.5, zts: no, debug: no)\nTurbo worker binary: none found\nTurbo trusted types: off (extension inactive)\n";

    private const string STALE = "Turbo extension: inactive (extension version abc1234, expected 6351afb)\nTurbo platform: linux-gnu-x86_64 (os: Linux, machine: x86_64, libc: gnu, php: 8.5, zts: no, debug: no)\nTurbo worker binary: none found\nTurbo trusted types: off (extension inactive)\n";

    private const string PHPSTAN_HEADER = "PHP runtime version: 8.5.11\nPHPStan version: 2.3.0\n\n";

    #[Test]
    #[DataProvider('states')]
    public function itReadsTheStateFromDiagnose(string $diagnose, bool $shippedForHost, TurboStateEnum $expected): void
    {
        self::assertSame($expected, TurboStatus::fromDiagnose(self::PHPSTAN_HEADER . $diagnose, $shippedForHost)->state);
    }

    /** @return iterable<string, array{string, bool, TurboStateEnum}> */
    public static function states(): iterable
    {
        yield 'enabled' => [self::ENABLED, true, TurboStateEnum::Enabled];
        yield 'enabled in the workers only' => ["Turbo extension: enabled in worker processes (the main process runs without it)\n", true, TurboStateEnum::Enabled];
        yield 'not loaded on a host php-qa-ci ships a build for' => [self::NOT_LOADED, true, TurboStateEnum::Missing];
        yield 'a stale binary on a host php-qa-ci ships a build for' => [self::STALE, true, TurboStateEnum::Missing];
        yield 'not loaded on a host upstream builds nothing for' => [self::NOT_LOADED, false, TurboStateEnum::NotBuiltForHost];
        yield 'diagnose said nothing about Turbo' => ['', true, TurboStateEnum::Unknown];
    }

    #[Test]
    public function anEnabledRunSaysSoWithTheVersion(): void
    {
        self::assertSame('PHPStan Turbo: enabled (version 6351afb)', TurboStatus::fromDiagnose(self::ENABLED, true)->line());
    }

    /** The case php-qa-ci could have prevented: the report names the fix and keeps PHPStan's own reasons. */
    #[Test]
    public function aMissingBuildIsReportedWithTheFixAndPhpstansReasons(): void
    {
        $line = TurboStatus::fromDiagnose(self::NOT_LOADED, true)->line();

        self::assertStringStartsWith('PHPStan Turbo: NOT RUNNING, though php-qa-ci ships a build for this host.', $line);
        self::assertStringContainsString('turbo-install', $line);
        self::assertStringContainsString('Turbo extension: not loaded', $line);
        self::assertStringContainsString('Turbo worker binary: none found', $line);
    }

    #[Test]
    public function aHostWithoutABuildIsReportedAsTheExpectedFallback(): void
    {
        self::assertSame(
            'PHPStan Turbo: not running; upstream publishes no build for this host, so PHPStan runs without it',
            TurboStatus::fromDiagnose(self::NOT_LOADED, false)->line(),
        );
    }

    #[Test]
    public function anUnknownStateSaysDiagnoseDidNotReportIt(): void
    {
        self::assertSame('PHPStan Turbo: unknown; phpstan.phar diagnose did not report it', TurboStatus::fromDiagnose('', true)->line());
    }
}
