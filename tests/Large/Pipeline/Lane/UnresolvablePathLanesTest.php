<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Config\ConfigPathResolver;
use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Pipeline\Lane\DocsProseTool;
use LTS\PHPQA\Pipeline\Lane\MarkdownLinksTool;
use LTS\PHPQA\Pipeline\Lane\PhpArkitectTool;
use LTS\PHPQA\Pipeline\Lane\PhpLintTool;
use LTS\PHPQA\Pipeline\Lane\Psr4ValidateTool;
use LTS\PHPQA\Pipeline\Process\LogArchiver;
use LTS\PHPQA\Pipeline\Process\PhpInvoker;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\GitSandbox;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Defence for the class "a file a lane walks to whose real path cannot be resolved
 * crashes the lane, or fails it, without naming the file" (#241, the sibling of #239's
 * phpCsFixer defect). A dangling symlink is ordinary in a consumer checkout, and a lane
 * that dies on one reports nothing about the code it was asked to check.
 *
 * Each test runs one real lane over a git probe project holding a dangling symlink of
 * the lane's file type in a directory the lane checks, beside a real file with a genuine
 * finding. The lane must not crash or throw, must name the dangling symlink as skipped
 * exactly once, and must still report the real finding.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class UnresolvablePathLanesTest extends TestCase
{
    /** A dangling PHP symlink in the PSR-4 root, the source dir and a checked path. */
    private const string DANGLING_PHP = 'src/Gone.php';

    /** A dangling markdown symlink in the docs tree. */
    private const string DANGLING_MD = 'docs/Gone.md';

    /** What a lane prints after naming a file it skipped. */
    private const string SKIPPED_REASON = ': its real path cannot be resolved (a symlink whose target does not exist), so it was not checked';

    private TempDir $project;

    private string $library;

    protected function setUp(): void
    {
        $this->library = \dirname(__DIR__, 4);
        $this->project = TempDir::create('phpqa-dangling-lanes');
        $this->project->write('composer.json', '{"name": "probe/probe", "type": "project", "autoload": {"psr-4": {"Probe\\\": "src/"}}}');
        $this->project->write('.gitignore', "/var/\n");
        $this->project->write('vendor/autoload.php', "<?php\n\ndeclare(strict_types=1);\n");
        $this->project->write('README.md', "# Probe\n\nSee [the guide](docs/Guide.md).\n");
        // A broken relative link (markdownLinks) and a sentence about the page itself (docsProse).
        $this->project->write('docs/Guide.md', "# Guide\n\nRead [the missing page](Missing.md).\n\nThis section has been moved to another page.\n");
        // A namespace that does not match its directory (psr4Validate).
        $this->project->write('src/Sub/Misplaced.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe\\Elsewhere;\n\nfinal class Misplaced\n{\n}\n");
        // An interface without the Interface suffix (phpArkitect's default tier).
        $this->project->write('src/Repository.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe;\n\ninterface Repository\n{\n}\n");
        // A syntax error (phpLint).
        $this->project->write('tests/Broken.php', "<?php\n\nfunction (\n");
        $this->project->mkdir('var/qa/cache');
        \Safe\symlink('missing-target.php', $this->project->path . '/' . self::DANGLING_PHP);
        \Safe\symlink('missing-target.md', $this->project->path . '/' . self::DANGLING_MD);
        $this->git('init');
        $this->git('add', '-A');
        $this->git('commit', '-m', 'Probe');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function theProbeProjectTracksADanglingSymlinkOfEachFileType(): void
    {
        foreach ([self::DANGLING_PHP, self::DANGLING_MD] as $dangling) {
            self::assertTrue(is_link($this->project->path . '/' . $dangling));
            self::assertFileDoesNotExist($this->project->path . '/' . $dangling);
            self::assertSame($dangling . "\n", $this->git('ls-files', $dangling));
        }
    }

    #[Test]
    public function psr4ValidateSkipsTheDanglingSymlinkByNameAndStillChecksTheRealFiles(): void
    {
        $this->assertSkipsByNameAndStillChecks(new Psr4ValidateTool(), self::DANGLING_PHP, 'src/Sub/Misplaced.php');
    }

    #[Test]
    public function markdownLinksSkipsTheDanglingSymlinkByNameAndStillChecksTheRealFiles(): void
    {
        $this->assertSkipsByNameAndStillChecks(new MarkdownLinksTool(), self::DANGLING_MD, 'Missing.md');
    }

    #[Test]
    public function docsProseSkipsTheDanglingSymlinkByNameAndStillChecksTheRealFiles(): void
    {
        $this->assertSkipsByNameAndStillChecks(new DocsProseTool(), self::DANGLING_MD, 'docs/Guide.md:5');
    }

    #[Test]
    public function phpLintSkipsTheDanglingSymlinkByNameAndStillChecksTheRealFiles(): void
    {
        $text = $this->assertSkipsByNameAndStillChecks(new PhpLintTool(), self::DANGLING_PHP, 'tests/Broken.php');

        self::assertStringNotContainsString('Unknown error for file', $text, 'parallel-lint was still given the dangling symlink');
    }

    #[Test]
    public function phpArkitectSkipsTheDanglingSymlinkByNameAndStillChecksTheRealFiles(): void
    {
        $this->assertSkipsByNameAndStillChecks(new PhpArkitectTool(), self::DANGLING_PHP, 'Probe\Repository');
    }

    /**
     * The lane ran to a verdict, the verdict is the real file's finding, and the dangling
     * symlink is named once as skipped.
     */
    private function assertSkipsByNameAndStillChecks(ToolInterface $tool, string $dangling, string $finding): string
    {
        $output = new BufferedOutput();

        try {
            $result = $tool->run($this->context($output));
        } catch (Throwable $throwable) {
            self::fail(\sprintf("%s threw on a dangling symlink: %s: %s\n%s", $tool->name(), $throwable::class, $throwable->getMessage(), $output->fetch()));
        }

        $text = $output->fetch();

        self::assertNotSame(ToolOutcomeEnum::Crashed, $result->outcome, $tool->name() . " crashed on a dangling symlink:\n" . $text);
        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome, $tool->name() . " did not report the real file's finding:\n" . $text);
        self::assertStringContainsString($finding, $text, $tool->name() . ' did not check the real file');
        self::assertSame(
            1,
            substr_count($text, 'skipped ' . $dangling . self::SKIPPED_REASON),
            $tool->name() . " did not name the dangling symlink as skipped exactly once:\n" . $text,
        );

        return $text;
    }

    private function context(BufferedOutput $output): ToolContext
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
        $config = QaConfigBuilder::defaults(
            paths: $paths,
            platform: PlatformEnum::Generic,
            env: new EnvironmentReader([]),
            phpBinPath: \PHP_BINARY,
            xdebugEnabled: false,
            halfCpuThreads: 1,
            ci: false,
            readOnly: true,
            aggregate: true,
            jsonOutput: false,
            agentMode: false,
            singleTool: null,
            specifiedPath: null,
        )->build();
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
