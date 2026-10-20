<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Config\ConfigPathResolver;
use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Pipeline\Lane\PhpCsFixerTool;
use LTS\PHPQA\Pipeline\Process\LogArchiver;
use LTS\PHPQA\Pipeline\Process\PhpInvoker;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\GitSandbox;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/**
 * Defence for the class "a file the config's finder yields whose real path cannot be
 * resolved crashes the fixer" (#239). With `--path-mode=intersection` PHP CS Fixer filters
 * the finder by `str_starts_with($file->getRealPath(), $dir)`, and a dangling symlink's real
 * path is false, so the whole lane died with a TypeError (exit 255) and named no file.
 * The shipped finder's `ignoreVCSIgnored(true)` does not save a gitignored one: Symfony
 * Finder judges ignoredness by the real path too, and false never matches a pattern.
 *
 * Each test runs the real lane over a git probe project holding a dangling `*.php` symlink
 * in a tracked directory and another in a gitignored one, beside a real file with a pending
 * fix, read-only and writable, with and without ignored paths configured. The lane must not
 * crash, must name each dangling symlink it skipped, and must still check the real file.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class PhpCsFixerToolUnresolvablePathTest extends TestCase
{
    /** A real file with a fix pending. */
    private const string DIRTY = 'src/Dirty.php';

    /** A dangling symlink in a tracked directory. */
    private const string TRACKED_DANGLING = 'src/Gone.php';

    /** A dangling symlink in a gitignored directory. */
    private const string IGNORED_DANGLING = 'untracked/scratch/Gone.php';

    /** A real file with a fix pending, under the path withIgnoredPaths() names. */
    private const string LEGACY = 'legacy/Old.php';

    /** The real file's content, with a brace layout the shipped ruleset rewrites. */
    private const string DIRTY_SOURCE = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe;\n\nfinal class Dirty\n{\n    public function value(): int { return 1; }\n}\n";

    private TempDir $project;

    private string $library;

    protected function setUp(): void
    {
        $this->library = \dirname(__DIR__, 4);
        $this->project = TempDir::create('phpqa-fixer-dangling');
        $this->project->write('composer.json', '{"name": "probe/probe", "type": "project", "autoload": {"psr-4": {"Probe\\\": "src/"}}}');
        $this->project->write('.gitignore', "/untracked/\n/var/\n");
        $this->project->write(self::DIRTY, self::DIRTY_SOURCE);
        $this->project->write('tests/ProbeTest.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe\\Tests;\n\nfinal class ProbeTest\n{\n}\n");
        $this->project->write(self::LEGACY, str_replace('Dirty', 'Old', self::DIRTY_SOURCE));
        $this->project->mkdir('untracked/scratch');
        $this->project->mkdir('var/qa/cache');
        \Safe\symlink('missing-target.php', $this->project->path . '/' . self::TRACKED_DANGLING);
        \Safe\symlink('missing-target.php', $this->project->path . '/' . self::IGNORED_DANGLING);
        $this->git('init');
        $this->git('add', '-A');
        $this->git('commit', '-m', 'Probe');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function theProbeProjectTracksOneDanglingSymlinkAndIgnoresTheOther(): void
    {
        foreach ([self::TRACKED_DANGLING, self::IGNORED_DANGLING] as $dangling) {
            self::assertTrue(is_link($this->project->path . '/' . $dangling));
            self::assertFileDoesNotExist($this->project->path . '/' . $dangling);
        }

        self::assertSame(self::TRACKED_DANGLING . "\n", $this->git('ls-files', self::TRACKED_DANGLING));
        self::assertSame(self::IGNORED_DANGLING . "\n", $this->git('check-ignore', self::IGNORED_DANGLING));
    }

    #[Test]
    #[DataProvider('modes')]
    public function theLaneSkipsEachDanglingSymlinkByNameAndStillChecksTheRealFiles(bool $readOnly, bool $ignoredPaths): void
    {
        $output = new BufferedOutput();
        $result = new PhpCsFixerTool()->run($this->context($readOnly, $ignoredPaths, $output));
        $text   = $output->fetch();

        self::assertNotSame(ToolOutcomeEnum::Crashed, $result->outcome, "the lane crashed on a dangling symlink:\n" . $text);
        self::assertStringContainsString($this->skipped(self::TRACKED_DANGLING), $text, 'the lane did not name the dangling symlink in a tracked directory');
        self::assertStringContainsString($this->skipped(self::IGNORED_DANGLING), $text, 'the lane did not name the dangling symlink in a gitignored directory');
        self::assertSame(1, substr_count($text, $this->skipped(self::TRACKED_DANGLING)), 'each skipped file is named once per lane run');

        if ($readOnly) {
            self::assertSame(ToolOutcomeEnum::Failed, $result->outcome, $text);
            self::assertStringContainsString(self::DIRTY, $text, 'the real file with a pending fix was not checked');
            self::assertSame(self::DIRTY_SOURCE, $this->project->read(self::DIRTY));
        } else {
            self::assertSame(ToolOutcomeEnum::Passed, $result->outcome, $text);
            self::assertNotSame(self::DIRTY_SOURCE, $this->project->read(self::DIRTY), 'the real file with a pending fix was not fixed');
        }

        if ($ignoredPaths) {
            self::assertStringNotContainsString(self::LEGACY, $text, 'an ignored path was checked');
            self::assertSame(str_replace('Dirty', 'Old', self::DIRTY_SOURCE), $this->project->read(self::LEGACY));
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function modes(): iterable
    {
        yield 'read-only, no ignored paths' => [true, false];
        yield 'read-only, ignored paths' => [true, true];
        yield 'writable, no ignored paths' => [false, false];
        yield 'writable, ignored paths' => [false, true];
    }

    /** How the lane names a file it skipped. */
    private function skipped(string $file): string
    {
        return 'skipped ' . $file . ':';
    }

    private function context(bool $readOnly, bool $ignoredPaths, BufferedOutput $output): ToolContext
    {
        $root  = $this->project->path;
        $paths = new ProjectPathsDto(
            projectRoot: $root,
            libraryRoot: $this->library,
            binDir: $root . '/vendor/bin',
            srcDir: $root . '/src',
            testsDir: $root . '/tests',
            projectConfigDir: $root . '/qaConfig',
            varDir: $root . '/var/qa',
            cacheDir: $root . '/var/qa/cache',
            pharDir: $this->library . '/vendor-phar',
            configDefaultsDir: $this->library . '/configDefaults',
        );
        $builder = QaConfigBuilder::defaults(
            paths: $paths,
            platform: PlatformEnum::Generic,
            env: new EnvironmentReader([]),
            phpBinPath: \PHP_BINARY,
            xdebugEnabled: false,
            halfCpuThreads: 1,
            ci: false,
            readOnly: $readOnly,
            aggregate: true,
            jsonOutput: false,
            agentMode: false,
            singleTool: null,
            specifiedPath: null,
        )->withCheckedPaths('legacy');
        $config    = ($ignoredPaths ? $builder->withIgnoredPaths('legacy') : $builder)->build();
        $processes = new SymfonyProcessRunner($output);

        return new ToolContext(
            config: $config,
            configPaths: new ConfigPathResolver($paths->projectConfigDir, $paths->configDefaultsDir, $config->platform),
            processes: $processes,
            php: new PhpInvoker($processes, $config->phpBinPath, $config->memoryLimit, $paths->varDir),
            logs: new LogArchiver($output),
            output: $output,
            stdout: $output,
        );
    }

    private function git(string ...$args): string
    {
        $process = new Process(['git', ...array_values($args)], $this->project->path, GitSandbox::environment());
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException(\sprintf('git %s failed: %s', implode(' ', $args), $process->getErrorOutput()));
        }

        return $process->getOutput();
    }
}
