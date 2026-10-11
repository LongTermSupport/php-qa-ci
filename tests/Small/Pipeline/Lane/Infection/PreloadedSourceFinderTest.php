<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Infection;

use InvalidArgumentException;
use LTS\PHPQA\Pipeline\Lane\Infection\PreloadedSourceFinder;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which of the files already loaded when PHPUnit's bootstrap starts are files
 * Infection would mutate: under a source directory, named `*.php`, not
 * excluded the way Infection's Finder excludes, and in a diff run's scope.
 *
 * @internal
 */
#[CoversClass(PreloadedSourceFinder::class)]
#[Small]
final class PreloadedSourceFinderTest extends TestCase
{
    private const string LEGACY = 'Legacy';

    private TempDir $project;

    private string $src;

    private string $tree;

    private string $old;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-preloaded');
        foreach (['src/Process/Tree.php', 'src/Legacy/Old.php', 'src/Domain/Legacy/Kept.php', 'src/ComposerPlugin/Plugin.php', 'src/view.phtml', 'src2/Other.php', 'vendor/autoload.php', 'lib/Extra.php'] as $file) {
            $this->project->write($file, '<?php');
        }

        $this->src  = $this->project->path . '/src';
        $this->tree = $this->src . '/Process/Tree.php';
        $this->old  = $this->src . '/Legacy/Old.php';
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function aLoadedFileUnderASourceDirectoryIsFound(): void
    {
        self::assertSame(
            [$this->tree],
            $this->found([$this->src], [], [], $this->project->path . '/vendor/autoload.php', $this->tree),
        );
    }

    #[Test]
    public function nothingLoadedFromTheSourceIsNothingFound(): void
    {
        self::assertSame([], $this->found([$this->src], [], [], $this->project->path . '/vendor/autoload.php'));
    }

    /** `src2/` starts with the characters of `src` and is not under it. */
    #[Test]
    public function aSiblingDirectorySharingThePrefixIsNotUnderIt(): void
    {
        self::assertSame([], $this->found([$this->src], [], [], $this->project->path . '/src2/Other.php'));
    }

    /** Infection collects `*.php` only, so a template a files entry includes is never mutated. */
    #[Test]
    public function aFileNotNamedDotPhpIsNotMutatedSoNotFound(): void
    {
        self::assertSame([], $this->found([$this->src], [], [], $this->src . '/view.phtml'));
    }

    #[Test]
    public function everySourceDirectoryIsSearched(): void
    {
        $lib = $this->project->path . '/lib';

        self::assertSame(
            [$lib . '/Extra.php', $this->tree],
            $this->found([$this->src, $lib], [], [], $lib . '/Extra.php', $this->tree),
        );
    }

    /** A source directory written with `..` or a trailing slash is the same directory. */
    #[Test]
    public function aSourceDirectoryIsComparedByItsRealPath(): void
    {
        self::assertSame(
            [$this->tree],
            $this->found([$this->project->path . '/src2/../src/'], [], [], $this->tree),
        );
    }

    /** A source directory that does not exist is compared as written, and holds nothing. */
    #[Test]
    public function aMissingSourceDirectoryHoldsNothing(): void
    {
        self::assertSame([], $this->found([$this->project->path . '/gone'], [], [], $this->tree));
    }

    /** A file under two nested source directories is still one file. */
    #[Test]
    public function aFileUnderNestedSourceDirectoriesIsNamedOnce(): void
    {
        self::assertSame([$this->tree], $this->found([$this->src, $this->src . '/Process'], [], [], $this->tree));
    }

    /** The filesystem root is a directory whose real path already ends in a slash. */
    #[Test]
    public function theFilesystemRootHoldsEveryFile(): void
    {
        self::assertSame([$this->tree], $this->found(['/'], [], [], $this->tree));
    }

    #[Test]
    public function aFileLoadedTwiceIsNamedOnce(): void
    {
        self::assertSame(
            [$this->tree],
            $this->found([$this->src], [], [], $this->tree, $this->tree),
        );
    }

    /**
     * Infection hands each exclude to Symfony Finder's notPath(): a plain one
     * excludes every path, relative to the source directory, that contains it.
     *
     * @param list<string> $excludes
     */
    #[Test]
    #[DataProvider('excludesThatDropTheLegacyFile')]
    public function anExcludedFileIsNotMutatedSoNotFound(array $excludes): void
    {
        self::assertSame(
            [$this->tree],
            $this->found([$this->src], $excludes, [], $this->old, $this->tree),
        );
    }

    /** @return iterable<string, array{list<string>}> */
    public static function excludesThatDropTheLegacyFile(): iterable
    {
        yield 'a directory name' => [[self::LEGACY]];
        yield 'a path' => [['Legacy/Old.php']];
        yield 'a substring of a name' => [['egac']];
        yield 'a regex anchored at the source directory' => [['#^Legacy(?:/|$)#']];
        yield 'a slash-delimited regex' => [['/Legacy/']];
        yield 'a regex with a modifier' => [['/^legacy/i']];
        yield 'a regex in braces' => [['{^Legacy/}']];
        yield 'among others' => [['Nothing', self::LEGACY]];
        yield 'a regex of three characters' => [['/O/']];
        yield 'a one-character regex with a modifier' => [['/l/i']];
    }

    /** The anchored regex the lane writes for an ignored `src/Legacy` keeps `src/Domain/Legacy`. */
    #[Test]
    public function anAnchoredRegexKeepsADeeperDirectoryOfTheSameName(): void
    {
        self::assertSame(
            [$this->src . '/Domain/Legacy/Kept.php'],
            $this->found([$this->src], ['#^Legacy(?:/|$)#'], [], $this->old, $this->src . '/Domain/Legacy/Kept.php'),
        );
    }

    /** php-qa-ci's own `/ComposerPlugin/` is a regex, as Finder reads it. */
    #[Test]
    public function thisRepositorysOwnExcludeDropsTheComposerPlugin(): void
    {
        self::assertSame(
            [],
            $this->found([$this->src], ['/ComposerPlugin/'], [], $this->src . '/ComposerPlugin/Plugin.php'),
        );
    }

    /**
     * A string whose first and last characters match is a regex only when that
     * character is not alphanumeric, a space, a backslash, `*` or `?`: Finder
     * reads `aLegacya` and `*Legacy*` as plain text, which no path contains.
     */
    #[Test]
    #[DataProvider('plainStringsThatLookDelimited')]
    public function aStringThatOnlyLooksDelimitedIsPlainText(string $exclude): void
    {
        self::assertSame(
            [$this->old],
            $this->found([$this->src], [$exclude], [], $this->old),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function plainStringsThatLookDelimited(): iterable
    {
        yield 'letters' => ['aLegacya'];
        yield 'digits' => ['1Legacy1'];
        yield 'stars' => ['*Legacy*'];
        yield 'question marks' => ['?Legacy?'];
        yield 'spaces' => [' Legacy '];
        yield 'backslashes' => ['\Legacy\\'];
        yield 'too short to delimit anything' => ['//'];
        yield 'two delimiters and a modifier, too short a body' => ['//i'];
        yield 'mismatched brackets' => ['{Legacy)'];
        yield 'an unknown modifier' => ['/Legacy/q'];
    }

    /** A regex delimited by each bracket pair Finder accepts. */
    #[Test]
    #[DataProvider('bracketDelimitedRegexes')]
    public function eachBracketPairDelimitsARegex(string $exclude): void
    {
        self::assertSame([], $this->found([$this->src], [$exclude], [], $this->old));
    }

    /** @return iterable<string, array{string}> */
    public static function bracketDelimitedRegexes(): iterable
    {
        yield 'braces' => ['{^Legacy/}'];
        yield 'parentheses' => ['(^Legacy/)'];
        yield 'square brackets' => ['[^Legacy/]'];
        yield 'angle brackets' => ['<^Legacy/>'];
        yield 'every modifier' => ['/^legacy/imsxuADUn'];
    }

    #[Test]
    public function anExcludeThatIsNotAValidRegexIsRefusedByName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('source.excludes entry "/(Legacy/" is not a valid regular expression');

        $this->found([$this->src], ['/(Legacy/'], [], $this->old);
    }

    /** A diff run mutates only the files it passes Infection. */
    #[Test]
    public function aDiffRunsScopeLimitsWhatIsMutated(): void
    {
        self::assertSame(
            [$this->tree],
            $this->found([$this->src], [], [$this->src . '/Process/../Process/Tree.php'], $this->old, $this->tree),
        );
    }

    /** A scoped file still has to be under a source directory and not excluded, as Infection filters both. */
    #[Test]
    public function aScopedFileOutsideTheSourceOrExcludedIsNotMutated(): void
    {
        self::assertSame(
            [],
            $this->found([$this->src], [self::LEGACY], [$this->old, $this->project->path . '/lib/Extra.php'], $this->old, $this->project->path . '/lib/Extra.php'),
        );
    }

    /** A scoped path that does not exist is compared as written. */
    #[Test]
    public function aMissingScopedFileIsComparedAsWritten(): void
    {
        self::assertSame([], $this->found([$this->src], [], [$this->src . '/Gone.php'], $this->tree));
    }

    /**
     * The finder's answer, with the scope before the loaded files as the cases read best.
     *
     * @param list<string> $sourceDirectories
     * @param list<string> $excludes
     * @param list<string> $scope
     *
     * @return list<string>
     */
    private function found(array $sourceDirectories, array $excludes, array $scope, string ...$loaded): array
    {
        return new PreloadedSourceFinder()->find($sourceDirectories, $excludes, array_values($loaded), ...$scope);
    }
}
