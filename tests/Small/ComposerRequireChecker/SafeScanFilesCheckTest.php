<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\ComposerRequireChecker;

use LTS\PHPQA\ComposerRequireChecker\SafeScanFilesCheck;
use LTS\PHPQA\ComposerRequireChecker\SafeScanFilesDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SafeScanFilesCheck::class)]
#[UsesClass(SafeScanFilesDetector::class)]
#[Small]
final class SafeScanFilesCheckTest extends TestCase
{
    /** JSON configs live beside the test; the safe dispatcher tree they point at lives under tests/assets. */
    private const string FIXTURE_ROOT = __DIR__ . '/../../assets/composerRequireChecker';

    private const string CONFIG_DIR = __DIR__ . '/Fixture';

    #[Test]
    public function itReturnsZeroAndPrintsTheExactOkMessageWhenEveryEntryIsCurrent(): void
    {
        $configPath = self::CONFIG_DIR . '/current.json';

        self::expectOutputString('composer-require-checker scan-files name the safe files loaded on PHP 8.5 — OK (' . $configPath . ').' . \PHP_EOL);
        self::assertSame(0, new SafeScanFilesCheck('8.5')->run($configPath, self::FIXTURE_ROOT));
    }

    #[Test]
    public function itReturnsOneAndPrintsTheExactFailureBlockWhenAnEntryIsStale(): void
    {
        $configPath = self::CONFIG_DIR . '/stale.json';

        $expected = \PHP_EOL . 'ERROR — composer-require-checker scan-files list safe files PHP 8.5 does not load' . \PHP_EOL
            . '-----------------------------------------------------------------------------------' . \PHP_EOL
            . 'Config file: ' . $configPath . \PHP_EOL . \PHP_EOL
            . '  - "vendor/thecodingmachine/safe/generated/8.2/array.php": on PHP 8.5 safe loads generated/8.4/array.php, '
            . 'not the 8.2 one; replace the entry with "vendor/thecodingmachine/safe/generated/8.4/array.php"' . \PHP_EOL
            . '  - "vendor/thecodingmachine/safe/generated/8.4/exec.php": on PHP 8.5 safe loads generated/8.2/exec.php, '
            . 'not the 8.4 one; replace the entry with "vendor/thecodingmachine/safe/generated/8.2/exec.php"' . \PHP_EOL
            . \PHP_EOL . '🪪  ' . SafeScanFilesCheck::IDENTIFIER
            . '  (vendor/bin/rule-doc ' . SafeScanFilesCheck::IDENTIFIER . ')' . \PHP_EOL;

        self::expectOutputString($expected);
        self::assertSame(1, new SafeScanFilesCheck('8.5')->run($configPath, self::FIXTURE_ROOT));
    }

    #[Test]
    public function itReturnsOneAndPrintsTheExactMissingConfigMessage(): void
    {
        $configPath = self::CONFIG_DIR . '/nope.json';

        self::expectOutputString('ERROR — composer-require-checker config path does not exist: ' . $configPath . \PHP_EOL);
        self::assertSame(1, new SafeScanFilesCheck('8.5')->run($configPath, self::FIXTURE_ROOT));
    }

    #[Test]
    public function itReturnsOneAndPrintsTheExactInvalidJsonMessage(): void
    {
        $configPath = self::CONFIG_DIR . '/invalid.json';

        self::expectOutputString('ERROR — composer-require-checker config could not be parsed as valid JSON: ' . $configPath . ' (Syntax error)' . \PHP_EOL);
        self::assertSame(1, new SafeScanFilesCheck('8.5')->run($configPath, self::FIXTURE_ROOT));
    }

    #[Test]
    public function itReturnsOneWhenScanFilesIsNotAList(): void
    {
        $configPath = self::CONFIG_DIR . '/not-a-list.json';

        self::expectOutputString('ERROR — composer-require-checker config "scan-files" is not a list: ' . $configPath . \PHP_EOL);
        self::assertSame(1, new SafeScanFilesCheck('8.5')->run($configPath, self::FIXTURE_ROOT));
    }

    #[Test]
    public function mainJudgesTheShippedDefaultAgainstTheRunningPhpAndTheRealSafePackage(): void
    {
        $shippedDefault = __DIR__ . '/../../../configDefaults/generic/composerRequireChecker.json';
        $projectRoot    = __DIR__ . '/../../..';

        $this->expectOutputRegex('#scan-files name the safe files loaded on PHP ' . \PHP_MAJOR_VERSION . '\.' . \PHP_MINOR_VERSION . ' — OK#');
        self::assertSame(0, SafeScanFilesCheck::main($shippedDefault, $projectRoot));
    }
}
