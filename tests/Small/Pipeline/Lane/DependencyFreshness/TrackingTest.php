<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\DependencyFreshness;

use Iterator;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Tracking;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What a locked package follows (issue #345): the project's constraint
 * decides when it names only branches or only tags, the locked version
 * decides when it admits both.
 *
 * @internal
 */
#[CoversClass(Tracking::class)]
#[Small]
final class TrackingTest extends TestCase
{
    private const string BRANCH_LOCK = 'dev-php8.5';

    private const string TAG_LOCK = '1.0.0';

    private const string CARET = '^1.0';

    private const string COMPOSER_JSON = 'composer.json';

    private TempDir $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('phpqa-tracking');
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    #[Test]
    #[DataProvider('provideVersions')]
    public function aVersionIsABranchWhenComposerSpellsItAsOne(string $version, bool $branch): void
    {
        self::assertSame($branch, Tracking::isBranchVersion($version));
    }

    /** @return Iterator<string, array{string, bool}> */
    public static function provideVersions(): Iterator
    {
        yield 'a branch' => [self::BRANCH_LOCK, true];
        yield 'a branch with its commit, as printed' => ['dev-php8.5 93f1ae4', true];
        yield 'a numeric branch' => ['1.x-dev', true];
        yield 'a numeric branch with its commit' => ['1.x-dev 93f1ae4', true];
        yield 'a tag' => ['1.2.0', false];
        yield 'a v-prefixed tag' => ['v1.2.0', false];
        yield 'a prerelease tag' => ['1.0.0-RC1', false];
        yield 'a tag whose suffix only starts with dev' => ['1.0.0-developer', false];
        yield 'a tag whose text only mentions dev-' => ['1.0.0 dev-x', false];
        yield 'surrounding space' => ['  dev-main  ', true];
    }

    #[Test]
    #[DataProvider('provideTrackings')]
    public function aPackageFollowsWhatItsConstraintAndLockSay(?string $constraint, ?string $locked, bool $followsBranch): void
    {
        self::assertSame($followsBranch, Tracking::followsBranch($constraint, $locked));
    }

    /** @return Iterator<string, array{?string, ?string, bool}> */
    public static function provideTrackings(): Iterator
    {
        yield 'a branch constraint' => [self::BRANCH_LOCK, self::BRANCH_LOCK, true];
        yield 'a branch constraint with the stability flag' => ['dev-php8.5@dev', self::BRANCH_LOCK, true];
        yield 'a branch constraint, lock still on a tag' => [self::BRANCH_LOCK, self::TAG_LOCK, true];
        yield 'a numeric branch constraint' => ['1.x-dev', self::BRANCH_LOCK, true];
        yield 'a pinned branch commit' => ['dev-php8.5#93f1ae4', self::BRANCH_LOCK, true];
        yield 'a branch aliased to a version' => ['dev-php8.5 as 1.0.0', self::BRANCH_LOCK, true];
        yield 'a branch aliased, lock on a tag' => ['dev-php8.5 as 1.0.0', self::TAG_LOCK, true];
        yield 'several branches' => ['dev-main || dev-php8.5', self::TAG_LOCK, true];
        yield 'several branches, single bar' => ['dev-main | dev-php8.5', self::TAG_LOCK, true];
        yield 'a caret range' => [self::CARET, self::TAG_LOCK, false];
        yield 'a caret range, lock still on a branch' => [self::CARET, self::BRANCH_LOCK, false];
        yield 'a tilde range with the dev stability flag' => ['~1.0.0@dev', self::BRANCH_LOCK, false];
        yield 'an exact tag' => ['1.0.0', self::BRANCH_LOCK, false];
        yield 'a bounded range' => ['>=1.0 <2.0', self::BRANCH_LOCK, false];
        yield 'several ranges' => ['^1.0 || ^2.0', self::BRANCH_LOCK, false];
        yield 'any version, lock on a branch' => ['*', self::BRANCH_LOCK, true];
        yield 'any version, lock on a tag' => ['*', self::TAG_LOCK, false];
        yield 'any version with a stability flag, lock on a branch' => ['*@dev', self::BRANCH_LOCK, true];
        yield 'only a stability flag, lock on a tag' => ['@dev', self::TAG_LOCK, false];
        yield 'x spelling of any version, lock on a branch' => ['x', self::BRANCH_LOCK, true];
        yield 'x.x spelling of any version, lock on a tag' => ['x.x', self::TAG_LOCK, false];
        yield 'dotted star spelling of any version, lock on a tag' => ['*.*', self::TAG_LOCK, false];
        yield 'a branch or a range, lock on a branch' => ['dev-php8.5 || ^1.0', self::BRANCH_LOCK, true];
        yield 'a branch or a range, lock on a tag' => ['dev-php8.5 || ^1.0', self::TAG_LOCK, false];
        yield 'a branch or a range, single bar, lock on a tag' => ['dev-php8.5 | ^1.0', self::TAG_LOCK, false];
        yield 'a range and a branch, lock on a branch' => ['^1.0 || dev-php8.5', self::BRANCH_LOCK, true];
        yield 'a branch or any version, lock on a tag' => ['dev-php8.5 || *', self::TAG_LOCK, false];
        yield 'a range or any version, lock on a branch' => ['^1.0 || *', self::BRANCH_LOCK, true];
        yield 'not required directly, lock on a branch' => [null, self::BRANCH_LOCK, true];
        yield 'not required directly, lock on a tag' => [null, self::TAG_LOCK, false];
        yield 'not required directly, no lock version' => [null, null, true];
        yield 'any version, no lock version' => ['*', null, true];
        yield 'a range, no lock version' => [self::CARET, null, false];
    }

    #[Test]
    public function theConstraintsAreReadFromRequireAndRequireDev(): void
    {
        $constraints = $this->constraintsOf('{"require": {"acme/one": "^1.0", "php": ">=8.5"}, "require-dev": {"acme/two": "dev-main"}}');

        self::assertSame(['acme/one' => self::CARET, 'php' => '>=8.5', 'acme/two' => 'dev-main'], $constraints);
    }

    #[Test]
    public function aDevConstraintDoesNotShadowAProductionOne(): void
    {
        self::assertSame(['acme/one' => self::CARET], $this->constraintsOf('{"require": {"acme/one": "^1.0"}, "require-dev": {"acme/one": "dev-main"}}'));
    }

    #[Test]
    public function packageNamesAreMatchedWithoutRegardToCase(): void
    {
        self::assertSame(['acme/one' => self::CARET], $this->constraintsOf('{"require": {"Acme/One": "^1.0"}}'));
    }

    #[Test]
    public function entriesThatAreNotNameConstraintPairsAreIgnored(): void
    {
        self::assertSame(['acme/two' => '^2.0'], $this->constraintsOf('{"require": {"acme/one": 1, "acme/two": "^2.0"}, "require-dev": "x"}'));
    }

    #[Test]
    public function aMissingComposerJsonHasNoConstraints(): void
    {
        self::assertSame([], Tracking::constraints($this->dir->path . '/' . self::COMPOSER_JSON));
    }

    #[Test]
    public function aComposerJsonThatIsNotJsonIsRefusedNamingTheFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('composer.json is not valid JSON');

        $this->constraintsOf('{not json');
    }

    #[Test]
    public function aComposerJsonThatIsNotAnObjectIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('composer.json is not a JSON object');

        $this->constraintsOf('"text"');
    }

    /** @return array<array-key, string> */
    private function constraintsOf(string $json): array
    {
        return Tracking::constraints($this->dir->write(self::COMPOSER_JSON, $json));
    }
}
