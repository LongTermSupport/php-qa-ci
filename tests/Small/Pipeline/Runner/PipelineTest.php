<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Runner;

use LTS\PHPQA\ComposerPlugin\ChildExit;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Lock\Dto\LockInfoDto;
use LTS\PHPQA\Pipeline\Lock\RunLock;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\Dto\SignalledChildDto;
use LTS\PHPQA\Pipeline\Process\SignalledChildren;
use LTS\PHPQA\Pipeline\Runner\AggregateReport;
use LTS\PHPQA\Pipeline\Runner\DirectoryPreparer;
use LTS\PHPQA\Pipeline\Runner\HookRunner;
use LTS\PHPQA\Pipeline\Runner\NonInteractiveRetryPrompt;
use LTS\PHPQA\Pipeline\Runner\PharToolsVerifier;
use LTS\PHPQA\Pipeline\Runner\Pipeline;
use LTS\PHPQA\Pipeline\Runner\ToolExecutor;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use LTS\PHPQA\Pipeline\Tool\ToolRegistry;
use LTS\PHPQA\Tests\Support\AlwaysRetry;
use LTS\PHPQA\Tests\Support\ContextFactory;
use LTS\PHPQA\Tests\Support\FakeToolLocator;
use LTS\PHPQA\Tests\Support\RewritingTool;
use LTS\PHPQA\Tests\Support\SignalledChildTool;
use LTS\PHPQA\Tests\Support\StubTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(Pipeline::class)]
#[CoversClass(AggregateReport::class)]
#[UsesClass(ToolExecutor::class)]
#[UsesClass(RunLock::class)]
#[UsesClass(HookRunner::class)]
#[UsesClass(DirectoryPreparer::class)]
#[UsesClass(PharToolsVerifier::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(LockInfoDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lock\SystemClock::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Runner\Dto\ExecutionDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto::class)]
#[UsesClass(ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\PhaseEnum::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\PhaseDto::class)]
#[UsesClass(ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolGateEnum::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum::class)]
#[UsesClass(ToolRegistry::class)]
#[UsesClass(NonInteractiveRetryPrompt::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[UsesClass(\LTS\PHPQA\PhpstanDocs\PhpstanDocsCatalogue::class)]
#[UsesClass(\LTS\PHPQA\Turbo\TurboManifest::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Runner\RepeatUntilSettled::class)]
#[UsesClass(SignalledChildren::class)]
#[UsesClass(SignalledChildDto::class)]
#[UsesClass(ChildExit::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[Small]
final class PipelineTest extends TestCase
{
    private const string ALL_TESTS_PASSING = 'ALL TESTS PASSING';

    private const string PHP_LINT = 'phpLint';

    private const string PHP_CS_FIXER = 'phpCsFixer';

    private const string RECTOR = 'rector';

    private const string PHPSTAN = 'phpstan';

    private const string TWIG_LINT = 'twigLint';

    private const string PARAM_TYPES = 'types';

    private const string HALTED = 'HALTED';

    private const string SEGFAULT_LINE = 'A segmentation fault is never retried past or accepted in a passing run: debug it and report it where the crashing code lives, as %s describes.';

    private ContextFactory $factory;

    private FakeToolLocator $tools;

    private SignalledChildren $signalled;

    protected function setUp(): void
    {
        $this->factory   = ContextFactory::create();
        $this->tools     = new FakeToolLocator();
        $this->signalled = new SignalledChildren();
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function aFullGreenRunExecutesEveryPhasedToolInOrderAndExitsZero(): void
    {
        $context = $this->factory->context($this->factory->builder(readOnly: false, aggregate: false)->build());

        $exit = $this->pipeline()->run($context);

        self::assertSame(0, $exit);
        $printed = $this->factory->output->fetch();
        self::assertStringContainsString(self::ALL_TESTS_PASSING, $printed);
        $expectedOrder = ['rector', 'phpCsFixer', 'twigCsFixer', 'markdownFormat', 'psr4Validate', 'composerChecks', 'packageType', 'configTemplateIgnoreList', 'infectionConfigSourceDirs', 'analysedPaths', 'versionPins', 'changelog', 'phpStrictTypes', self::PHP_LINT, 'opcache', 'composerRequireChecker', 'composerDependencyAnalyser', 'markdownLinks', 'docsProse', 'documentedQaCommands', 'yamlLint', 'shellCheck', 'branchNamePolicy', 'phpstanIgnoreJustification', self::PHPSTAN, 'deadCode', 'phpArkitect', 'sensitiveParameterUsage', 'phpunit', 'infection', 'phpcpd'];
        \Safe\preg_match_all('/\[(\w+) ran\]/', $printed, $ran);
        self::assertSame($expectedOrder, $ran[1] ?? []);
        self::assertNull($this->lockRecord(), 'the lock is released');
        self::assertFileExists($this->factory->project->path . '/var/qa/cache/.gitignore', 'directories are prepared');
    }

    #[Test]
    public function aSymfonyProjectRunsTheTwigLinterAfterTheGenericLintingLanes(): void
    {
        $context = $this->factory->context($this->factory->builder(readOnly: false, aggregate: false, platform: PlatformEnum::Symfony)->build());

        self::assertSame(0, $this->pipeline()->run($context));
        $printed = $this->factory->output->fetch();
        \Safe\preg_match_all('/\[(\w+) ran\]/', $printed, $ran);
        $captured = $ran[1] ?? null;
        $order    = \is_array($captured) ? array_values(array_filter($captured, is_string(...))) : [];
        self::assertContains(self::TWIG_LINT, $order);
        self::assertGreaterThan(array_search('yamlLint', $order, true), array_search(self::TWIG_LINT, $order, true));
        self::assertLessThan(array_search('branchNamePolicy', $order, true), array_search(self::TWIG_LINT, $order, true));
        self::assertStringContainsString('Running Twig Linter', $printed);
    }

    #[Test]
    public function failFastStopsAtTheFirstFailureAndExitsOne(): void
    {
        $this->tools->register(new StubTool(self::PHP_LINT, ToolResultDto::failed('syntax')));
        $context = $this->factory->context($this->factory->builder(readOnly: false, aggregate: false)->build());

        $exit = $this->pipeline()->run($context);

        self::assertSame(1, $exit);
        $printed = $this->factory->output->fetch();
        self::assertStringContainsString('[phpLint ran]', $printed);
        self::assertStringNotContainsString('[composerRequireChecker ran]', $printed);
        self::assertStringNotContainsString(self::ALL_TESTS_PASSING, $printed);
    }

    #[Test]
    public function aggregateModeRunsEverythingAndListsEveryFailure(): void
    {
        $this->tools->register(new StubTool(self::PHP_LINT, ToolResultDto::failed('syntax')));
        $this->tools->register(new StubTool(self::PHPSTAN, ToolResultDto::failed(self::PARAM_TYPES)));

        $context = $this->factory->context($this->factory->builder(readOnly: true, aggregate: true)->build());

        $exit = $this->pipeline()->run($context);

        self::assertSame(1, $exit);
        $printed = $this->factory->output->fetch();
        self::assertStringContainsString('>>> phpLint FAILED — continuing (aggregate mode)', $printed);
        self::assertStringContainsString('[infection ran]', $printed, 'later tools still run');
        self::assertStringContainsString('Aggregate (read-only) run: 2 tool(s) FAILED', $printed);
        self::assertStringContainsString('          - phpLint', $printed);
        self::assertStringContainsString('          - phpstan', $printed);
        self::assertStringNotContainsString(self::ALL_TESTS_PASSING, $printed);
    }

    #[Test]
    public function aggregateModeReportsEveryToolPassedOnAGreenRun(): void
    {
        $context = $this->factory->context($this->factory->builder(readOnly: true, aggregate: true)->build());

        self::assertSame(0, $this->pipeline()->run($context));
        self::assertStringContainsString('every QA tool passed', $this->factory->output->fetch());
    }

    #[Test]
    public function gatesSkipPhpstanPhpunitAndInfectionInQuickMode(): void
    {
        $context = $this->factory->context($this->factory->builder(env: ['phpqaQuickTests' => '1'], readOnly: false, aggregate: false)->build());

        self::assertSame(0, $this->pipeline()->run($context));
        $printed = $this->factory->output->fetch();
        self::assertStringContainsString('Skipping phpstan (gate=NotQuick', $printed);
        self::assertStringContainsString('Skipping phpunit', $printed);
        self::assertStringContainsString('Skipping infection', $printed);
        self::assertSame(0, $this->tools->stub(self::PHPSTAN)->runs);
    }

    #[Test]
    public function infectionIsSkippedWhenCoverageIsOff(): void
    {
        $context = $this->factory->context($this->factory->builder(env: ['phpUnitCoverage' => '0'], readOnly: false, aggregate: false)->build());

        self::assertSame(0, $this->pipeline()->run($context));
        self::assertStringContainsString('Skipping infection (gate=Infection', $this->factory->output->fetch());
        self::assertSame(1, $this->tools->stub('phpunit')->runs);
    }

    #[Test]
    public function aSingleLeafToolRunsAloneAndIgnoresGates(): void
    {
        $context = $this->factory->context($this->factory->builder(env: ['phpqaQuickTests' => '1'], readOnly: false, aggregate: false, singleTool: self::PHPSTAN)->build());

        self::assertSame(0, $this->pipeline()->run($context));
        $printed = $this->factory->output->fetch();
        self::assertStringContainsString('Running Single Tool: phpstan', $printed);
        self::assertSame(1, $this->tools->stub(self::PHPSTAN)->runs);
        self::assertSame(0, $this->tools->stub(self::PHP_LINT)->runs);
        self::assertStringNotContainsString(self::ALL_TESTS_PASSING, $printed);
    }

    #[Test]
    public function aSingleFailingLeafToolExitsOne(): void
    {
        $this->tools->register(new StubTool(self::PHPSTAN, ToolResultDto::failed(self::PARAM_TYPES)));
        $context = $this->factory->context($this->factory->builder(readOnly: false, aggregate: false, singleTool: self::PHPSTAN)->build());

        self::assertSame(1, $this->pipeline()->run($context));
    }

    #[Test]
    public function aPhaseRunnerRunsItsLeafToolsWithGatesAndAggregates(): void
    {
        $this->tools->register(new StubTool(self::PHPSTAN, ToolResultDto::failed(self::PARAM_TYPES)));
        $context = $this->factory->context($this->factory->builder(env: ['phpqaQuickTests' => '1'], readOnly: true, aggregate: true, singleTool: 'allStaticAnalysisTools')->build());

        self::assertSame(0, $this->pipeline()->run($context), 'phpstan is gated off in quick mode, so nothing fails');
        $printed = $this->factory->output->fetch();
        self::assertStringContainsString('[branchNamePolicy ran]', $printed);
        self::assertStringContainsString('Skipping phpstan', $printed);
        self::assertStringContainsString('every QA tool passed', $printed);
    }

    #[Test]
    public function aHeldLockAbortsBeforeAnyToolRuns(): void
    {
        $context = $this->factory->context($this->factory->builder(readOnly: false, aggregate: false)->build());
        $holder  = RunLock::forProject($this->factory->project->path, $this->factory->output);
        $holder->acquire('unit', '', 'other-host', 999);

        self::assertSame(Pipeline::EXIT_LOCK_CONTENDED, $this->pipeline()->run($context));
        self::assertStringContainsString('Another QA run holds the lock', $this->factory->output->fetch());
        self::assertSame(0, $this->tools->stub('rector')->runs);
    }

    #[Test]
    public function aGenuineToolFailureStillExitsOne(): void
    {
        // The other half of the contention contract: a real defect keeps exit 1,
        // so the distinct contention code stays meaningful. That the two codes
        // differ is guaranteed by the constant's type, not asserted here.
        $this->tools->register(new StubTool(self::PHP_LINT, ToolResultDto::failed('a real defect')));
        $context = $this->factory->context($this->factory->builder(readOnly: false, aggregate: false, singleTool: self::PHP_LINT)->build());

        self::assertSame(1, $this->pipeline()->run($context));
    }

    #[Test]
    public function aWritableRunRepeatsTheCodingStandardsPhaseUntilAPassChangesNothing(): void
    {
        $fixer = new RewritingTool(self::PHP_CS_FIXER, 1);
        $this->tools->register($fixer);
        $context = $this->factory->context($this->factory->builder(readOnly: false, aggregate: false)->build());

        self::assertSame(0, $this->pipeline()->run($context));
        self::assertSame(2, $fixer->runs);
        self::assertSame(2, $this->tools->stub(self::RECTOR)->runs, 'the tool before the one that rewrote sees its changes');
        self::assertSame(1, $this->tools->stub(self::PHP_LINT)->runs, 'only the coding-standards phase repeats');
        self::assertStringContainsString("1 file(s) changed on pass 1; running the phase again so each tool sees the others' changes", $this->factory->output->fetch());
    }

    #[Test]
    public function theCodingStandardsRunnerRepeatsToo(): void
    {
        $fixer = new RewritingTool(self::PHP_CS_FIXER, 2);
        $this->tools->register($fixer);
        $context = $this->factory->context($this->factory->builder(readOnly: false, aggregate: false, singleTool: 'allCodingStandardsTools')->build());

        self::assertSame(0, $this->pipeline()->run($context));
        self::assertSame(3, $fixer->runs);
        self::assertSame(3, $this->tools->stub(self::RECTOR)->runs);
    }

    #[Test]
    public function aReadOnlyRunChecksOnce(): void
    {
        $fixer = new RewritingTool(self::PHP_CS_FIXER, 1);
        $this->tools->register($fixer);
        $context = $this->factory->context($this->factory->builder(readOnly: true, aggregate: false)->build());

        self::assertSame(0, $this->pipeline()->run($context));
        self::assertSame(1, $fixer->runs);
        self::assertSame(1, $this->tools->stub(self::RECTOR)->runs);
    }

    #[Test]
    public function aPhaseThatDoesNotRepeatRunsOnceWhateverItsToolsWrite(): void
    {
        $lint = new RewritingTool(self::PHP_LINT, 1);
        $this->tools->register($lint);
        $context = $this->factory->context($this->factory->builder(readOnly: false, aggregate: false)->build());

        self::assertSame(0, $this->pipeline()->run($context));
        self::assertSame(1, $lint->runs);
    }

    #[Test]
    public function aFailureInTheCodingStandardsPhaseIsReportedOnceAndNotRepeated(): void
    {
        $fixer = new RewritingTool(self::PHP_CS_FIXER, 1, ToolResultDto::failed('pending'));
        $this->tools->register($fixer);
        $aggregate = $this->factory->context($this->factory->builder(readOnly: false, aggregate: true)->build());

        self::assertSame(1, $this->pipeline()->run($aggregate));
        self::assertSame(1, $fixer->runs);
        self::assertSame(1, substr_count($this->factory->output->fetch(), '>>> phpCsFixer FAILED'));
        self::assertSame(1, $this->tools->stub(self::PHP_LINT)->runs, 'an aggregate run carries on to the next phase');

        $failFast = $this->factory->context($this->factory->builder(readOnly: false, aggregate: false)->build());

        self::assertSame(1, $this->pipeline()->run($failFast));
        self::assertSame(2, $fixer->runs);
        self::assertSame(1, $this->tools->stub(self::PHP_LINT)->runs, 'a fail-fast run stops at the failing phase');
    }

    #[Test]
    public function theRetryWarningIsPrintedWhenAToolWasRetried(): void
    {
        $this->tools->register(new StubTool(self::PHP_LINT, ToolResultDto::failed('once'), ToolResultDto::passed()));
        $context = $this->factory->context($this->factory->builder(readOnly: false, aggregate: false)->build());

        self::assertSame(0, $this->pipeline(retry: true)->run($context));
        self::assertStringContainsString('WARNING - RAN WITH RETRIES', $this->factory->output->fetch());
    }

    #[Test]
    public function aToolThatThrowsReleasesTheLockAndTheExceptionPropagates(): void
    {
        $this->tools->register(new class implements ToolInterface {
            public function name(): string
            {
                return 'phpLint';
            }

            public function identifier(): string
            {
                return 'phpqaci.phpLint';
            }

            public function run(ToolContext $context): ToolResultDto
            {
                throw new RuntimeException('the lane crashed');
            }
        });
        $context = $this->factory->context($this->factory->builder(readOnly: false, aggregate: false, singleTool: self::PHP_LINT)->build());

        try {
            $this->pipeline()->run($context);
            self::fail('the crash must propagate');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('the lane crashed', $runtimeException->getMessage());
        }

        self::assertNull($this->lockRecord(), 'a crash inside the locked section must not leave the lock behind');
    }

    /** A segmentation fault halts the run, even one that would carry on past a failure, and names the lane. */
    #[Test]
    public function aSegfaultInALaneHaltsEvenAnAggregateRun(): void
    {
        $this->tools->register(new SignalledChildTool(self::PHP_LINT, $this->signalled, \SIGSEGV));
        $context = $this->factory->context($this->factory->builder(readOnly: true, aggregate: true)->build());

        self::assertSame(1, $this->pipeline()->run($context));

        $printed = $this->factory->output->fetch();
        self::assertStringContainsString("\nHALTED: phpLint crashed with a segmentation fault, so no further tool runs.\n" . \sprintf(self::SEGFAULT_LINE, \dirname(__DIR__, 4) . '/docs/segfaults.md') . "\n", $printed);
        self::assertStringNotContainsString('[composerRequireChecker ran]', $printed);
        self::assertStringNotContainsString('continuing (aggregate mode)', $printed);
        self::assertStringContainsString('          - phpLint', $printed);
        self::assertNull($this->lockRecord());
    }

    /** Another signal crashes the lane, and an aggregate run carries on as after any crash. */
    #[Test]
    public function aLaneKilledByAnotherSignalCrashesWithoutHaltingAnAggregateRun(): void
    {
        $this->tools->register(new SignalledChildTool(self::PHP_LINT, $this->signalled, \SIGKILL));
        $context = $this->factory->context($this->factory->builder(readOnly: true, aggregate: true)->build());

        self::assertSame(1, $this->pipeline()->run($context));

        $printed = $this->factory->output->fetch();
        self::assertStringContainsString('phpLint crashed: `fake phpLint` ended with exit code 137, killed by signal 9', $printed);
        self::assertStringContainsString('>>> phpLint FAILED — continuing (aggregate mode)', $printed);
        self::assertStringContainsString('[infection ran]', $printed);
        self::assertStringNotContainsString(self::HALTED, $printed);
    }

    /** phpcpd cannot fail a run, but a segfault in it is never accepted in a passing one. */
    #[Test]
    public function aSegfaultInTheCopyPasteReportFailsTheRun(): void
    {
        $this->tools->register(new SignalledChildTool('phpcpd', $this->signalled, \SIGSEGV));
        $context = $this->factory->context($this->factory->builder(readOnly: false, aggregate: false)->build());

        self::assertSame(1, $this->pipeline()->run($context));
        self::assertStringContainsString("\nHALTED: phpcpd crashed with a segmentation fault, so no further tool runs.\n", $this->factory->output->fetch());
    }

    /** A start-up probe killed by a signal before the pipeline began stops it before any tool or lock. */
    #[Test]
    public function aChildSignalledWhileTheRunWasPreparedHaltsItBeforeAnyTool(): void
    {
        $this->signalled->record(new SignalledChildDto('php -r probe', \SIGSEGV));
        $context = $this->factory->context($this->factory->builder(readOnly: false, aggregate: false)->build());

        self::assertSame(1, $this->pipeline()->run($context));

        self::assertSame(
            "\nHALTED: a process run while the run was being prepared was killed by a signal, so the run stops here:\n"
            . '  `php -r probe` ended with exit code 139, killed by signal 11 (SIGSEGV): ' . ChildExit::segfaultAdvice() . "\n",
            $this->factory->output->fetch(),
        );
        self::assertSame(0, $this->tools->stub(self::RECTOR)->runs);
        self::assertNull($this->lockRecord());
    }

    /** A hook's child killed by a signal stops the run there; a hook fails a run by throwing, and this one could not. */
    #[Test]
    #[TestWith(['hookPre', 'by the pre hook', 0])]
    #[TestWith(['hookPost', 'by the post hook', 1])]
    public function aChildOfAHookASignalKilledHaltsTheRun(string $hook, string $where, int $rectorRuns): void
    {
        $this->factory->project->write('qaConfig/' . $hook . '.php', <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
            use LTS\PHPQA\Pipeline\Tool\ToolContext;

            return static function (ToolContext $context): void {
                $context->processes->run(new ProcessSpecDto(['hook-child'], '/'));
            };
            PHP_WRAP);
        $this->factory->processes->willRun(function (ProcessSpecDto $spec): ProcessResultDto {
            $this->signalled->record(new SignalledChildDto($spec->commandLine(), \SIGTERM));

            return new ProcessResultDto(143, '', '', \SIGTERM);
        });
        $context = $this->factory->context($this->factory->builder(readOnly: false, aggregate: false)->build());

        self::assertSame(1, $this->pipeline()->run($context));

        self::assertStringContainsString(
            \sprintf("\nHALTED: a process run %s was killed by a signal, so the run stops here:\n  `hook-child` ended with exit code 143, killed by signal 15 (SIGTERM)\n", $where),
            $this->factory->output->fetch(),
        );
        self::assertSame($rectorRuns, $this->tools->stub(self::RECTOR)->runs);
        self::assertNull($this->lockRecord());
    }

    /**
     * The holder record, which only release() clears. Whether the flock is
     * free proves nothing here: the pipeline's RunLock is garbage once run()
     * returns, and closing its file drops the flock either way.
     */
    private function lockRecord(): ?LockInfoDto
    {
        return RunLock::forProject($this->factory->project->path, $this->factory->output)->current();
    }

    private function pipeline(bool $retry = false): Pipeline
    {
        $prompt = $retry ? new AlwaysRetry() : new NonInteractiveRetryPrompt();

        return new Pipeline(
            registry: ToolRegistry::shipped(),
            executor: new ToolExecutor($this->tools, $prompt, $this->factory->output, $this->signalled),
            lock: RunLock::forProject($this->factory->project->path, $this->factory->output),
            hooks: new HookRunner(),
            directories: new DirectoryPreparer(),
            phars: new PharToolsVerifier(),
            aggregate: new AggregateReport($this->factory->output),
            output: $this->factory->output,
            hostname: 'test-host',
            pid: 4242,
            signalled: $this->signalled,
        );
    }
}
