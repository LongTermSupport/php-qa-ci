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
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class ShellCheckToolTest extends TestCase
{
    private const string BANNER = "ShellCheck - shell script analysis tool\nversion: 0.11.0\n";

    private const string BUILD = 'scripts/build.bash';

    private const string CI_BASH = 'ci.bash';

    private const string TRACKED = "scripts/build.bash\nci.bash\nREADME.md\n";

    private const string SIBLING = 'scripts-old/deploy.bash';

    private const string SCRIPT_BODY = "#!/usr/bin/env bash\ntrue\n";

    private const string DEPLOY_GLOB = 'deploy/*.bash';

    private const string FAKE_LIBRARY = 'library';

    private const string RESTORE_HEADING = "Restore it with:\n";

    private const string BROKEN_INSTALL = 'the vendored ShellCheck binary or its version pin is missing';

    private const string IDENTIFIER_FOOTER = "\n🪪  phpqaci.shellCheck  (vendor/bin/rule-doc phpqaci.shellCheck)\n";

    private const string FINDING = <<<'OUT'
        In scripts/build.bash line 3:
        BUILD_VENDOR="$BUILD_DIR/vendor"
        ^----------^ SC2034 (warning): BUILD_VENDOR appears unused. Verify use (or export if used externally).
        OUT;

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        $this->factory->project->write(self::BUILD, self::SCRIPT_BODY);
        $this->factory->project->write(self::CI_BASH, self::SCRIPT_BODY);
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
        $config = $this->factory->builder()->withShellCheckGlobs(self::DEPLOY_GLOB)->build();

        $result = new ShellCheckTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertStringContainsString(self::DEPLOY_GLOB, $this->factory->output->fetch());
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
    public function aPassReportsTheCountAndTheSeverityAndTheCheckIsQuietInTheProjectRoot(): void
    {
        $this->queue()->willSucceed('');

        new ShellCheckTool()->run($this->factory->context());

        self::assertSame("2 shell file(s) checked; no finding at severity warning or above\n", $this->factory->output->fetch());
        $check = $this->factory->processes->lastSpec();
        self::assertSame($this->factory->project->path, $check->cwd);
        self::assertFalse($check->streamOutput);
    }

    #[Test]
    public function theVersionProbeIsQuietAndRunsThePinnedBinaryInTheProjectRoot(): void
    {
        $this->queue()->willSucceed('');

        new ShellCheckTool()->run($this->factory->context());

        $probe = $this->factory->processes->specs[1];
        self::assertSame([ShellCheckBinary::path($this->factory->paths()->libraryRoot), '--version'], $probe->command);
        self::assertSame($this->factory->project->path, $probe->cwd);
        self::assertFalse($probe->streamOutput);
    }

    #[Test]
    public function aVersionMismatchPrintsBothVersionsWhyAndTheRestoreCommand(): void
    {
        $this->factory->processes
            ->willSucceed(self::TRACKED)
            ->willSucceed("ShellCheck - shell script analysis tool\nversion: 0.9.0\n")
        ;
        $libraryRoot = $this->factory->paths()->libraryRoot;
        $pinned      = ShellCheckBinary::pinnedVersion($libraryRoot);
        self::assertNotNull($pinned);

        $result = new ShellCheckTool()->run($this->factory->context());

        self::assertSame('the vendored ShellCheck does not match the pinned release', $result->summary);
        self::assertSame(
            'The vendored ShellCheck reports v0.9.0 but this checkout pins ' . $pinned . ".\n"
            . "One pinned build has to run in every environment, or QA and CI are not the same check.\n"
            . self::RESTORE_HEADING
            . '  ' . ShellCheckBinary::fetchCommand($libraryRoot, $pinned) . "\n"
            . self::IDENTIFIER_FOOTER,
            $this->factory->output->fetch(),
        );
    }

    /**
     * A missing binary with a pin is broken on its own; the lane must not
     * wait for the pin to be missing as well before it says so.
     */
    #[Test]
    public function aMissingBinaryBesideAPinIsACrashWithTheRestoreCommand(): void
    {
        $this->factory->project->write(self::FAKE_LIBRARY . '/' . ShellCheckBinary::VERSION_FILE, "v0.11.0\n");
        $libraryRoot = $this->factory->project->path . '/' . self::FAKE_LIBRARY;

        $result = new ShellCheckTool()->run($this->factory->context($this->configWithLibraryRoot($libraryRoot)));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame(self::BROKEN_INSTALL, $result->summary);
        self::assertSame(
            "The vendored ShellCheck is not usable: the binary is missing\n"
            . 'Expected: ' . ShellCheckBinary::path($libraryRoot) . " (linux.x86_64)\n"
            . self::RESTORE_HEADING
            . '  ' . ShellCheckBinary::fetchCommand($libraryRoot, 'v0.11.0') . "\n"
            . self::IDENTIFIER_FOOTER,
            $this->factory->output->fetch(),
        );
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function aBinaryWithNoPinIsACrashWithNothingToRestoreFrom(): void
    {
        $this->factory->project->write(self::FAKE_LIBRARY . '/' . ShellCheckBinary::RELATIVE_PATH, "#!/bin/sh\n");
        $libraryRoot = $this->factory->project->path . '/' . self::FAKE_LIBRARY;

        $result = new ShellCheckTool()->run($this->factory->context($this->configWithLibraryRoot($libraryRoot)));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame(self::BROKEN_INSTALL, $result->summary);
        self::assertSame(
            "The vendored ShellCheck is not usable: no pinned version recorded\n"
            . 'Expected: ' . ShellCheckBinary::path($libraryRoot) . " (linux.x86_64)\n"
            . self::IDENTIFIER_FOOTER,
            $this->factory->output->fetch(),
        );
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function aGlobListThatMatchesNothingPrintsEveryGlobAndWhyItIsBroken(): void
    {
        $this->queue();
        $config = $this->factory->builder()->withShellCheckGlobs(self::DEPLOY_GLOB, 'hooks/*')->build();

        $result = new ShellCheckTool()->run($this->factory->context($config));

        self::assertSame('every configured ShellCheck glob matched nothing', $result->summary);
        self::assertSame(
            "withShellCheckGlobs() names files this repository does not track:\n"
            . "  deploy/*.bash\n"
            . "  hooks/*\n"
            . "\n"
            . "A hand-written glob list that matches nothing is a broken configuration, not a clean\n"
            . "result: the list exists so those files are checked. Correct it, or drop it and let the\n"
            . "lane discover shell files by extension and shebang.\n"
            . self::IDENTIFIER_FOOTER,
            $this->factory->output->fetch(),
        );
    }

    #[Test]
    public function trackedFileLinesAreTrimmedOfCarriageReturns(): void
    {
        $this->factory->processes
            ->willSucceed("scripts/build.bash\r\nci.bash\r\n")
            ->willSucceed(self::BANNER)
            ->willSucceed('')
        ;

        $result = new ShellCheckTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame($this->expectedCommand(self::CI_BASH, self::BUILD), $this->factory->processes->lastSpec()->command);
    }

    /**
     * `-p scripts/` means the directory: its trailing slash is not part of the
     * name, and a sibling that merely starts with the same letters is not in it.
     */
    #[Test]
    public function aPathGivenWithATrailingSlashNarrowsToThatDirectoryAndNotItsNamesakes(): void
    {
        $this->factory->project->write(self::SIBLING, self::SCRIPT_BODY);
        $this->factory->processes
            ->willSucceed(self::TRACKED . self::SIBLING . "\n")
            ->willSucceed(self::BANNER)
            ->willSucceed('')
        ;
        $config = $this->factory->builder(specifiedPath: 'scripts/')->build();

        $result = new ShellCheckTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame($this->expectedCommand(self::BUILD), $this->factory->processes->lastSpec()->command);
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

    /** The fixture paths with the library root moved, so its vendor-bin/ can be broken on purpose. */
    private function configWithLibraryRoot(string $libraryRoot): \LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto
    {
        $paths = $this->factory->paths();

        return $this->factory->builder(paths: new \LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto(
            projectRoot: $paths->projectRoot,
            libraryRoot: $libraryRoot,
            binDir: $paths->binDir,
            srcDir: $paths->srcDir,
            testsDir: $paths->testsDir,
            projectConfigDir: $paths->projectConfigDir,
            varDir: $paths->varDir,
            cacheDir: $paths->cacheDir,
            pharDir: $paths->pharDir,
            configDefaultsDir: $paths->configDefaultsDir,
        ))->build();
    }

    /** The two probes every run makes before the check itself: the tracked file list, then the version. */
    private function queue(): FakeProcessRunner
    {
        return $this->factory->processes->willSucceed(self::TRACKED)->willSucceed(self::BANNER);
    }
}
