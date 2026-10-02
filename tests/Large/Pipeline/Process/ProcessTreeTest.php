<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline\Process;

use InvalidArgumentException;
use LTS\PHPQA\Pipeline\Process\ProcessTree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Real processes read from the real /proc.
 *
 * @internal
 */
#[CoversClass(ProcessTree::class)]
#[Large]
final class ProcessTreeTest extends TestCase
{
    private ?Process $parent = null;

    protected function tearDown(): void
    {
        $pid = $this->parent?->getPid();
        if (null !== $pid) {
            foreach (new ProcessTree()->descendantsOf($pid) as $descendant) {
                \Safe\posix_kill($descendant, \SIGKILL);
            }
        }

        $this->parent?->stop(0);
    }

    /**
     * Symfony reports a finished process's pid as null, and (int) null is 0:
     * the parent of init. Its descendants are every process on the host, and
     * a caller that went on to signal them would take the machine down with
     * the run.
     */
    #[Test]
    public function theRootOfTheProcessTableIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProcessTree()->descendantsOf(0);
    }

    #[Test]
    public function initIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProcessTree()->descendantsOf(1);
    }

    #[Test]
    public function theDescendantsOfAProcessAreFoundAtEveryDepth(): void
    {
        [$parent, $reported] = $this->parentWithDescendants();

        $descendants = new ProcessTree()->descendantsOf((int)$parent->getPid());

        foreach ($reported as $pid) {
            self::assertContains($pid, $descendants);
        }

        self::assertNotContains($parent->getPid(), $descendants, 'a process is not its own descendant');
    }

    #[Test]
    public function aProcessWithNoChildrenHasNoDescendants(): void
    {
        $this->parent = new Process(['sleep', '60']);
        $this->parent->start();

        self::assertSame([], new ProcessTree()->descendantsOf((int)$this->parent->getPid()));
    }

    #[Test]
    public function aRunningProcessIsAliveAndAFinishedOneIsNot(): void
    {
        $finished = new Process(['true']);
        $finished->start();

        $finishedPid = (int)$finished->getPid();
        $finished->wait();
        $this->parent = new Process(['sleep', '60']);
        $this->parent->start();

        $tree = new ProcessTree();

        self::assertTrue($tree->isAlive((int)$this->parent->getPid()));
        self::assertFalse($tree->isAlive($finishedPid), 'exited and reaped');
    }

    #[Test]
    public function anExitedButUnreapedProcessIsNotAlive(): void
    {
        $this->parent = new Process(['true']);
        $this->parent->start();

        $pid  = (int)$this->parent->getPid();
        $tree = new ProcessTree();

        $deadline = microtime(true) + 10;
        while (!$this->isZombie($pid) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        self::assertTrue($this->isZombie($pid), 'nothing has reaped it yet, so it is a zombie');
        self::assertFalse($tree->isAlive($pid));
    }

    /**
     * A shell with a `sleep` child and a grandchild `sleep` (under a second
     * shell), each reporting its pid.
     *
     * @return array{Process, list<int>}
     */
    private function parentWithDescendants(): array
    {
        $this->parent = new Process(['sh', '-c', 'sh -c "sleep 60 & echo \$!; wait" & sleep 60 & echo $!; wait']);
        $this->parent->start();

        $deadline = microtime(true) + 10;
        do {
            $pids = array_values(array_map(intval(...), array_filter(explode("\n", $this->parent->getOutput()), static fn (string $line): bool => '' !== $line)));
            if (2 === \count($pids)) {
                return [$this->parent, $pids];
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        self::fail('the descendants never reported their pids: ' . $this->parent->getOutput());
    }

    private function isZombie(int $pid): bool
    {
        $stat = '/proc/' . $pid . '/stat';

        return is_file($stat) && str_contains(\Safe\file_get_contents($stat), ') Z ');
    }
}
