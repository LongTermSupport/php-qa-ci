<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Runner;

use LTS\PHPQA\ComposerPlugin\ChildExit;
use LTS\PHPQA\Pipeline\Process\Dto\SignalledChildDto;
use LTS\PHPQA\Pipeline\Process\SignalledChildren;
use LTS\PHPQA\Pipeline\Runner\Dto\ExecutionDto;
use LTS\PHPQA\Pipeline\Runner\NonInteractiveRetryPrompt;
use LTS\PHPQA\Pipeline\Runner\RetryPromptInterface;
use LTS\PHPQA\Pipeline\Runner\ToolExecutor;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use LTS\PHPQA\Tests\Support\FakeToolLocator;
use LTS\PHPQA\Tests\Support\SignalledChildTool;
use LTS\PHPQA\Tests\Support\StubTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ToolExecutor::class)]
#[CoversClass(ExecutionDto::class)]
#[CoversClass(NonInteractiveRetryPrompt::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(ToolOutcomeEnum::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[UsesClass(SignalledChildren::class)]
#[UsesClass(SignalledChildDto::class)]
#[UsesClass(ChildExit::class)]
#[Small]
final class ToolExecutorTest extends TestCase
{
    private const string TOOL_LINT = 'lint';

    private const string STAN = 'stan';

    private const string LINT_RAN = "[lint ran]\n";

    private const string DEFENCE_BEFORE_FIX_LINE = 'Defence Before Fix (DBF): https://defence-before-fix.github.io/ - agent prompt: https://defence-before-fix.github.io/defence-before-fix-project-prompt.md';

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
    public function aPassingToolRunsOnceAndReportsNoRetry(): void
    {
        $locator  = new FakeToolLocator()->register(new StubTool(self::TOOL_LINT, ToolResultDto::passed()));
        $executor = new ToolExecutor($locator, new NonInteractiveRetryPrompt(), $this->factory->output);

        $execution = $executor->execute(self::TOOL_LINT, $this->factory->context());

        self::assertTrue($execution->result->isSuccess());
        self::assertFalse($execution->retried);
        self::assertSame(1, $locator->stub(self::TOOL_LINT)->runs);
        self::assertSame(self::LINT_RAN, $this->factory->output->fetch(), 'a pass prints no banner');
    }

    #[Test]
    public function nonInteractivelyAFailureIsFinalAndPrintsTheBanner(): void
    {
        $locator  = new FakeToolLocator()->register(new StubTool(self::TOOL_LINT, ToolResultDto::failed('3 files')));
        $executor = new ToolExecutor($locator, new NonInteractiveRetryPrompt(), $this->factory->output);

        $execution = $executor->execute(self::TOOL_LINT, $this->factory->context());

        self::assertSame(ToolOutcomeEnum::Failed, $execution->result->outcome);
        self::assertFalse($execution->retried);
        self::assertSame(1, $locator->stub(self::TOOL_LINT)->runs);
        self::assertSame(self::LINT_RAN . $this->banner("        lint Failed...\n        3 files\n"), $this->factory->output->fetch());
    }

    /** A failure with no summary prints the banner without a summary line. */
    #[Test]
    public function aFailureWithNoSummaryPrintsTheBannerWithoutASummaryLine(): void
    {
        $locator  = new FakeToolLocator()->register(new StubTool(self::TOOL_LINT, ToolResultDto::failed('')));
        $executor = new ToolExecutor($locator, new NonInteractiveRetryPrompt(), $this->factory->output);

        $executor->execute(self::TOOL_LINT, $this->factory->context());

        self::assertSame(self::LINT_RAN . $this->banner("        lint Failed...\n"), $this->factory->output->fetch());
    }

    #[Test]
    public function interactivelyAFailureIsRetriedUntilItPassesAndTheRetryIsRecorded(): void
    {
        $locator  = new FakeToolLocator()->register(new StubTool(self::TOOL_LINT, ToolResultDto::failed('a'), ToolResultDto::failed('b'), ToolResultDto::passed()));
        $executor = new ToolExecutor($locator, new AlwaysRetry(), $this->factory->output);

        $execution = $executor->execute(self::TOOL_LINT, $this->factory->context());

        self::assertTrue($execution->result->isSuccess());
        self::assertTrue($execution->retried);
        self::assertSame(3, $locator->stub(self::TOOL_LINT)->runs);
    }

    #[Test]
    public function aCrashIsNeverRetriedEvenInteractively(): void
    {
        $locator  = new FakeToolLocator()->register(new StubTool(self::STAN, ToolResultDto::crashed('boom'), ToolResultDto::passed()));
        $executor = new ToolExecutor($locator, new AlwaysRetry(), $this->factory->output);

        $execution = $executor->execute(self::STAN, $this->factory->context());

        self::assertSame(ToolOutcomeEnum::Crashed, $execution->result->outcome);
        self::assertSame(1, $locator->stub(self::STAN)->runs);
    }

    /**
     * A lane whose child a signal killed crashed, whatever the lane made of the child's exit code:
     * the crash names the lane, the command and the signal, and is never retried.
     */
    #[Test]
    public function aLaneWhoseChildASignalKilledCrashedAndSaysWhy(): void
    {
        $signalled = new SignalledChildren();
        $lane      = new SignalledChildTool(self::TOOL_LINT, $signalled, \SIGTERM);
        $executor  = new ToolExecutor(new FakeToolLocator()->register($lane), new AlwaysRetry(), $this->factory->output, $signalled);

        $execution = $executor->execute(self::TOOL_LINT, $this->factory->context());

        $summary = 'lint crashed: `fake lint` ended with exit code 143, killed by signal 15 (SIGTERM)';
        self::assertSame(ToolOutcomeEnum::Crashed, $execution->result->outcome);
        self::assertSame($summary, $execution->result->summary);
        self::assertFalse($execution->segmentationFault);
        self::assertFalse($execution->retried);
        self::assertSame(1, $lane->runs);
        self::assertSame(self::LINT_RAN . $this->banner("        lint Failed...\n        " . $summary . "\n"), $this->factory->output->fetch());
        self::assertSame([], $signalled->take(), 'the crash is taken, so the next lane does not inherit it');
    }

    /** A segmentation fault is marked, so the pipeline can halt on it. */
    #[Test]
    public function aLaneWhoseChildSegfaultedIsMarked(): void
    {
        $signalled = new SignalledChildren();
        $locator   = new FakeToolLocator()->register(new SignalledChildTool(self::STAN, $signalled, \SIGSEGV));
        $executor  = new ToolExecutor($locator, new NonInteractiveRetryPrompt(), $this->factory->output, $signalled);

        $execution = $executor->execute(self::STAN, $this->factory->context());

        self::assertTrue($execution->segmentationFault);
        self::assertSame(
            'stan crashed: `fake stan` ended with exit code 139, killed by signal 11 (SIGSEGV): ' . ChildExit::segfaultAdvice(),
            $execution->result->summary,
        );
    }

    /**
     * A child that exited 139 itself relays a crash below it: the lane crashed with a segfault, and
     * the line says the child exited with the code, never that a signal killed it.
     */
    #[Test]
    public function aLaneWhoseChildRelayedASegfaultIsMarkedAndNotCalledKilled(): void
    {
        $signalled = new SignalledChildren();
        $locator   = new FakeToolLocator()->register(new SignalledChildTool(self::STAN, $signalled, \SIGSEGV, exitedItself: true));
        $executor  = new ToolExecutor($locator, new NonInteractiveRetryPrompt(), $this->factory->output, $signalled);

        $execution = $executor->execute(self::STAN, $this->factory->context());

        self::assertTrue($execution->segmentationFault);
        self::assertTrue($execution->childCrashed);
        self::assertSame('stan crashed: `fake stan` ended with ' . ChildExit::describeExitedItself(139), $execution->result->summary);
        self::assertStringNotContainsString('killed by', $this->factory->output->fetch());
    }

    /** A passing lane that ran no signalled child is not marked. */
    #[Test]
    public function aPassingLaneIsNotMarked(): void
    {
        $locator  = new FakeToolLocator()->register(new StubTool(self::TOOL_LINT, ToolResultDto::passed()));
        $executor = new ToolExecutor($locator, new NonInteractiveRetryPrompt(), $this->factory->output, new SignalledChildren());

        $execution = $executor->execute(self::TOOL_LINT, $this->factory->context());

        self::assertFalse($execution->segmentationFault);
        self::assertFalse($execution->childCrashed);
    }

    /** The whole failure banner around the given heading lines, as the executor prints it. */
    private function banner(string $heading): string
    {
        $rule = "    ==================================================\n";

        return "\n" . $rule . "\n" . $heading . "\n        " . self::DEFENCE_BEFORE_FIX_LINE . "\n\n" . $rule . "\n";
    }
}

/**
 * @internal
 */
final readonly class AlwaysRetry implements RetryPromptInterface
{
    public function shouldRetry(string $label): bool
    {
        return true;
    }
}
