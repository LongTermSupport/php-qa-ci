<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\PhpLintTool;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PhpLintTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Filesystem\UnresolvablePath::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
#[UsesClass(\LTS\PHPQA\ComposerPlugin\ChildExit::class)]
final class PhpLintToolTest extends TestCase
{
    private const string SRC = '/src';

    private const string TESTS = '/tests';

    private const string EXCLUDE = '--exclude';

    private const string PHP_FILE = "<?php\n";

    private const string REAL = 'src/Real.php';

    private const string MISSING = 'missing-target.php';

    private const string ASSET_KEEP = 'tests/Asset/keep.txt';

    private const string ASSET_DANGLING = '/tests/Asset/Ignored.php';

    private const string SUB_DIR = 'src/a';

    private const string GENERATED = 'generated';

    private const string GENERATED_FILE = 'generated/Out.php';

    private const string DECLARED_REASON = 'generated code kept as it is';

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function aCleanLintPassesAndRunsTheParallelLintPharOverTheCheckedPaths(): void
    {
        $this->factory->processes->willSucceed('No syntax error found');
        $root = $this->factory->project->path;

        $result = new PhpLintTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            ['/usr/bin/php', '-d', 'memory_limit=4G', '-d', 'display_errors=stderr', '-f', $this->factory->context()->config->paths->pharDir . '/parallel-lint.phar', '--', $root . self::TESTS, $root . self::SRC],
            $this->factory->processes->lastSpec()->command,
        );
        self::assertSame($root, $this->factory->processes->lastSpec()->cwd);
        self::assertStringNotContainsString(PhpLintTool::IDENTIFIER, $this->factory->output->fetch());
    }

    #[Test]
    public function eachIgnoredPathBecomesAnExcludeUnderTheProjectRoot(): void
    {
        $this->factory->processes->willSucceed();
        $root   = $this->factory->project->path;
        $config = $this->factory->builder()->withIgnoredPaths('tests/Asset', 'src/Generated')->build();

        new PhpLintTool()->run($this->factory->context($config));

        self::assertSame(
            ['--', self::EXCLUDE, $root . '/tests/Asset', self::EXCLUDE, $root . '/src/Generated', $root . self::TESTS, $root . self::SRC],
            \array_slice($this->factory->processes->lastSpec()->command, 7),
        );
    }

    /** parallel-lint reports a dangling symlink as an unexplained "Unknown error" (#241). */
    #[Test]
    public function aDanglingSymlinkItWouldLintIsNamedAsSkippedAndExcluded(): void
    {
        $this->factory->processes->willSucceed();
        $root = $this->factory->project->path;
        $this->factory->project->write(self::REAL, self::PHP_FILE);
        \Safe\symlink(self::MISSING, $root . '/src/Gone.PHP');
        \Safe\symlink('missing-target.txt', $root . '/src/Gone.txt');
        $this->factory->project->write(self::ASSET_KEEP, '');
        \Safe\symlink(self::MISSING, $root . self::ASSET_DANGLING);
        $config = $this->factory->builder()->withIgnoredPaths('tests/Asset')->build();

        $result = new PhpLintTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            ['--', self::EXCLUDE, $root . '/tests/Asset', self::EXCLUDE, $root . '/src/Gone.PHP', $root . self::TESTS, $root . self::SRC],
            \array_slice($this->factory->processes->lastSpec()->command, 7),
        );
        self::assertSame(
            "PHP Lint skipped src/Gone.PHP: its real path cannot be resolved (a symlink whose target does not exist), so it was not checked\n",
            $this->factory->output->fetch(),
        );
    }

    /**
     * parallel-lint drops a trailing slash from an --exclude (SplFileInfo), so it does not
     * walk an ignored path spelt with one; nor may the lane, or it names a file nobody walks.
     */
    #[Test]
    public function anIgnoredPathWithATrailingSlashIsNotEntered(): void
    {
        $this->factory->processes->willSucceed();
        $root = $this->factory->project->path;
        $this->factory->project->write(self::REAL, self::PHP_FILE);
        $this->factory->project->write(self::ASSET_KEEP, '');
        \Safe\symlink(self::MISSING, $root . self::ASSET_DANGLING);
        $config = $this->factory->builder()->withIgnoredPaths('tests/Asset/')->build();

        $result = new PhpLintTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            ['--', self::EXCLUDE, $root . '/tests/Asset/', $root . self::TESTS, $root . self::SRC],
            \array_slice($this->factory->processes->lastSpec()->command, 7),
        );
        self::assertSame('', $this->factory->output->fetch());
    }

    /**
     * parallel-lint does not collapse a doubled slash in an --exclude, so it walks such an
     * ignored path, and the lane must still exclude the dangling files it finds there.
     */
    #[Test]
    public function anIgnoredPathParallelLintWouldStillWalkHasItsDanglingFilesExcluded(): void
    {
        $this->factory->processes->willSucceed();
        $root = $this->factory->project->path;
        $this->factory->project->write(self::REAL, self::PHP_FILE);
        $this->factory->project->write(self::ASSET_KEEP, '');
        \Safe\symlink(self::MISSING, $root . self::ASSET_DANGLING);
        $config = $this->factory->builder()->withIgnoredPaths('tests//Asset')->build();

        new PhpLintTool()->run($this->factory->context($config));

        self::assertSame(
            ['--', self::EXCLUDE, $root . '/tests//Asset', self::EXCLUDE, $root . self::ASSET_DANGLING, $root . self::TESTS, $root . self::SRC],
            \array_slice($this->factory->processes->lastSpec()->command, 7),
        );
    }

    /**
     * Once its dangling symlinks are excluded, nothing is left for parallel-lint, which
     * then prints "No file found to check" and exits 254; a skipped file must not fail
     * the lane.
     */
    #[Test]
    public function aCheckedDirectoryWhosePhpFilesAreAllDanglingPassesWithoutRunningParallelLint(): void
    {
        $this->factory->processes->willFail(254, 'No file found to check.');
        $root = $this->factory->project->path;
        $this->factory->project->write('src/a/b/notes.txt', '');
        \Safe\symlink(self::MISSING, $root . '/src/a/b/Deep.PHP');
        $config = $this->factory->builder(specifiedPath: self::SUB_DIR)->build();

        $result = new PhpLintTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame([], $this->factory->processes->commandLines());
        self::assertSame(
            "PHP Lint skipped src/a/b/Deep.PHP: its real path cannot be resolved (a symlink whose target does not exist), so it was not checked\n",
            $this->factory->output->fetch(),
        );
    }

    /** An empty checked directory is parallel-lint's to judge: it is still run over it. */
    #[Test]
    public function aCheckedDirectoryWithNoPhpFilesAtAllIsStillGivenToParallelLint(): void
    {
        $this->factory->processes->willFail(254, 'No file found to check.');
        $root = $this->factory->project->path;
        $this->factory->project->write('src/a/notes.txt', '');
        $config = $this->factory->builder(specifiedPath: self::SUB_DIR)->build();

        $result = new PhpLintTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('PHP Lint failed (exit 254)', $result->summary);
        self::assertSame(['--', $root . '/src/a'], \array_slice($this->factory->processes->lastSpec()->command, 7));
    }

    #[Test]
    public function aDanglingSymlinkBesideARealPhpFileStillRunsParallelLintOverTheRealOne(): void
    {
        $this->factory->processes->willSucceed();
        $root = $this->factory->project->path;
        $this->factory->project->write('src/a/Real.php', self::PHP_FILE);
        \Safe\symlink(self::MISSING, $root . '/src/a/Gone.php');
        $config = $this->factory->builder(specifiedPath: self::SUB_DIR)->build();

        $result = new PhpLintTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            ['--', self::EXCLUDE, $root . '/src/a/Gone.php', $root . '/src/a'],
            \array_slice($this->factory->processes->lastSpec()->command, 7),
        );
    }

    /**
     * A path declared unanalysed is excluded from style and static analysis, not from the syntax
     * check: generated code that does not parse is a defect (#414).
     */
    #[Test]
    public function aPathDeclaredUnanalysedIsLintedAfterTheCheckedPaths(): void
    {
        $this->factory->processes->willSucceed();
        $root = $this->factory->project->path;
        $this->factory->project->write(self::GENERATED_FILE, self::PHP_FILE);
        $config = $this->factory->builder()->withUnanalysedPath(self::GENERATED, self::DECLARED_REASON)->build();

        $result = new PhpLintTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            ['--', $root . self::TESTS, $root . self::SRC, $root . '/' . self::GENERATED],
            \array_slice($this->factory->processes->lastSpec()->command, 7),
        );
    }

    #[Test]
    public function aDeclaredFileIsLintedNotOnlyADirectory(): void
    {
        $this->factory->processes->willSucceed();
        $root = $this->factory->project->path;
        $this->factory->project->write('bin/bootstrap.php', self::PHP_FILE);
        $config = $this->factory->builder()->withUnanalysedPath('bin/bootstrap.php', self::DECLARED_REASON)->build();

        new PhpLintTool()->run($this->factory->context($config));

        self::assertSame(
            ['--', $root . self::TESTS, $root . self::SRC, $root . '/bin/bootstrap.php'],
            \array_slice($this->factory->processes->lastSpec()->command, 7),
        );
    }

    /** A path made of digits is an int array key in the declarations; it is still a path. */
    #[Test]
    public function aDeclaredPathNamedInDigitsIsLinted(): void
    {
        $this->factory->processes->willSucceed();
        $root = $this->factory->project->path;
        $this->factory->project->write('2024/Out.php', self::PHP_FILE);
        $config = $this->factory->builder()->withUnanalysedPath('2024', self::DECLARED_REASON)->build();

        new PhpLintTool()->run($this->factory->context($config));

        self::assertSame(
            ['--', $root . self::TESTS, $root . self::SRC, $root . '/2024'],
            \array_slice($this->factory->processes->lastSpec()->command, 7),
        );
    }

    /** An ignored path inside a declared one stays excluded: a deliberately broken file belongs there. */
    #[Test]
    public function anIgnoredPathInsideADeclaredPathStaysExcluded(): void
    {
        $this->factory->processes->willSucceed();
        $root = $this->factory->project->path;
        $this->factory->project->write(self::GENERATED_FILE, self::PHP_FILE);
        $config = $this->factory->builder()
            ->withIgnoredPaths('generated/broken')
            ->withUnanalysedPath(self::GENERATED, self::DECLARED_REASON)
            ->build();

        new PhpLintTool()->run($this->factory->context($config));

        self::assertSame(
            ['--', self::EXCLUDE, $root . '/generated/broken', $root . self::TESTS, $root . self::SRC, $root . '/' . self::GENERATED],
            \array_slice($this->factory->processes->lastSpec()->command, 7),
        );
    }

    /** A declared path that is also ignored is excluded, not given to parallel-lint to walk and skip. */
    #[Test]
    public function aDeclaredPathThatIsAlsoIgnoredIsNotLinted(): void
    {
        $this->factory->processes->willSucceed();
        $root = $this->factory->project->path;
        $this->factory->project->write(self::GENERATED_FILE, self::PHP_FILE);
        $config = $this->factory->builder()
            ->withIgnoredPaths(self::GENERATED)
            ->withUnanalysedPath(self::GENERATED, self::DECLARED_REASON)
            ->build();

        new PhpLintTool()->run($this->factory->context($config));

        self::assertSame(
            ['--', self::EXCLUDE, $root . '/' . self::GENERATED, $root . self::TESTS, $root . self::SRC],
            \array_slice($this->factory->processes->lastSpec()->command, 7),
        );
    }

    /** A declaration naming nothing is the analysedPaths lane's to report; parallel-lint fails on a path that is not there. */
    #[Test]
    public function aDeclaredPathThatDoesNotExistIsNotGivenToParallelLint(): void
    {
        $this->factory->processes->willSucceed();
        $root   = $this->factory->project->path;
        $config = $this->factory->builder()->withUnanalysedPath('not-there', self::DECLARED_REASON)->build();

        new PhpLintTool()->run($this->factory->context($config));

        self::assertSame(
            ['--', $root . self::TESTS, $root . self::SRC],
            \array_slice($this->factory->processes->lastSpec()->command, 7),
        );
    }

    /** A run narrowed with -p lints that path alone, as every lane does. */
    #[Test]
    public function aRunNarrowedToOnePathDoesNotAddTheDeclaredPaths(): void
    {
        $this->factory->processes->willSucceed();
        $root = $this->factory->project->path;
        $this->factory->project->write('src/a/Real.php', self::PHP_FILE);
        $this->factory->project->write(self::GENERATED_FILE, self::PHP_FILE);
        $config = $this->factory->builder(specifiedPath: self::SUB_DIR)->withUnanalysedPath(self::GENERATED, self::DECLARED_REASON)->build();

        new PhpLintTool()->run($this->factory->context($config));

        self::assertSame(['--', $root . '/src/a'], \array_slice($this->factory->processes->lastSpec()->command, 7));
    }

    /** The dangling-symlink handling reaches the declared paths too (#241). */
    #[Test]
    public function aDanglingSymlinkUnderADeclaredPathIsNamedAsSkippedAndExcluded(): void
    {
        $this->factory->processes->willSucceed();
        $root = $this->factory->project->path;
        $this->factory->project->write(self::REAL, self::PHP_FILE);
        $this->factory->project->write(self::GENERATED_FILE, self::PHP_FILE);
        \Safe\symlink(self::MISSING, $root . '/generated/Gone.php');
        $config = $this->factory->builder()->withUnanalysedPath(self::GENERATED, self::DECLARED_REASON)->build();

        new PhpLintTool()->run($this->factory->context($config));

        self::assertSame(
            ['--', self::EXCLUDE, $root . '/generated/Gone.php', $root . self::TESTS, $root . self::SRC, $root . '/' . self::GENERATED],
            \array_slice($this->factory->processes->lastSpec()->command, 7),
        );
        self::assertSame(
            "PHP Lint skipped generated/Gone.php: its real path cannot be resolved (a symlink whose target does not exist), so it was not checked\n",
            $this->factory->output->fetch(),
        );
    }

    #[Test]
    public function aNonZeroExitFailsWithTheIdentifierTrailer(): void
    {
        $this->factory->processes->willFail(1, 'Parse error: syntax error');

        $result = new PhpLintTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('PHP Lint failed (exit 1)', $result->summary);
        self::assertStringContainsString(PhpLintTool::IDENTIFIER, $this->factory->output->fetch());
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new PhpLintTool();

        self::assertSame('phpLint', $tool->name());
        self::assertSame('phpqaci.phpLint', $tool->identifier());
    }
}
