<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Config\ShellCheckBinary;
use LTS\PHPQA\Pipeline\Lane\ShellCheckTool;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use LTS\PHPQA\Tests\Support\FakeProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ShellCheckTool::class)]
#[UsesClass(ShellCheckBinary::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ShellCheck\ShellFileFinder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[Small]
final class ShellCheckToolTest extends TestCase
{
    private const string BANNER = "ShellCheck - shell script analysis tool\nversion: 0.11.0\n";

    private const string BUILD = 'scripts/build.bash';

    private const string CI_BASH = 'ci.bash';

    private const string TRACKED = "scripts/build.bash\nci.bash\nREADME.md\n";

    private const string FINDING = <<<'OUT'
        In scripts/build.bash line 3:
        BUILD_VENDOR="$BUILD_DIR/vendor"
        ^----------^ SC2034 (warning): BUILD_VENDOR appears unused. Verify use (or export if used externally).
        OUT;

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        $this->factory->project->write(self::BUILD, "#!/usr/bin/env bash\ntrue\n");
        $this->factory->project->write(self::CI_BASH, "#!/usr/bin/env bash\ntrue\n");
        $this->factory->project->write('README.md', "# hi\n");
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function everyDiscoveredShellFileIsCheckedAtWarningSeverityInOneInvocation(): void
    {
        $this->queue()->willSucceed('');

        $result = new ShellCheckTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame($this->expectedCommand(self::CI_BASH, self::BUILD), $this->factory->processes->lastSpec()->command);
        self::assertStringContainsString('2 shell file(s) checked', $this->factory->output->fetch());
    }

    #[Test]
    public function aFindingFailsTheLaneAndThePrintedReportNamesFileLineAndCode(): void
    {
        $this->queue()->willFail(1, self::FINDING);

        $result  = new ShellCheckTool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('scripts/build.bash line 3', $printed);
        self::assertStringContainsString('SC2034', $printed);
        self::assertStringContainsString(ShellCheckTool::IDENTIFIER, $printed);
    }

    /**
     * ShellCheck exits 1 for findings and 2 when it could not read a file at
     * all. Reporting the second as a finding would hide a broken checkout
     * behind a fixable-looking failure.
     */
    #[Test]
    public function anExitCodeAboveOneIsACrashRatherThanAFinding(): void
    {
        $this->queue()->willFail(2, "openBinaryFile: does not exist\n");

        $result = new ShellCheckTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame('ShellCheck crashed (exit 2)', $result->summary);
    }

    #[Test]
    public function aHostWhoseBinaryDoesNotMatchThePinIsACrashNamingBoth(): void
    {
        $this->factory->processes
            ->willSucceed(self::TRACKED)
            ->willSucceed("ShellCheck - shell script analysis tool\nversion: 0.9.0\n")
        ;

        $result = new ShellCheckTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        $pinned = ShellCheckBinary::pinnedVersion($this->factory->paths()->libraryRoot);
        self::assertNotNull($pinned);

        $printed = $this->factory->output->fetch();
        self::assertStringContainsString('v0.9.0', $printed);
        self::assertStringContainsString($pinned, $printed);
    }

    /**
     * The tracked file set is the contract, so a checkout that is not a
     * repository has no contract to check against. That is a skip with a
     * reason, not a pass.
     */
    #[Test]
    public function aProjectThatIsNotAGitWorkTreeSkipsWithAReason(): void
    {
        $this->factory->processes->willFail(128, "fatal: not a git repository\n");

        $result = new ShellCheckTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString('not a git work tree', $result->summary);
    }

    #[Test]
    public function aProjectWithNoShellFilesAtAllPasses(): void
    {
        $this->factory->processes->willSucceed("README.md\nsrc/A.php\n")->willSucceed(self::BANNER);

        $result = new ShellCheckTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('No shell files to check', $this->factory->output->fetch());
    }

    /**
     * Silence from a glob list the project wrote by hand is a broken config,
     * not a clean bill of health — the whole point of the list is that those
     * files get checked.
     */
    #[Test]
    public function aConfiguredGlobThatMatchesNothingIsACrash(): void
    {
        $this->queue();
        $config = $this->factory->builder()->withShellCheckGlobs('deploy/*.bash')->build();

        $result = new ShellCheckTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertStringContainsString('deploy/*.bash', $this->factory->output->fetch());
    }

    #[Test]
    public function aConfiguredGlobNarrowsTheSetToWhatItNames(): void
    {
        $this->queue()->willSucceed('');
        $config = $this->factory->builder()->withShellCheckGlobs('scripts/*.bash')->build();

        $result = new ShellCheckTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame($this->expectedCommand(self::BUILD), $this->factory->processes->lastSpec()->command);
    }

    #[Test]
    public function aPathGivenOnTheCommandLineNarrowsTheDiscoveredSet(): void
    {
        $this->queue()->willSucceed('');
        $config = $this->factory->builder(specifiedPath: 'scripts')->build();

        $result = new ShellCheckTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame($this->expectedCommand(self::BUILD), $this->factory->processes->lastSpec()->command);
    }

    #[Test]
    public function ignoredPathsAreLeftOut(): void
    {
        $this->queue()->willSucceed('');
        $config = $this->factory->builder()->withIgnoredPaths('scripts')->build();

        $result = new ShellCheckTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame($this->expectedCommand(self::CI_BASH), $this->factory->processes->lastSpec()->command);
    }

    #[Test]
    public function theTrackedFileProbeIsQuietAndRunsInTheProjectRoot(): void
    {
        $this->queue()->willSucceed('');

        new ShellCheckTool()->run($this->factory->context());

        $probe = $this->factory->processes->specs[0];
        self::assertSame(['git', 'ls-files', '--cached'], $probe->command);
        self::assertSame($this->factory->project->path, $probe->cwd);
        self::assertFalse($probe->streamOutput);
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new ShellCheckTool();

        self::assertSame('shellCheck', $tool->name());
        self::assertSame('phpqaci.shellCheck', $tool->identifier());
        self::assertSame(ShellCheckBinary::IDENTIFIER, $tool->identifier());
    }

    /**
     * The one invocation the lane makes: the pinned binary, the fixed
     * severity, then the absolute path of each file in discovery order.
     *
     * @return list<string>
     */
    private function expectedCommand(string ...$relative): array
    {
        $root = $this->factory->project->path;

        return [
            ShellCheckBinary::path($this->factory->paths()->libraryRoot),
            '--severity=' . ShellCheckBinary::SEVERITY,
            '--',
            ...array_map(static fn (string $file): string => $root . '/' . $file, array_values($relative)),
        ];
    }

    /** The two probes every run makes before the check itself: the tracked file list, then the version. */
    private function queue(): FakeProcessRunner
    {
        return $this->factory->processes->willSucceed(self::TRACKED)->willSucceed(self::BANNER);
    }
}
