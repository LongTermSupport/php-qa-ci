<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Changelog;

use Iterator;
use LTS\PHPQA\Changelog\Exception\ChangelogReleaseException;
use LTS\PHPQA\Changelog\ReleaseBumpEnum;
use LTS\PHPQA\Changelog\ReleaseVersionCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Safe\Exceptions\JsonException;

/**
 * @internal
 */
#[CoversClass(ReleaseVersionCalculator::class)]
#[CoversClass(ChangelogReleaseException::class)]
#[Small]
final class ReleaseVersionCalculatorTest extends TestCase
{
    private const array TAGS = ['84.0.0', '85.0.0', '85.2.0', '85.10.1', '85.9.9', 'v85.11.0', '85.12', '85.13.0-rc1', '850.1.0', 'foo'];

    #[Test]
    #[DataProvider('lines')]
    public function theMajorIsThePhpLineWithoutTheDot(string $constraint, int $major): void
    {
        self::assertSame($major, new ReleaseVersionCalculator()->lineMajor($this->composer($constraint)));
    }

    /** @return Iterator<string, array{string, int}> */
    public static function lines(): Iterator
    {
        yield 'php 8.5'  => ['^8.5', 85];
        yield 'php 8.4'  => ['^8.4', 84];
        yield 'php 10.1' => ['^10.1', 101];
    }

    #[Test]
    #[DataProvider('ambiguousConstraints')]
    public function anythingButOneCaretLineFailsLoudly(string $constraint): void
    {
        $this->expectException(ChangelogReleaseException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('composer.json require.php must be a single "^X.Y" constraint to name the release line; found "%s"', $constraint));

        new ReleaseVersionCalculator()->lineMajor($this->composer($constraint));
    }

    /** @return Iterator<string, array{string}> */
    public static function ambiguousConstraints(): Iterator
    {
        yield 'a range'         => ['>=8.5'];
        yield 'two lines'       => ['^8.5 || ^8.6'];
        yield 'a patch'         => ['^8.5.1'];
        yield 'a wildcard'      => ['8.5.*'];
        yield 'surrounding gap' => [' ^8.5'];
    }

    #[Test]
    public function aComposerJsonWithNoPhpRequirementFailsLoudly(): void
    {
        $this->expectException(ChangelogReleaseException::class);
        $this->expectExceptionMessageIsOrContains('composer.json has no require.php, so it names no release line');

        new ReleaseVersionCalculator()->lineMajor('{"require": {"ext-json": "*"}}');
    }

    #[Test]
    public function anUnreadableComposerJsonFailsLoudly(): void
    {
        $this->expectException(ChangelogReleaseException::class);
        $this->expectExceptionMessageIsOrContains('composer.json is not a JSON object');

        new ReleaseVersionCalculator()->lineMajor('[1, 2]');
    }

    #[Test]
    public function aComposerJsonThatDoesNotParseFailsLoudlyWithTheParseErrorAsItsCause(): void
    {
        try {
            new ReleaseVersionCalculator()->lineMajor('{"require": ');
        } catch (ChangelogReleaseException $changelogReleaseException) {
            self::assertSame('composer.json is not valid JSON: Syntax error', $changelogReleaseException->getMessage());
            self::assertSame(0, $changelogReleaseException->getCode());
            self::assertInstanceOf(JsonException::class, $changelogReleaseException->getPrevious());

            return;
        }

        self::fail('expected the calculator to refuse an unparseable composer.json');
    }

    #[Test]
    public function anEmptyObjectNamesNoLine(): void
    {
        $this->expectException(ChangelogReleaseException::class);
        $this->expectExceptionMessageIsOrContains('composer.json has no require.php');

        new ReleaseVersionCalculator()->lineMajor('{}');
    }

    #[Test]
    public function theLatestTagOnTheLineIsComparedNumericallyAndStrictly(): void
    {
        $calculator = new ReleaseVersionCalculator();

        self::assertSame('85.10.1', $calculator->latestOnLine(85, ...self::TAGS));
        self::assertSame('84.0.0', $calculator->latestOnLine(84, ...self::TAGS));
        self::assertNull($calculator->latestOnLine(86, ...self::TAGS));
        self::assertSame('85.0.10', $calculator->latestOnLine(85, '85.0.9', '85.0.10', '85.0.2'));
    }

    #[Test]
    public function aMinorBumpResetsThePatchAndAPatchBumpMovesIt(): void
    {
        $calculator = new ReleaseVersionCalculator();

        self::assertSame('85.11.0', $calculator->next(85, ReleaseBumpEnum::Minor, ...self::TAGS));
        self::assertSame('85.10.2', $calculator->next(85, ReleaseBumpEnum::Patch, ...self::TAGS));
    }

    #[Test]
    public function theFirstReleaseOnALineIsItsDotZero(): void
    {
        $calculator = new ReleaseVersionCalculator();

        self::assertSame('86.0.0', $calculator->next(86, ReleaseBumpEnum::Minor, ...self::TAGS));
        self::assertSame('86.0.0', $calculator->next(86, ReleaseBumpEnum::Patch));
    }

    private function composer(string $constraint): string
    {
        return \Safe\json_encode(['name' => 'x/y', 'require' => ['php' => $constraint]]);
    }
}
