<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Runner;

use Closure;
use LTS\PHPQA\Pipeline\Lock\RunLock;
use LTS\PHPQA\Pipeline\Process\RunningProcesses;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ends an interrupted run cleanly. Without it SIGINT (Ctrl-C), SIGTERM (kill,
 * a Claude Code TaskStop), SIGHUP (the terminal went away) and SIGQUIT
 * (Ctrl-\) kill PHP outright: no `finally` runs, the lock record stays behind
 * and the running tool is orphaned with its workers. The handler stops the
 * child tools and everything below them, releases the lock and exits 128 +
 * the signal number, the code a shell reports for a process the signal killed.
 *
 * It is the graceful half of the lock's lifecycle. The flock RunLock holds is
 * the other half, for the deaths no handler sees.
 *
 * ext-pcntl and ext-posix are hard requirements (composer.json) rather than
 * optional extras: a QA run that cannot clean up after Ctrl-C is the defect
 * this class exists to remove, so a host without them is told at install time.
 *
 * @internal
 */
final class RunInterruptHandler
{
    /**
     * The catchable ways a run is ended from outside.
     *
     * @var list<int>
     */
    public const array SIGNALS = [\SIGINT, \SIGTERM, \SIGHUP, \SIGQUIT];

    /** How long a child tool gets to exit after SIGTERM before it is killed. */
    private const float CHILD_GRACE_SECONDS = 5.0;

    /** @var array<int, mixed> the dispositions install() replaced, by signal */
    private array $previous = [];

    private bool $previousAsync = false;

    /**
     * @param Closure(int): never $terminate ends the process with the given code
     */
    public function __construct(
        private readonly RunLock $lock,
        private readonly RunningProcesses $children,
        private readonly OutputInterface $output,
        private readonly Closure $terminate = static function (int $exitCode): never {
            exit($exitCode);
        },
    ) {
    }

    /**
     * Async signals, so a signal is handled while the run is blocked waiting
     * on a child rather than only between ticks.
     */
    public function install(): void
    {
        $this->previousAsync = pcntl_async_signals(true);
        foreach (self::SIGNALS as $signal) {
            $this->previous[$signal] = pcntl_signal_get_handler($signal);
            \Safe\pcntl_signal($signal, $this->handle(...));
        }
    }

    public function uninstall(): void
    {
        foreach ($this->previous as $signal => $handler) {
            \Safe\pcntl_signal($signal, \is_callable($handler) || \is_int($handler) ? $handler : \SIG_DFL);
        }

        $this->previous = [];
        pcntl_async_signals($this->previousAsync);
    }

    /**
     * Stop the children, release the lock, terminate. The console is the last
     * thing trusted: after SIGHUP it may be gone, so a failed write still ends
     * in the release and the exit code.
     */
    public function handle(int $signal): void
    {
        $exitCode = 128 + $signal;

        try {
            $stopped = $this->children->stopAll(self::CHILD_GRACE_SECONDS);

            try {
                $this->output->writeln('');
                $this->output->writeln(\sprintf('[QA] Interrupted by %s; stopped %d running tool process(es).', $this->name($signal), $stopped));
            } finally {
                $this->lock->release($exitCode);
            }
        } finally {
            ($this->terminate)($exitCode);
        }
    }

    private function name(int $signal): string
    {
        return match ($signal) {
            \SIGINT  => 'SIGINT',
            \SIGTERM => 'SIGTERM',
            \SIGHUP  => 'SIGHUP',
            \SIGQUIT => 'SIGQUIT',
            default  => 'signal ' . $signal,
        };
    }
}
