<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\VersionPins;

use LTS\PHPQA\VersionPins\PhpUnitConfigDetector;
use LTS\PHPQA\VersionPins\SafeScanFilesDetector;
use LTS\PHPQA\VersionPins\VersionPinsCheck;
use LTS\PHPQA\VersionPins\WorkflowPhpVersionDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Version;

/**
 * @internal
 */
#[CoversClass(VersionPinsCheck::class)]
#[UsesClass(PhpUnitConfigDetector::class)]
#[UsesClass(SafeScanFilesDetector::class)]
#[UsesClass(WorkflowPhpVersionDetector::class)]
#[Small]
final class VersionPinsCheckTest extends TestCase
{
    private const string PROJECTS = __DIR__ . '/../../assets/versionPins/project';

    private const string CURRENT = self::PROJECTS . '/current';

    private const string STALE = self::PROJECTS . '/stale';

    #[Test]
    public function itReturnsZeroAndPrintsTheExactOkMessageWhenEveryPinAgrees(): void
    {
        self::expectOutputString('Version pins agree with the toolchain in use (PHPUnit 13.3.2, PHP 8.5): phpunit.xml, safe scan-files, GitHub Actions workflows — OK.' . \PHP_EOL);
        self::assertSame(0, $this->check()->run(self::CURRENT, self::CURRENT . '/qaConfig/phpunit.xml', self::CURRENT . '/qaConfig/composerRequireChecker.json'));
    }

    #[Test]
    public function itReturnsOneAndPrintsEveryStalePinFromAllThreeSourcesInOneBlock(): void
    {
        $phpunit = self::STALE . '/qaConfig/phpunit.xml';
        $crc     = self::STALE . '/qaConfig/composerRequireChecker.json';

        $expected = \PHP_EOL . 'ERROR — a version pin in the QA configuration does not match the toolchain in use' . \PHP_EOL
            . '----------------------------------------------------------------------------------' . \PHP_EOL
            . 'Installed: PHPUnit 13.3.2, PHP 8.5' . \PHP_EOL . \PHP_EOL
            . '  - phpunit.xml (' . $phpunit . '): xsi:noNamespaceSchemaLocation points at the PHPUnit 10 schema but PHPUnit 13.3.2 is installed; set it to https://schema.phpunit.de/13.3/phpunit.xsd' . \PHP_EOL
            . '  - phpunit.xml (' . $phpunit . '): SYMFONY_PHPUNIT_VERSION pins PHPUnit 10 but PHPUnit 13.3.2 is installed; set the pin to 13.3 or remove it' . \PHP_EOL
            . '  - composerRequireChecker.json (' . $crc . '): "vendor/thecodingmachine/safe/generated/8.2/array.php": on PHP 8.5 safe loads generated/8.4/array.php, not the 8.2 one; replace the entry with "vendor/thecodingmachine/safe/generated/8.4/array.php"' . \PHP_EOL
            . '  - composerRequireChecker.json (' . $crc . '): "vendor/thecodingmachine/safe/generated/8.4/exec.php": on PHP 8.5 safe loads generated/8.2/exec.php, not the 8.4 one; replace the entry with "vendor/thecodingmachine/safe/generated/8.2/exec.php"' . \PHP_EOL
            . '  - templates/github-actions/autofix.yml: the PHP version detection list [8.4, 8.3] cannot select PHP 8.5, which composer.json requires; add 8.5 to the list' . \PHP_EOL
            . '  - templates/github-actions/autofix.yml: the fallback PHP version is 8.3 but composer.json requires 8.5; set the default to 8.5' . \PHP_EOL
            . '  - templates/github-actions/php-qa-ci.yml differs from .github/workflows/qa.yml; the shipped consumer template must stay identical to the workflow this repository runs' . \PHP_EOL
            . \PHP_EOL . '🪪  ' . VersionPinsCheck::IDENTIFIER . '  (vendor/bin/rule-doc ' . VersionPinsCheck::IDENTIFIER . ')' . \PHP_EOL;

        self::expectOutputString($expected);
        self::assertSame(1, $this->check()->run(self::STALE, $phpunit, $crc));
    }

    #[Test]
    public function aMissingConfigFileIsReportedAsAPinProblemNotACrash(): void
    {
        $this->expectOutputRegex('#phpunit\.xml: config path does not exist: .*nope\.xml.*composerRequireChecker\.json: config path does not exist: .*nope\.json#s');
        self::assertSame(1, $this->check()->run(self::CURRENT, self::CURRENT . '/nope.xml', self::CURRENT . '/nope.json'));
    }

    #[Test]
    public function invalidJsonAndANonListScanFilesAreReported(): void
    {
        $this->expectOutputRegex('#not valid JSON \(Syntax error\)#');
        self::assertSame(1, $this->check()->run(self::CURRENT, self::CURRENT . '/qaConfig/phpunit.xml', self::STALE . '/qaConfig/invalid.json'));
    }

    #[Test]
    public function aNonListScanFilesIsReported(): void
    {
        $this->expectOutputRegex('#"scan-files" is not a list#');
        self::assertSame(1, $this->check()->run(self::CURRENT, self::CURRENT . '/qaConfig/phpunit.xml', self::STALE . '/qaConfig/not-a-list.json'));
    }

    #[Test]
    public function mainJudgesThisRepositoryAgainstTheInstalledToolchainAndItPasses(): void
    {
        $root = __DIR__ . '/../../..';

        $this->expectOutputRegex('#Version pins agree with the toolchain in use \(PHPUnit ' . preg_quote(Version::id(), '#') . ', PHP \d+\.\d+\)#');
        self::assertSame(0, VersionPinsCheck::main($root, $root . '/qaConfig/phpunit.xml', $root . '/qaConfig/composerRequireChecker.json'));
    }

    private function check(): VersionPinsCheck
    {
        return new VersionPinsCheck('13.3.2', '8.5');
    }
}
