<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\InfectionConfig;

use LTS\PHPQA\InfectionConfig\InfectionConfigSourceDirectoriesDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @internal
 */
#[CoversClass(InfectionConfigSourceDirectoriesDetector::class)]
#[Small]
final class InfectionConfigSourceDirectoriesDetectorTest extends TestCase
{
    private InfectionConfigSourceDirectoriesDetector $detector;

    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->detector   = new InfectionConfigSourceDirectoriesDetector();
        $this->fixtureDir = __DIR__ . '/Fixture';
    }

    #[Test]
    public function aSourceDirectoryThatResolvesToAnExistingDirectoryIsAccepted(): void
    {
        $problems = $this->detector->check(
            ['source' => ['directories' => ['src']]],
            $this->fixtureDir . '/Real',
        );

        self::assertSame([], $problems);
    }

    #[Test]
    public function aSourceDirectoryThatDoesNotResolveIsReportedByName(): void
    {
        $problems = $this->detector->check(
            ['source' => ['directories' => ['src']]],
            $this->fixtureDir . '/Broken',
        );

        $expected = 'source.directories entry "src" does not resolve to an existing directory '
            . '(resolved, relative to the config file\'s own directory, to "'
            . $this->fixtureDir . '/Broken/src").';

        self::assertSame([$expected], $problems);
    }

    #[Test]
    public function aCorrectlyAdjustedRelativePathIsAccepted(): void
    {
        $problems = $this->detector->check(
            ['source' => ['directories' => ['../src']]],
            $this->fixtureDir . '/Real/qaConfig',
        );

        self::assertSame([], $problems);
    }

    #[Test]
    public function multipleDirectoriesAreEachCheckedIndependently(): void
    {
        $problems = $this->detector->check(
            ['source' => ['directories' => ['src', 'nope', 'also-missing']]],
            $this->fixtureDir . '/Real',
        );

        self::assertCount(2, $problems);
    }

    #[Test]
    public function anAbsentSourceKeyIsIgnored(): void
    {
        self::assertSame([], $this->detector->check([], $this->fixtureDir . '/Real'));
    }

    #[Test]
    public function anEmptyDirectoriesListIsIgnored(): void
    {
        self::assertSame([], $this->detector->check(['source' => ['directories' => []]], $this->fixtureDir . '/Real'));
    }

    #[Test]
    public function aNonStringDirectoryEntryIsIgnoredRatherThanCrashing(): void
    {
        self::assertSame([], $this->detector->check(['source' => ['directories' => [123, null]]], $this->fixtureDir . '/Real'));
    }

    #[Test]
    public function aNonStringDirectoryEntryIsSkippedRatherThanHaltingLaterEntries(): void
    {
        $problems = $this->detector->check(
            ['source' => ['directories' => [123, 'nope']]],
            $this->fixtureDir . '/Real',
        );

        self::assertCount(1, $problems);
    }

    #[Test]
    public function aSourceValueThatIsNeitherArrayNorArrayAccessibleIsRejectedWithoutCrashing(): void
    {
        self::assertSame([], $this->detector->check(['source' => new stdClass()], $this->fixtureDir . '/Real'));
    }

    #[Test]
    public function anAbsolutePathIsCheckedAsIs(): void
    {
        $problems = $this->detector->check(
            ['source' => ['directories' => [$this->fixtureDir . '/Real/src']]],
            $this->fixtureDir . '/Broken',
        );

        self::assertSame([], $problems);
    }
}
