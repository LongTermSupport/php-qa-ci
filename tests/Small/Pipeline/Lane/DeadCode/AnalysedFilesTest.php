<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\DeadCode;

use LTS\PHPQA\Pipeline\Lane\DeadCode\AnalysedFiles;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which files a PHPStan run analyses, given its merged `paths`, `excludePaths` and
 * `fileExtensions`, judged as PHPStan's FileFinder, DirectoryWalker and FileExcluder judge them.
 *
 * @internal
 */
#[CoversClass(AnalysedFiles::class)]
#[Small]
final class AnalysedFilesTest extends TestCase
{
    private const string APP = 'src/app.php';

    private const string SRC = 'src';

    private const string CONSOLE = 'bin/console';

    private const string PHP = 'php';

    private const string LEGACY = 'src/legacy';

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-analysed-files-test');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /** A listed file is analysed whatever its name, through any spelling of its path. */
    #[Test]
    public function aListedFileIsAnalysedWhateverItsSuffix(): void
    {
        $this->write(self::CONSOLE);

        self::assertTrue($this->files([$this->at('bin/../bin/console')])->analyses($this->real(self::CONSOLE)));
    }

    /**
     * @param list<string> $extensions
     */
    #[Test]
    #[DataProvider('scannedFiles')]
    public function aFileInAListedDirectoryIsAnalysedByItsSuffix(string $file, array $extensions, bool $analysed): void
    {
        $this->write($file);

        self::assertSame($analysed, $this->files([$this->at(self::SRC)], [], $extensions)->analyses($this->real($file)));
    }

    /** @return iterable<string, array{string, list<string>, bool}> */
    public static function scannedFiles(): iterable
    {
        yield 'a .php file' => [self::APP, [self::PHP], true];
        yield 'a .php file two levels down' => ['src/Deep/Er/app.php', [self::PHP], true];
        yield 'an extensionless file' => ['src/app', [self::PHP], false];
        yield 'a suffix the project adds' => ['src/app.inc', [self::PHP, 'inc'], true];
        yield 'a suffix not listed' => ['src/app.inc', [self::PHP], false];
        yield 'a suffix in another case' => ['src/app.PHP', [self::PHP], false];
        yield 'a dot file' => ['src/.app.php', [self::PHP], false];
        yield 'a file in a dot directory' => ['src/.hidden/app.php', [self::PHP], false];
        yield 'a file in a VCS directory' => ['src/CVS/app.php', [self::PHP], false];
    }

    #[Test]
    public function aFileOutsideEveryPathIsNotAnalysed(): void
    {
        $this->write('srcExtra/app.php');
        $this->project->mkdir(self::SRC);

        self::assertFalse($this->files([$this->at(self::SRC)])->analyses($this->real('srcExtra/app.php')));
    }

    /**
     * @param list<string> $excluded project-relative
     */
    #[Test]
    #[DataProvider('exclusions')]
    public function anExcludedFileIsNotAnalysed(string $listed, array $excluded): void
    {
        $this->write(self::APP);
        $this->project->mkdir(self::LEGACY);

        self::assertFalse($this->files([$this->at($listed)], array_map($this->at(...), $excluded))->analyses($this->real(self::APP)));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function exclusions(): iterable
    {
        yield 'a scanned file excluded by name' => [self::SRC, [self::APP]];
        yield 'a scanned file in an excluded directory' => ['.', [self::SRC]];
        yield 'a scanned file in an excluded directory with a trailing slash' => ['.', ['src/']];
        yield 'a scanned file excluded by a pattern' => [self::SRC, ['src/*.php']];
        yield 'a listed file excluded by a pattern' => [self::APP, ['*/app.php']];
        yield 'a listed file excluded by name through another spelling' => [self::APP, ['src/../src/app.php']];
    }

    /** PHPStan drops a literal exclusion that names nothing; a directory beside the file's is not its own. */
    #[Test]
    #[DataProvider('exclusionsThatMissTheFile')]
    public function anExclusionThatNamesSomethingElseLeavesTheFileAnalysed(string $excluded): void
    {
        $this->write(self::APP);
        $this->project->mkdir(self::LEGACY);
        $this->project->mkdir('sr');

        self::assertTrue($this->files([$this->at(self::SRC)], [$this->at($excluded)])->analyses($this->real(self::APP)));
    }

    /** @return iterable<string, array{string}> */
    public static function exclusionsThatMissTheFile(): iterable
    {
        yield 'a path that does not exist' => ['src/app.php.gone'];
        yield 'another directory' => [self::LEGACY];
        yield 'a directory whose name the file path starts with' => ['sr'];
        yield 'a pattern matching other files' => ['src/*.inc'];
    }

    /** A listed directory reached through a link is walked, and a file in it judged by its real path. */
    #[Test]
    public function aListedDirectoryThatIsALinkIsWalked(): void
    {
        $this->write('lib/app.php');
        \Safe\symlink('lib', $this->project->path . '/' . self::SRC);

        self::assertTrue($this->files([$this->at(self::SRC)])->analyses($this->real('lib/app.php')));
    }

    #[Test]
    public function aListedPathThatDoesNotExistAnalysesNothing(): void
    {
        $this->write(self::APP);

        self::assertFalse($this->files([$this->at('gone'), $this->at('src/gone.php')])->analyses($this->real(self::APP)));
    }

    /**
     * @param list<string> $paths
     * @param list<string> $excluded
     * @param list<string> $extensions
     */
    private function files(array $paths, array $excluded = [], array $extensions = [self::PHP]): AnalysedFiles
    {
        return new AnalysedFiles($paths, $excluded, $extensions);
    }

    private function write(string $relative): void
    {
        $this->project->write($relative, '<?php');
    }

    private function at(string $relative): string
    {
        return $this->project->path . '/' . $relative;
    }

    private function real(string $relative): string
    {
        return \Safe\realpath($this->at($relative));
    }
}
