<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Process;

use Closure;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\ProcessTree;
use LTS\PHPQA\Pipeline\Process\RunningProcesses;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 */
#[CoversClass(SymfonyProcessRunner::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(RunningProcesses::class)]
#[UsesClass(ProcessTree::class)]
#[Small]
final class SymfonyProcessRunnerTest extends TestCase
{
    private const string XDEBUG_MODE = 'XDEBUG_MODE';

    private const string PRINT_MODE = 'echo getenv("XDEBUG_MODE");';

    private const string OFF = 'off';

    private const string COVERAGE = 'coverage';

    // Built by the child, so the echoed command line does not contain it.
    private const string READY = 'rrr';

    // Newlines included: a pseudo-terminal would turn each into \r\n.
    private const string INTERLEAVE = 'echo "o1\n"; fflush(STDOUT); usleep(100000); fwrite(STDERR, "e1\n"); usleep(100000); echo "o2\n"; exit(3);';

    private const string WAIT_THEN_FINISH = 'echo str_repeat("r", 3), "\n"; fflush(STDOUT); sleep(5); echo "done";';

    #[Test]
    public function aChildThatNamesNoXdebugModeGetsOffWhateverTheParentHas(): void
    {
        $env = SymfonyProcessRunner::childEnvironment([self::XDEBUG_MODE => self::COVERAGE, 'FOO' => 'bar'], []);

        self::assertSame(self::OFF, $env[self::XDEBUG_MODE]);
        self::assertSame('bar', $env['FOO']);
    }

    #[Test]
    public function aChildThatNamesAnXdebugModeKeepsIt(): void
    {
        $env = SymfonyProcessRunner::childEnvironment([self::XDEBUG_MODE => self::OFF], [self::XDEBUG_MODE => self::COVERAGE]);

        self::assertSame(self::COVERAGE, $env[self::XDEBUG_MODE]);
    }

    #[Test]
    public function aRealChildWithNoEnvironmentOfItsOwnSeesXdebugOff(): void
    {
        $result = new SymfonyProcessRunner(new NullOutput(), new RunningProcesses())
            ->run(new ProcessSpecDto([\PHP_BINARY, '-r', self::PRINT_MODE], \Safe\getcwd(), streamOutput: false))
        ;

        self::assertSame(self::OFF, trim($result->output));
    }

    #[Test]
    public function aRealChildAskingForCoverageSeesCoverage(): void
    {
        $result = new SymfonyProcessRunner(new NullOutput(), new RunningProcesses())
            ->run(new ProcessSpecDto([\PHP_BINARY, '-r', self::PRINT_MODE], \Safe\getcwd(), [self::XDEBUG_MODE => self::COVERAGE], streamOutput: false))
        ;

        // A live Xdebug may print the JIT warning ahead of the answer; the answer is the last word.
        self::assertStringEndsWith(self::COVERAGE, trim($result->output));
    }

    /** The command line first, so a log shows what ran, then the child's output exactly as it arrived. */
    #[Test]
    public function itEchoesTheCommandThenStreamsTheChildsOutputRaw(): void
    {
        $output = new BufferedOutput();
        $spec   = new ProcessSpecDto([\PHP_BINARY, '-r', 'echo "a"; fflush(STDOUT); usleep(100000); fwrite(STDERR, "b");'], \Safe\getcwd());

        new SymfonyProcessRunner($output, new RunningProcesses())->run($spec);

        self::assertSame('++ ' . $spec->commandLine() . "\nab", $output->fetch());
    }

    /** Every chunk of both streams is captured, through plain pipes, and stdout on its own beside them. */
    #[Test]
    public function itCapturesEveryChunkOfBothStreamsAndStdoutAlone(): void
    {
        $result = new SymfonyProcessRunner(new NullOutput(), new RunningProcesses())
            ->run(new ProcessSpecDto([\PHP_BINARY, '-r', self::INTERLEAVE], \Safe\getcwd(), streamOutput: false))
        ;

        // Across the two pipes the order depends on scheduling, so the combined capture is compared as a set of lines.
        $lines = explode("\n", rtrim($result->output, "\n"));
        sort($lines);
        self::assertSame(['e1', 'o1', 'o2'], $lines);
        self::assertSame("o1\no2\n", $result->stdout);
        self::assertSame(3, $result->exitCode);
    }

    /** A low-priority child runs under `nice -n 19`, with its own arguments passed through intact. */
    #[Test]
    public function aLowPriorityChildRunsNicedAndAnOrdinaryOneDoesNot(): void
    {
        $runner = new SymfonyProcessRunner(new NullOutput(), new RunningProcesses());
        $print  = [\PHP_BINARY, '-r', 'echo pcntl_getpriority();'];

        self::assertSame('19', $runner->run(new ProcessSpecDto($print, \Safe\getcwd(), streamOutput: false, lowPriority: true))->stdout);
        self::assertSame((string)\Safe\pcntl_getpriority(), $runner->run(new ProcessSpecDto($print, \Safe\getcwd(), streamOutput: false))->stdout);
    }

    /**
     * The same, seen through a stand-in `nice` that prints its arguments: under Infection the test
     * process already runs at 19, where a niced child and an ordinary one report the same priority.
     */
    #[Test]
    public function onlyALowPriorityChildIsHandedToNiceWithItsArgumentsIntact(): void
    {
        $path = getenv('PATH');
        self::assertIsString($path);
        $bin = TempDir::create('fake-nice');

        try {
            \Safe\chmod($bin->write('nice', "#!/bin/sh\nprintf '%s|' \"\$@\"\n"), 0o755);
            // The executable is looked up on this process's PATH, not on the child's environment.
            \Safe\putenv('PATH=' . $bin->path . ':' . $path);
            $runner = new SymfonyProcessRunner(new NullOutput(), new RunningProcesses());
            $print  = [\PHP_BINARY, '-r', 'echo "plain";'];

            self::assertSame('-n|19|' . \PHP_BINARY . '|-r|echo "plain";|', $runner->run(new ProcessSpecDto($print, \Safe\getcwd(), streamOutput: false, lowPriority: true))->stdout);
            self::assertSame('plain', $runner->run(new ProcessSpecDto($print, \Safe\getcwd(), streamOutput: false))->stdout);
        } finally {
            \Safe\putenv('PATH=' . $path);
            $bin->remove();
        }
    }

    /** While it runs, the child is registered, so an interrupt arriving mid-run can stop it. */
    #[Test]
    public function aRunningChildIsRegisteredSoAnInterruptCanStopIt(): void
    {
        $running = new RunningProcesses();
        $stopped = null;
        $output  = new class(static function (string $message) use ($running, &$stopped): void {
            if (null === $stopped && str_contains($message, self::READY)) {
                $stopped = $running->stopAll(5.0);
            }
        }) extends BufferedOutput {
            public function __construct(private readonly Closure $onWrite)
            {
                parent::__construct();
            }

            protected function doWrite(string $message, bool $newline): void
            {
                ($this->onWrite)($message);
                parent::doWrite($message, $newline);
            }
        };

        $result = new SymfonyProcessRunner($output, $running)
            ->run(new ProcessSpecDto([\PHP_BINARY, '-r', self::WAIT_THEN_FINISH], \Safe\getcwd(), timeout: 30.0))
        ;

        self::assertSame(1, $stopped, 'the child was registered while it ran');
        self::assertStringNotContainsString('done', $result->output, 'stopping it ended the child before it finished');
    }

    /** A run that ends by throwing still deregisters its child, and stops it: nothing outlives the run. */
    #[Test]
    public function aRunThatThrowsLeavesNothingRegistered(): void
    {
        $running = new RunningProcesses();
        $output  = new class(self::READY) extends BufferedOutput {
            public function __construct(private readonly string $failOn)
            {
                parent::__construct();
            }

            protected function doWrite(string $message, bool $newline): void
            {
                if (str_contains($message, $this->failOn)) {
                    throw new RuntimeException('the console went away');
                }

                parent::doWrite($message, $newline);
            }
        };

        try {
            new SymfonyProcessRunner($output, $running)
                ->run(new ProcessSpecDto([\PHP_BINARY, '-r', self::WAIT_THEN_FINISH], \Safe\getcwd(), timeout: 30.0))
            ;
            self::fail('the failed write must propagate');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('the console went away', $runtimeException->getMessage());
        }

        self::assertSame(0, $running->stopAll(0.0), 'nothing is left registered once the run has ended');

        $tree = new ProcessTree();
        self::assertSame([], array_values(array_filter($tree->descendantsOf(\Safe\getmypid()), $tree->isAlive(...))), 'the child was stopped, not left running unregistered');
    }
}
