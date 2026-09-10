<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Runner;

use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Lock\RunLock;
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
use LTS\PHPQA\Tests\Support\ContextFactory;
use LTS\PHPQA\Tests\Support\FakeToolLocator;
use LTS\PHPQA\Tests\Support\StubTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
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
#[UsesClass(\LTS\PHPQA\Pipeline\Lock\Dto\LockInfoDto::class)]
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
#[Small]
final class PipelineTest extends TestCase
{
    private const string ALL_TESTS_PASSING = 'ALL TESTS PASSING';

    private const string PHP_LINT = 'phpLint';

    private const string PHPSTAN = 'phpstan';

    private const string TWIG_LINT = 'twigLint';

    private const string PARAM_TYPES = 'types';

    private ContextFactory $factory;

    private FakeToolLocator $tools;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        $this->tools   = new FakeToolLocator();
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
        $expectedOrder = ['rector', 'phpCsFixer', 'twigCsFixer', 'psr4Validate', 'composerChecks', 'packageType', 'configTemplateIgnoreList', 'infectionConfigSourceDirs', 'versionPins', 'phpStrictTypes', self::PHP_LINT, 'composerRequireChecker', 'composerDependencyAnalyser', 'markdownLinks', 'yamlLint', 'branchNamePolicy', 'phpstanIgnoreJustification', self::PHPSTAN, 'deadCode', 'phpArkitect', 'sensitiveParameterUsage', 'phpunit', 'infection', 'phpcpd'];
        \Safe\preg_match_all('/\[(\w+) ran\]/', $printed, $ran);
        self::assertSame($expectedOrder, $ran[1] ?? []);
        self::assertFileDoesNotExist($this->factory->project->path . '/qaConfig/.qa-lock/qa-running.lock', 'the lock is released');
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

        self::assertSame(1, $this->pipeline()->run($context));
        self::assertStringContainsString('Another QA run holds the lock', $this->factory->output->fetch());
        self::assertSame(0, $this->tools->stub('rector')->runs);
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

        self::assertFileDoesNotExist($this->factory->project->path . '/qaConfig/.qa-lock/qa-running.lock', 'a crash inside the locked section must not leave the lock behind');
    }

    private function pipeline(bool $retry = false): Pipeline
    {
        $prompt = $retry ? new AlwaysRetry() : new NonInteractiveRetryPrompt();

        return new Pipeline(
            registry: ToolRegistry::shipped(),
            executor: new ToolExecutor($this->tools, $prompt, $this->factory->output),
            lock: RunLock::forProject($this->factory->project->path, $this->factory->output),
            hooks: new HookRunner(),
            directories: new DirectoryPreparer(),
            phars: new PharToolsVerifier(),
            aggregate: new AggregateReport($this->factory->output),
            output: $this->factory->output,
            hostname: 'test-host',
            pid: 4242,
        );
    }
}
