<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Changelog;

use InvalidArgumentException;
use Iterator;
use LTS\PHPQA\Changelog\Exception\ChangelogReleaseException;
use LTS\PHPQA\Changelog\ReleaseBumpEnum;
use LTS\PHPQA\Changelog\ReleaseLine;
use LTS\PHPQA\Changelog\ReleaseVersionPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Safe\Exceptions\JsonException;

/**
 * The two versioning policies a release can follow: semantic versioning, the
 * default, and a locked major, the override php-qa-ci declares for itself
 * because its major is the PHP line.
 *
 * @internal
 */
#[CoversClass(ReleaseVersionPolicy::class)]
#[CoversClass(ReleaseLine::class)]
#[CoversClass(ChangelogReleaseException::class)]
#[Small]
final class ReleaseVersionPolicyTest extends TestCase
{
    private const string NO_COMPOSER = '{}';

    private const string PHP_85 = '{"require": {"php": "^8.5"}}';

    private const string LATEST = '1.4.2';

    private const array LOCKED_TAGS = ['84.0.0', '85.0.0', '85.2.0', '85.10.1', '85.9.9', 'v85.11.0', '85.12', '85.13.0-rc1', '850.1.0', '86.0.0', 'foo'];

    #[Test]
    #[DataProvider('semanticBumps')]
    public function semanticVersioningMovesThePartTheChangeAsksFor(ReleaseBumpEnum $bump, string $next): void
    {
        self::assertSame($next, $this->semver()->next($bump, '1.0.0', self::LATEST, '1.3.9'));
    }

    /** @return Iterator<string, array{ReleaseBumpEnum, string}> */
    public static function semanticBumps(): Iterator
    {
        yield 'a breaking change moves the major' => [ReleaseBumpEnum::Major, '2.0.0'];
        yield 'a feature moves the minor'         => [ReleaseBumpEnum::Minor, '1.5.0'];
        yield 'a fix moves the patch'             => [ReleaseBumpEnum::Patch, '1.4.3'];
    }

    #[Test]
    #[DataProvider('initialDevelopmentBumps')]
    public function whileTheMajorIsZeroABreakingChangeMovesTheMinor(ReleaseBumpEnum $bump, string $next): void
    {
        self::assertSame($next, $this->semver()->next($bump, '0.3.1'));
    }

    /** @return Iterator<string, array{ReleaseBumpEnum, string}> */
    public static function initialDevelopmentBumps(): Iterator
    {
        yield 'breaking' => [ReleaseBumpEnum::Major, '0.4.0'];
        yield 'feature'  => [ReleaseBumpEnum::Minor, '0.4.0'];
        yield 'fix'      => [ReleaseBumpEnum::Patch, '0.3.2'];
    }

    #[Test]
    public function theFirstSemanticReleaseIsZeroDotOneDotZeroWhateverItHolds(): void
    {
        $line = $this->semver();

        self::assertSame('0.1.0', ReleaseVersionPolicy::FIRST_VERSION);
        foreach (ReleaseBumpEnum::cases() as $bump) {
            self::assertSame('0.1.0', $line->next($bump), $bump->name);
            self::assertSame('0.1.0', $line->next($bump, 'foo', 'v9.9.9'), $bump->name);
        }

        self::assertSame('0.1.0', $line->firstVersion());
    }

    #[Test]
    public function theFirstSemanticReleaseIsConfigurable(): void
    {
        $line = ReleaseVersionPolicy::semanticVersioning(firstVersion: '1.0.0')->line(self::NO_COMPOSER);

        self::assertSame('1.0.0', $line->next(ReleaseBumpEnum::Patch));
        self::assertSame('1.5.0', $line->next(ReleaseBumpEnum::Minor, self::LATEST));
    }

    #[Test]
    public function aTagPrefixIsPartOfTheTagAndNotOfTheVersion(): void
    {
        $line = ReleaseVersionPolicy::semanticVersioning('v')->line(self::NO_COMPOSER);

        self::assertSame('1.5.0', $line->next(ReleaseBumpEnum::Minor, 'v1.4.2', '7.0.0', 'v1.4.3-rc1'));
        self::assertSame('v1.4.2', $line->latestTag('v1.4.2', '7.0.0', 'v1.3.0'));
        self::assertSame('v1.5.0', $line->tagOf('1.5.0'));
        self::assertSame('1.4.2', $line->versionOfTag('v1.4.2'));
        self::assertNull($line->versionOfTag('1.4.2'));
        self::assertSame('v0.1.0', $line->tagOf($line->firstVersion()));
    }

    #[Test]
    public function withoutAPrefixAPrefixedTagIsNoRelease(): void
    {
        $line = $this->semver();

        self::assertSame('1.4.0', $line->latestTag('v2.0.0', '1.4.0'));
        self::assertNull($line->versionOfTag('v2.0.0'));
        self::assertSame('1.4.0', $line->tagOf('1.4.0'));
    }

    #[Test]
    public function onlyAPlainReleaseVersionIsARelease(): void
    {
        $line = $this->semver();

        foreach (['1.4', '1.4.2-rc1', '1.4.2+build', '01.4.2', '1.04.2', 'release-1.4.2', 'foo', '', '1.4.2.0'] as $notARelease) {
            self::assertFalse($line->isRelease($notARelease), $notARelease);
            self::assertNull($line->versionOfTag($notARelease), $notARelease);
        }

        self::assertTrue($line->isRelease('0.0.0'));
        self::assertTrue($line->isRelease('10.20.30'));
        self::assertNull($line->latestTag('1.4', '1.5.0-rc1', 'foo'));
    }

    #[Test]
    public function tagsCompareNumerically(): void
    {
        $line = $this->semver();

        self::assertSame('1.10.0', $line->latestTag('1.9.0', '1.10.0', '1.2.0'));
        self::assertSame('1.10.1', $line->next(ReleaseBumpEnum::Patch, '1.9.9', '1.10.0'));
        self::assertSame('2.0.0', $line->latestTag('1.10.0', '2.0.0', '1.99.99'));
    }

    #[Test]
    public function aLockedMajorMovesTheMinorForABreakingChange(): void
    {
        $line = ReleaseVersionPolicy::lockedMajor(85)->line(self::NO_COMPOSER);

        self::assertSame('85.11.0', $line->next(ReleaseBumpEnum::Major, ...self::LOCKED_TAGS));
        self::assertSame('85.11.0', $line->next(ReleaseBumpEnum::Minor, ...self::LOCKED_TAGS));
        self::assertSame('85.10.2', $line->next(ReleaseBumpEnum::Patch, ...self::LOCKED_TAGS));
        self::assertSame('85.10.1', $line->latestTag(...self::LOCKED_TAGS));
        self::assertSame(85, $line->lockedMajor);
    }

    #[Test]
    public function aLockedMajorIgnoresEveryOtherLine(): void
    {
        $line = ReleaseVersionPolicy::lockedMajor(85)->line(self::NO_COMPOSER);

        self::assertFalse($line->isRelease('86.0.0'));
        self::assertFalse($line->isRelease('84.9.0'));
        self::assertFalse($line->isRelease('850.1.0'));
        self::assertTrue($line->isRelease('85.0.0'));
        self::assertSame('85.0.10', $line->latestTag('85.0.9', '85.0.10', '85.0.2', '86.1.0'));
    }

    #[Test]
    public function theFirstReleaseOnALockedLineIsItsDotZero(): void
    {
        $line = ReleaseVersionPolicy::lockedMajor(86)->line(self::NO_COMPOSER);

        self::assertSame('86.0.0', $line->next(ReleaseBumpEnum::Major, ...self::LOCKED_TAGS));
        self::assertSame('86.0.0', $line->next(ReleaseBumpEnum::Patch));
        self::assertSame('86.0.0', $line->firstVersion());
    }

    #[Test]
    public function aLockedLineTakesAPrefixToo(): void
    {
        $line = ReleaseVersionPolicy::lockedMajor(85, 'v')->line(self::NO_COMPOSER);

        self::assertSame('85.12.0', $line->next(ReleaseBumpEnum::Major, ...self::LOCKED_TAGS));
        self::assertSame('v85.11.0', $line->latestTag(...self::LOCKED_TAGS));
    }

    #[Test]
    #[DataProvider('phpLines')]
    public function theMajorCanBeThePhpLineWithoutTheDot(string $constraint, int $major): void
    {
        $line = ReleaseVersionPolicy::lockedMajorFromPhpRequirement()->line($this->composer($constraint));

        self::assertSame($major, $line->lockedMajor);
        self::assertSame($major . '.0.0', $line->firstVersion());
    }

    /** @return Iterator<string, array{string, int}> */
    public static function phpLines(): Iterator
    {
        yield 'php 8.5'  => ['^8.5', 85];
        yield 'php 8.4'  => ['^8.4', 84];
        yield 'php 10.1' => ['^10.1', 101];
    }

    #[Test]
    public function phpQaCisNextBreakingReleaseIsTheNextMinorOnItsLine(): void
    {
        $line = ReleaseVersionPolicy::lockedMajorFromPhpRequirement()->line(self::PHP_85);

        self::assertSame('85.3.0', $line->next(ReleaseBumpEnum::Major, '85.0.0', '85.1.0', '85.2.0'));
    }

    #[Test]
    #[DataProvider('ambiguousConstraints')]
    public function anythingButOneCaretLineFailsLoudly(string $constraint): void
    {
        $this->expectException(ChangelogReleaseException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('composer.json require.php must be a single "^X.Y" constraint to name the release line; found "%s"', $constraint));

        ReleaseVersionPolicy::lockedMajorFromPhpRequirement()->line($this->composer($constraint));
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

        ReleaseVersionPolicy::lockedMajorFromPhpRequirement()->line('{"require": {"ext-json": "*"}}');
    }

    #[Test]
    public function aComposerJsonThatIsNotAnObjectFailsLoudly(): void
    {
        $this->expectException(ChangelogReleaseException::class);
        $this->expectExceptionMessageIsOrContains('composer.json is not a JSON object');

        ReleaseVersionPolicy::lockedMajorFromPhpRequirement()->line('[1, 2]');
    }

    #[Test]
    public function aComposerJsonThatDoesNotParseFailsLoudlyWithTheParseErrorAsItsCause(): void
    {
        try {
            ReleaseVersionPolicy::lockedMajorFromPhpRequirement()->line('{"require": ');
        } catch (ChangelogReleaseException $changelogReleaseException) {
            self::assertSame('composer.json is not valid JSON: Syntax error', $changelogReleaseException->getMessage());
            self::assertSame(0, $changelogReleaseException->getCode());
            self::assertInstanceOf(JsonException::class, $changelogReleaseException->getPrevious());

            return;
        }

        self::fail('expected the policy to refuse an unparseable composer.json');
    }

    #[Test]
    public function onlyThePhpDerivedPolicyReadsComposerJson(): void
    {
        self::assertSame('2.0.0', ReleaseVersionPolicy::semanticVersioning()->line('not json')->next(ReleaseBumpEnum::Major, self::LATEST));
        self::assertSame('85.0.0', ReleaseVersionPolicy::lockedMajor(85)->line('not json')->firstVersion());
    }

    #[Test]
    public function theDefaultPolicyIsSemanticVersioningWithNoPrefix(): void
    {
        $default = new ReleaseVersionPolicy();

        self::assertEquals(ReleaseVersionPolicy::semanticVersioning(), $default);
        self::assertSame('', $default->tagPrefix);
        self::assertNull($default->line(self::NO_COMPOSER)->lockedMajor);
        self::assertSame('2.0.0', $default->line(self::NO_COMPOSER)->next(ReleaseBumpEnum::Major, self::LATEST));
    }

    #[Test]
    public function eachPolicyDescribesTheTagsItReleases(): void
    {
        self::assertSame('X.Y.Z', $this->semver()->describe());
        self::assertSame('vX.Y.Z', ReleaseVersionPolicy::semanticVersioning('v')->line(self::NO_COMPOSER)->describe());
        self::assertSame('85.N.N', ReleaseVersionPolicy::lockedMajorFromPhpRequirement()->line(self::PHP_85)->describe());
        self::assertSame('release-7.N.N', ReleaseVersionPolicy::lockedMajor(7, 'release-')->line(self::NO_COMPOSER)->describe());
    }

    #[Test]
    #[DataProvider('invalidPolicies')]
    public function aPolicyThatCannotNameAReleaseIsRefusedWhenDeclared(callable $declare, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);

        $declare();
    }

    /** @return Iterator<string, array{callable(): ReleaseVersionPolicy, string}> */
    public static function invalidPolicies(): Iterator
    {
        yield 'a first version that is not X.Y.Z' => [static fn (): ReleaseVersionPolicy => ReleaseVersionPolicy::semanticVersioning(firstVersion: 'v1.0.0'), 'first version must be a plain X.Y.Z version; got "v1.0.0"'];
        yield 'a prefix starting with a digit'     => [static fn (): ReleaseVersionPolicy => ReleaseVersionPolicy::semanticVersioning('1'), 'tag prefix must start with a letter'];
        yield 'a prefix with a space'              => [static fn (): ReleaseVersionPolicy => ReleaseVersionPolicy::lockedMajor(85, 'v '), 'tag prefix must start with a letter'];
        yield 'a negative major'                   => [static fn (): ReleaseVersionPolicy => ReleaseVersionPolicy::lockedMajor(-1), 'locked major must be 0 or more; got -1'];
        yield 'two sources for the major'          => [static fn (): ReleaseVersionPolicy => new ReleaseVersionPolicy(lockedMajor: 85, lockMajorToPhpLine: true), 'lock the major to a number or to the PHP line, not both'];
    }

    private function semver(): ReleaseLine
    {
        return ReleaseVersionPolicy::semanticVersioning()->line(self::NO_COMPOSER);
    }

    private function composer(string $constraint): string
    {
        return \Safe\json_encode(['name' => 'x/y', 'require' => ['php' => $constraint]]);
    }
}
