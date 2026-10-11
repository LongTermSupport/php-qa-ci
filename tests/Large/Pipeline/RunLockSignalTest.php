<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline;

use Iterator;
use LTS\PHPQA\Pipeline\Lock\RunLock;
use LTS\PHPQA\Pipeline\Process\ProcessTree;
use LTS\PHPQA\Tests\Support\FixtureConsumer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/**
 * A real bin/qa over a fixture consumer whose one tool runs a long-lived child
 * (`sleep`, which records its pid first), interrupted the ways a run is
 * interrupted in practice: Ctrl-C, a TaskStop or `kill`, a closed terminal,
 * Ctrl-\, and the one no handler sees, SIGKILL.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class RunLockSignalTest extends TestCase
{
    private const string CHILD_PID_FILE = 'child.pid';

    private const string GRANDCHILD_PID_FILE = 'grandchild.pid';

    private const string SLEEPER = 'sleeper';

    private const int WAIT_SECONDS = 60;

    private FixtureConsumer $consumer;

    private ?Process $qa = null;

    protected function setUp(): void
    {
        $this->consumer = FixtureConsumer::create('qa-signal');
        $this->consumer->dir->write('qaConfig/pipeline.php', <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
            use LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto;
            use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
            use LTS\PHPQA\Pipeline\Tool\PipelineBuilder;
            use LTS\PHPQA\Pipeline\Tool\ToolContext;
            use LTS\PHPQA\Pipeline\Tool\ToolInterface;

            return static fn (PipelineBuilder $pipeline): PipelineBuilder => $pipeline->withTool(
                new ToolDefinitionDto('sleeper', ['sleeper'], 'a tool with a long-lived child', 'linting', false),
                new class implements ToolInterface {
                    public function name(): string { return 'sleeper'; }
                    public function identifier(): string { return 'fixture.sleeper'; }
                    public function run(ToolContext $context): ToolResultDto
                    {
                        $root = $context->config->paths->projectRoot;
                        $context->processes->run(new ProcessSpecDto(['sh', '-c', 'sleep 600 & echo $! > grandchild.pid; echo $$ > child.pid; wait'], $root, streamOutput: false));

                        return ToolResultDto::passed();
                    }
                },
            );
            PHP_WRAP);
    }

    protected function tearDown(): void
    {
        $this->qa?->stop(0);
        foreach ([self::GRANDCHILD_PID_FILE, self::CHILD_PID_FILE] as $file) {
            $pid = $this->pidFrom($file);
            if (null !== $pid && $this->isAlive($pid)) {
                \Safe\posix_kill($pid, \SIGKILL);
            }
        }

        $this->consumer->remove();
    }

    #[Test]
    #[DataProvider('catchableSignals')]
    public function aCatchableSignalStopsTheChildReleasesTheLockAndExitsWithTheConventionalCode(int $signal, int $exitCode): void
    {
        [$qa, $child] = $this->runUntilTheChildIsUp();
        self::assertSame($qa->getPid(), $this->lock()->current()?->pid, 'the run holds the lock while its child runs');

        $qa->signal($signal);
        $qa->wait();

        self::assertSame($exitCode, $qa->getExitCode(), $qa->getOutput() . $qa->getErrorOutput());
        self::assertFalse($this->isAlive($child), 'the child tool was stopped, not orphaned');
        self::assertFalse($this->isAlive((int)$this->pidFrom(self::GRANDCHILD_PID_FILE)), "the tool's own child (a phpstan worker, say) went with it");
        self::assertStringContainsString('[QA] Interrupted by', $qa->getOutput());
        self::assertStringContainsString(\sprintf('(exit %d)', $exitCode), $qa->getOutput(), 'the release reports the exit the run ends with');
        self::assertNull($this->lock()->current(), 'the holder record is cleared');

        $next = $this->lock();
        self::assertTrue($next->acquire('next', '', 'h', 1), 'the next run gets the lock at once');
        $next->release(0);
    }

    /** @return Iterator<string, array{int, int}> */
    public static function catchableSignals(): Iterator
    {
        yield 'SIGINT (Ctrl-C)'                => [\SIGINT, 130];
        yield 'SIGTERM (kill, TaskStop)'       => [\SIGTERM, 143];
        yield 'SIGHUP (the terminal went away)' => [\SIGHUP, 129];
        yield 'SIGQUIT (Ctrl-\)'              => [\SIGQUIT, 131];
    }

    /**
     * SIGKILL runs no handler, so the record stays and the child is orphaned.
     * The flock still goes with the process, and the lock file is opened
     * close-on-exec, so the orphan does not keep it: the next run takes the
     * lock straight away instead of waiting on a pid or a clock.
     */
    #[Test]
    public function aKilledRunLeavesItsRecordButTheNextRunTakesTheLock(): void
    {
        [$qa, $child] = $this->runUntilTheChildIsUp();
        $pid          = $qa->getPid();

        $qa->signal(\SIGKILL);
        $qa->wait();

        self::assertSame(137, $qa->getExitCode());
        self::assertSame($pid, $this->lock()->current()?->pid, "no handler ran, so the dead run's record is still there");
        self::assertTrue($this->isAlive($child), 'the orphaned child is still running, and must not be holding the lock');

        $next = $this->consumer->qa(['QA_READONLY' => '1'], '-t', 'pt');
        $next->run();

        self::assertSame(0, $next->getExitCode(), $next->getOutput() . $next->getErrorOutput());
        self::assertStringContainsString('ended without releasing the lock', $next->getOutput());
        self::assertStringContainsString(\sprintf('pid %d', $pid), $next->getOutput());
    }

    /** @return array{Process, int} the running qa process and its child's pid */
    private function runUntilTheChildIsUp(): array
    {
        // Held by the test, so tearDown() stops it whether or not the test gets that far.
        $this->qa = $this->consumer->qa([], '-t', self::SLEEPER);
        $this->qa->start();

        $deadline = microtime(true) + self::WAIT_SECONDS;
        do {
            $child = $this->pidFrom(self::CHILD_PID_FILE);
            if (null !== $child) {
                return [$this->qa, $child];
            }

            usleep(50_000);
        } while ($this->qa->isRunning() && microtime(true) < $deadline);

        self::fail('the sleeper tool never started its child: ' . $this->qa->getOutput() . $this->qa->getErrorOutput());
    }

    /** The pid the sleeper tool's shell wrote to $relative, once it has. */
    private function pidFrom(string $relative): ?int
    {
        $file = $this->consumer->dir->path . '/' . $relative;
        if (!is_file($file)) {
            return null;
        }

        $pid = (int)trim(\Safe\file_get_contents($file));

        return $pid > 0 ? $pid : null;
    }

    private function isAlive(int $pid): bool
    {
        return new ProcessTree()->isAlive($pid);
    }

    private function lock(): RunLock
    {
        return RunLock::forProject($this->consumer->dir->path, new BufferedOutput());
    }
}
