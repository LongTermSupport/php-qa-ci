<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use Iterator;
use LTS\PHPQA\Pipeline\Config\ActionsQaBinary;
use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;
use LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto;
use LTS\PHPQA\Pipeline\Lane\ActionsQaTool;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The lane over a fake process runner and a library root whose install directory the test fills
 * or leaves empty: the bundle itself is never committed, so the lane is tested on what it does
 * when it is there, missing, of another release, or answering with each exit code.
 *
 * @internal
 */
#[CoversClass(ActionsQaTool::class)]
#[UsesClass(ActionsQaBinary::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(ProjectPathsDto::class)]
#[UsesClass(QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(ToolOutcomeEnum::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[UsesClass(\LTS\PHPQA\ComposerPlugin\ChildExit::class)]
#[Small]
final class ActionsQaToolTest extends TestCase
{
    private const string LINUX = 'Linux';

    private const string X86_64 = 'x86_64';

    private const string WORKFLOWS = ".github/workflows/qa.yml\n";

    private const string CURRENT_REPORT = '{"method": "1.1.0", "toolchain": "' . ActionsQaBinary::VERSION . '"}';

    private const string FINDING = ".github/workflows/qa.yml:12:7 actionsqaci.actionlint.expression \"github.head_ref\" is potentially untrusted\nrule-doc actionsqaci.actionlint.expression\n1 findings\n";

    private const string IDENTIFIER_FOOTER = "\n🪪  phpqaci.actionsQa  (vendor/bin/rule-doc phpqaci.actionsQa)\n";

    private const string FAKE_LIBRARY = 'library';

    private const string CLEAN = "0 findings\n";

    private const string INSTALL_WITH = "Install it with:\n";

    private const string BROKEN_INSTALL = 'actions-qa-ci is not installed at the pinned release';

    private ContextFactory $factory;

    private string $libraryRoot;

    protected function setUp(): void
    {
        $this->factory     = ContextFactory::create();
        $this->libraryRoot = $this->factory->project->path . '/' . self::FAKE_LIBRARY;
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function aCleanCheckPassesAndPrintsWhatTheToolSaid(): void
    {
        $this->install();
        $this->factory->processes->willSucceed(self::WORKFLOWS)->willSucceed(self::CURRENT_REPORT)->willSucceed(self::CLEAN);

        $result = $this->tool()->run($this->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(self::CLEAN, $this->factory->output->fetch());
    }

    /** The probes and the check are quiet, run in the project root, and ask what the lane needs, in order. */
    #[Test]
    public function theLaneProbesTheWorkflowsThenTheVersionThenChecksFromTheProjectRoot(): void
    {
        $this->install();
        $this->factory->processes->willSucceed(self::WORKFLOWS)->willSucceed(self::CURRENT_REPORT)->willSucceed(self::CLEAN);

        $this->tool()->run($this->context());

        $binary = ActionsQaBinary::path($this->libraryRoot);
        self::assertSame(
            [
                ['git', 'ls-files', '--cached', '--', '.github/workflows'],
                [$binary, 'version', '--json'],
                [$binary, 'check', '--format', 'agent'],
            ],
            array_map(static fn (ProcessSpecDto $spec): array => $spec->command, $this->factory->processes->specs),
        );
        foreach ($this->factory->processes->specs as $spec) {
            self::assertSame($this->factory->project->path, $spec->cwd);
            self::assertFalse($spec->streamOutput);
        }
    }

    #[Test]
    public function findingsFailTheLaneAndThePrintedReportNamesThemAndTheIdentifier(): void
    {
        $this->install();
        $this->factory->processes->willSucceed(self::WORKFLOWS)->willSucceed(self::CURRENT_REPORT)->willFail(1, self::FINDING);

        $result = $this->tool()->run($this->context());

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('actions-qa-ci reported findings', $result->summary);
        self::assertSame(self::FINDING . self::IDENTIFIER_FOOTER, $this->factory->output->fetch());
    }

    /** Only exit 1 is a verdict about the workflows; every other non-zero exit is the tool's failure, never a pass. */
    #[Test]
    #[DataProvider('exitsThatAreNoVerdict')]
    public function anExitOtherThanZeroOrOneIsACrashSayingWhatItMeans(int $exitCode, string $summary): void
    {
        $this->install();
        $this->factory->processes->willSucceed(self::WORKFLOWS)->willSucceed(self::CURRENT_REPORT)->willFail($exitCode, "actions-qa: something\n");

        $result = $this->tool()->run($this->context());

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame($summary, $result->summary);
        self::assertStringContainsString("actions-qa: something\n", $this->factory->output->fetch());
    }

    /** @return Iterator<string, array{int, string}> */
    public static function exitsThatAreNoVerdict(): Iterator
    {
        yield 'refused' => [2, 'actions-qa-ci refused to check the project (exit 2)'];
        yield 'toolchain fault' => [3, 'actions-qa-ci hit a toolchain fault (exit 3)'];
        yield 'no verdict' => [4, 'actions-qa-ci reached no verdict (exit 4)'];
        yield 'anything else' => [127, 'actions-qa-ci crashed (exit 127)'];
    }

    #[Test]
    public function aProjectThatIsNotAGitWorkTreeSkipsWithAReason(): void
    {
        $this->factory->processes->willFail(128, "fatal: not a git repository\n");

        $result = $this->tool()->run($this->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString('not a git work tree', $result->summary);
    }

    /** A project with no workflows needs no bundle: it skips before the install is even looked at. */
    #[Test]
    public function aProjectWithNoTrackedWorkflowsSkipsWithAReasonWithoutTheBundle(): void
    {
        $this->factory->processes->willSucceed('');

        $result = $this->tool()->run($this->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString('.github/workflows', $result->summary);
        self::assertCount(1, $this->factory->processes->specs);
    }

    #[Test]
    public function aMissingBundleIsACrashNamingTheOneCommandThatInstallsIt(): void
    {
        $this->factory->processes->willSucceed(self::WORKFLOWS);

        $result = $this->tool()->run($this->context());

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame(self::BROKEN_INSTALL, $result->summary);
        self::assertSame(
            \sprintf("actions-qa-ci %s is not installed: %s is missing or not executable.\n", ActionsQaBinary::VERSION, ActionsQaBinary::path($this->libraryRoot))
            . self::INSTALL_WITH
            . '  ' . ActionsQaBinary::installCommand($this->libraryRoot) . "\n"
            . self::IDENTIFIER_FOOTER,
            $this->factory->output->fetch(),
        );
        self::assertCount(1, $this->factory->processes->specs);
    }

    #[Test]
    public function anInstallOfAnotherReleaseIsACrashNamingBothAndTheInstallCommand(): void
    {
        $this->install();
        $this->factory->processes->willSucceed(self::WORKFLOWS)->willSucceed('{"toolchain": "0.1.0"}');

        $result = $this->tool()->run($this->context());

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame(self::BROKEN_INSTALL, $result->summary);
        self::assertSame(
            \sprintf("The installed actions-qa-ci reports 0.1.0 but this checkout pins %s.\n", ActionsQaBinary::VERSION)
            . "One pinned release has to run in every environment, or QA and CI are not the same check.\n"
            . self::INSTALL_WITH
            . '  ' . ActionsQaBinary::installCommand($this->libraryRoot) . "\n"
            . self::IDENTIFIER_FOOTER,
            $this->factory->output->fetch(),
        );
    }

    #[Test]
    public function aVersionProbeThatFailsIsReportedAsNothingReadable(): void
    {
        $this->install();
        $this->factory->processes->willSucceed(self::WORKFLOWS)->willFail(126, 'cannot execute binary file');

        $result = $this->tool()->run($this->context());

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertStringContainsString('reports nothing readable (exit 126)', $this->factory->output->fetch());
    }

    #[Test]
    public function aHostWithNoBundleIsACrashNamingTheHost(): void
    {
        $this->factory->processes->willSucceed(self::WORKFLOWS);

        $result = new ActionsQaTool('Darwin', 'arm64')->run($this->context());

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame('actions-qa-ci has no bundle for this host', $result->summary);
        self::assertSame(
            "actions-qa-ci publishes no bundle for this host (Darwin arm64).\n"
            . "The lane runs on Linux with an x86_64 (linux_amd64) or aarch64 (linux_arm64) CPU.\n"
            . self::IDENTIFIER_FOOTER,
            $this->factory->output->fetch(),
        );
        self::assertCount(1, $this->factory->processes->specs);
    }

    #[Test]
    public function anAarch64HostRunsTheSameInstall(): void
    {
        $this->install();
        $this->factory->processes->willSucceed(self::WORKFLOWS)->willSucceed(self::CURRENT_REPORT)->willSucceed(self::CLEAN);

        $result = new ActionsQaTool(self::LINUX, 'aarch64')->run($this->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
    }

    /** Without a machine given, the lane asks the host it is running on. */
    #[Test]
    public function withNoMachineGivenTheLaneUsesThisHostsMachine(): void
    {
        $this->factory->processes->willSucceed(self::WORKFLOWS);

        new ActionsQaTool('Darwin')->run($this->context());

        self::assertStringContainsString(\sprintf('(Darwin %s)', php_uname('m')), $this->factory->output->fetch());
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = $this->tool();

        self::assertSame('actionsQa', $tool->name());
        self::assertSame('phpqaci.actionsQa', $tool->identifier());
    }

    private function tool(): ActionsQaTool
    {
        return new ActionsQaTool(self::LINUX, self::X86_64);
    }

    /** An executable stand-in for the installed program in the fake library. */
    private function install(): void
    {
        $program = $this->factory->project->write(self::FAKE_LIBRARY . '/' . ActionsQaBinary::INSTALL_DIR . '/bin/actions-qa', "#!/bin/sh\n");
        \Safe\chmod($program, 0o755);
    }

    /** The fixture's config with the library root moved, so its install directory is the test's. */
    private function context(): \LTS\PHPQA\Pipeline\Tool\ToolContext
    {
        $paths = $this->factory->paths();

        return $this->factory->context($this->factory->builder(paths: new ProjectPathsDto(
            projectRoot: $paths->projectRoot,
            libraryRoot: $this->libraryRoot,
            binDir: $paths->binDir,
            srcDir: $paths->srcDir,
            testsDir: $paths->testsDir,
            projectConfigDir: $paths->projectConfigDir,
            varDir: $paths->varDir,
            cacheDir: $paths->cacheDir,
            pharDir: $paths->pharDir,
            configDefaultsDir: $paths->configDefaultsDir,
        ))->build());
    }
}
