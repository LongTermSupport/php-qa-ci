<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Runner;

use LTS\PHPQA\Pipeline\Runner\ConsoleRetryPrompt;
use LTS\PHPQA\Pipeline\Runner\Dto\ExecutionDto;
use LTS\PHPQA\Pipeline\Runner\NonInteractiveRetryPrompt;
use LTS\PHPQA\Pipeline\Runner\RetryPromptInterface;
use LTS\PHPQA\Pipeline\Runner\ToolExecutor;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use LTS\PHPQA\Tests\Support\FakeToolLocator;
use LTS\PHPQA\Tests\Support\StubTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @internal
 */
#[CoversClass(ToolExecutor::class)]
#[CoversClass(ExecutionDto::class)]
#[CoversClass(NonInteractiveRetryPrompt::class)]
#[CoversClass(ConsoleRetryPrompt::class)]
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
#[Small]
final class ToolExecutorTest extends TestCase
{
    private const string TOOL_LINT = 'lint';

    private const string STAN = 'stan';

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
        $printed = $this->factory->output->fetch();
        self::assertStringContainsString('lint Failed...', $printed);
        self::assertStringContainsString('3 files', $printed);
        self::assertStringContainsString('Defence Before Fix: https://defence-before-fix.github.io/', $printed);
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

    #[Test]
    public function theConsolePromptAcceptsYAndNAndRejectsAnythingElse(): void
    {
        $output = new BufferedOutput();
        $stdin  = \Safe\fopen('php://memory', 'r+');
        \Safe\fwrite($stdin, "maybe\ny\nn\n");
        \Safe\rewind($stdin);
        $prompt = new ConsoleRetryPrompt($output, $stdin);

        self::assertTrue($prompt->shouldRetry(self::TOOL_LINT));
        self::assertStringContainsString('invalid choice: maybe', $output->fetch());
        self::assertFalse($prompt->shouldRetry(self::TOOL_LINT));
        self::assertFalse($prompt->shouldRetry(self::TOOL_LINT), 'end of input is a no');
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
