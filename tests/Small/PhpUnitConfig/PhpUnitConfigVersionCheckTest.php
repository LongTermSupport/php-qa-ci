<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PhpUnitConfig;

use LTS\PHPQA\PhpUnitConfig\PhpUnitConfigVersionCheck;
use LTS\PHPQA\PhpUnitConfig\PhpUnitConfigVersionDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Version;

/**
 * @internal
 */
#[CoversClass(PhpUnitConfigVersionCheck::class)]
#[UsesClass(PhpUnitConfigVersionDetector::class)]
#[Small]
final class PhpUnitConfigVersionCheckTest extends TestCase
{
    private const string FIXTURE_DIR = __DIR__ . '/Fixture';

    #[Test]
    public function itReturnsZeroAndPrintsTheExactOkMessageWhenThePinsAgree(): void
    {
        $configPath = self::FIXTURE_DIR . '/current/phpunit.xml';

        self::expectOutputString('phpunit.xml version pins agree with the installed PHPUnit 13.3.2 — OK (' . $configPath . ').' . \PHP_EOL);
        self::assertSame(0, new PhpUnitConfigVersionCheck('13.3.2')->run($configPath));
    }

    #[Test]
    public function itReturnsOneAndPrintsTheExactFailureBlockWhenAPinIsStale(): void
    {
        $configPath = self::FIXTURE_DIR . '/stale/phpunit.xml';

        $expected = \PHP_EOL . 'ERROR — phpunit.xml pins a PHPUnit version that is not the one installed' . \PHP_EOL
            . '--------------------------------------------------------------------------' . \PHP_EOL
            . 'Config file: ' . $configPath . \PHP_EOL . \PHP_EOL
            . '  - xsi:noNamespaceSchemaLocation points at the PHPUnit 10 schema but PHPUnit 13.3.2 is installed; '
            . 'set it to https://schema.phpunit.de/13.3/phpunit.xsd' . \PHP_EOL
            . '  - SYMFONY_PHPUNIT_VERSION pins PHPUnit 10 but PHPUnit 13.3.2 is installed; set the pin to 13.3 or remove it' . \PHP_EOL
            . \PHP_EOL . '🪪  ' . PhpUnitConfigVersionCheck::IDENTIFIER
            . '  (vendor/bin/rule-doc ' . PhpUnitConfigVersionCheck::IDENTIFIER . ')' . \PHP_EOL;

        self::expectOutputString($expected);
        self::assertSame(1, new PhpUnitConfigVersionCheck('13.3.2')->run($configPath));
    }

    #[Test]
    public function itReturnsOneAndPrintsTheExactMissingConfigMessage(): void
    {
        $configPath = self::FIXTURE_DIR . '/nope/phpunit.xml';

        self::expectOutputString('ERROR — phpunit.xml config path does not exist: ' . $configPath . \PHP_EOL);
        self::assertSame(1, new PhpUnitConfigVersionCheck('13.3.2')->run($configPath));
    }

    #[Test]
    public function mainJudgesAgainstTheInstalledPhpUnitAndTheShippedDefaultAgreesWithIt(): void
    {
        $shippedDefault = __DIR__ . '/../../../configDefaults/generic/phpunit.xml';

        self::expectOutputString('phpunit.xml version pins agree with the installed PHPUnit ' . Version::id() . ' — OK (' . $shippedDefault . ').' . \PHP_EOL);
        self::assertSame(0, PhpUnitConfigVersionCheck::main($shippedDefault));
    }
}
