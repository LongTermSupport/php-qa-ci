<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\VersionPins;

use LTS\PHPQA\VersionPins\PhpUnitConfigDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PhpUnitConfigDetector::class)]
#[Small]
final class PhpUnitConfigDetectorTest extends TestCase
{
    private const string PHPUNIT_CLOSE_TAG = '</phpunit>';

    private const string PHPUNIT_VERSION = '13.3.2';

    private const string SCHEMA_10 = '<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
        . 'xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.2/phpunit.xsd" colors="true">';

    private const string SCHEMA_13 = '<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
        . 'xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/13.3/phpunit.xsd" colors="true">';

    private PhpUnitConfigDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new PhpUnitConfigDetector();
    }

    #[Test]
    public function itPassesWhenTheSchemaMajorMatchesTheInstalledMajor(): void
    {
        self::assertSame([], $this->detector->check(self::SCHEMA_13 . self::PHPUNIT_CLOSE_TAG, self::PHPUNIT_VERSION));
    }

    #[Test]
    public function itPassesWhenTheSchemaMinorDiffersButTheMajorMatches(): void
    {
        self::assertSame([], $this->detector->check(self::SCHEMA_13 . self::PHPUNIT_CLOSE_TAG, '13.0.0'));
    }

    #[Test]
    public function itPassesWhenNoPinIsPresentAtAll(): void
    {
        self::assertSame([], $this->detector->check('<phpunit colors="true"></phpunit>', self::PHPUNIT_VERSION));
    }

    #[Test]
    public function itReportsASchemaMajorBehindTheInstalledMajorWithTheExactReplacement(): void
    {
        $problems = $this->detector->check(self::SCHEMA_10 . self::PHPUNIT_CLOSE_TAG, self::PHPUNIT_VERSION);

        self::assertSame(
            [
                'xsi:noNamespaceSchemaLocation points at the PHPUnit 10 schema but PHPUnit 13.3.2 is installed; '
                . 'set it to https://schema.phpunit.de/13.3/phpunit.xsd',
            ],
            $problems,
        );
    }

    #[Test]
    public function itReportsASchemaMajorAheadOfTheInstalledMajor(): void
    {
        $problems = $this->detector->check(self::SCHEMA_13 . self::PHPUNIT_CLOSE_TAG, '12.1.0');

        self::assertCount(1, $problems);
        self::assertStringContainsString('PHPUnit 13 schema but PHPUnit 12.1.0 is installed', $problems[0]);
        self::assertStringContainsString('https://schema.phpunit.de/12.1/phpunit.xsd', $problems[0]);
    }

    #[Test]
    public function itReportsAStaleSymfonyPinOnAServerElement(): void
    {
        $xml = self::SCHEMA_13 . '<php><server name="SYMFONY_PHPUNIT_VERSION" value="10.2"/></php></phpunit>';

        self::assertSame(
            ['SYMFONY_PHPUNIT_VERSION pins PHPUnit 10 but PHPUnit 13.3.2 is installed; set the pin to 13.3 or remove it'],
            $this->detector->check($xml, self::PHPUNIT_VERSION),
        );
    }

    #[Test]
    public function itReportsAStaleSymfonyPinOnAnEnvElement(): void
    {
        $xml = self::SCHEMA_13 . '<php><env name="SYMFONY_PHPUNIT_VERSION" value="9.6" force="true"/></php></phpunit>';

        self::assertCount(1, $this->detector->check($xml, self::PHPUNIT_VERSION));
    }

    #[Test]
    public function itAcceptsASymfonyPinWhoseMajorMatches(): void
    {
        $xml = self::SCHEMA_13 . '<php><server name="SYMFONY_PHPUNIT_VERSION" value="13.3"/></php></phpunit>';

        self::assertSame([], $this->detector->check($xml, self::PHPUNIT_VERSION));
    }

    #[Test]
    public function itIgnoresOtherSymfonyServerPins(): void
    {
        $xml = self::SCHEMA_13 . '<php><server name="SYMFONY_PHPUNIT_REMOVE" value=""/></php></phpunit>';

        self::assertSame([], $this->detector->check($xml, self::PHPUNIT_VERSION));
    }

    #[Test]
    public function itReportsBothPinsWhenBothAreStaleSchemaFirst(): void
    {
        $xml = self::SCHEMA_10 . '<php><server name="SYMFONY_PHPUNIT_VERSION" value="10.2"/></php></phpunit>';

        $problems = $this->detector->check($xml, self::PHPUNIT_VERSION);

        self::assertCount(2, $problems);
        self::assertStringStartsWith('xsi:noNamespaceSchemaLocation', $problems[0]);
        self::assertStringStartsWith('SYMFONY_PHPUNIT_VERSION', $problems[1]);
    }

    #[Test]
    public function itTreatsAnInstalledVersionWithNoMinorAsMinorZero(): void
    {
        $problems = $this->detector->check(self::SCHEMA_10 . self::PHPUNIT_CLOSE_TAG, '13');

        self::assertCount(1, $problems);
        self::assertStringContainsString('https://schema.phpunit.de/13.0/phpunit.xsd', $problems[0]);
    }
}
