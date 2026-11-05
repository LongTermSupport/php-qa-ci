<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline\Lock;

use LTS\PHPQA\Pipeline\Lock\RunLock;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Several first runs start at the same instant against a lock directory that
 * does not exist yet. Whichever loses the race to create it must wait its turn
 * like any other contender, not die on the directory it lost.
 *
 * @internal
 */
#[CoversClass(RunLock::class)]
#[Large]
final class FirstRunRaceTest extends TestCase
{
    private const int CONTENDERS = 6;

    private const int ROUNDS = 2;

    private TempDir $work;

    protected function setUp(): void
    {
        $this->work = TempDir::create('phpqa-lock-race');
        $this->work->write('contender.php', <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            use LTS\PHPQA\Pipeline\Lock\RunLock;
            use Symfony\Component\Console\Output\NullOutput;

            require $argv[1];

            [, , $lockDir, $startAt, $pid] = $argv;
            while (microtime(true) < (float) $startAt) {
            }

            $lock = new RunLock($lockDir, new NullOutput(), maxWaitSeconds: 60);
            if (!$lock->acquire('race', '', 'h', (int) $pid)) {
                fwrite(STDERR, "gave up\n");
                exit(75);
            }

            usleep(20_000);
            $lock->release(0);
            PHP_WRAP);
    }

    protected function tearDown(): void
    {
        $this->work->remove();
    }

    #[Test]
    public function firstRunsThatRaceToCreateTheLockDirectoryAllGetTheLockInTurn(): void
    {
        $autoload = \Safe\realpath(__DIR__ . '/../../../../vendor/autoload.php');

        for ($round = 0; $round < self::ROUNDS; ++$round) {
            $lockDir = $this->work->path . '/round-' . $round . '/lock';
            $startAt = microtime(true) + 1.0;

            $processes = [];
            for ($i = 1; $i <= self::CONTENDERS; ++$i) {
                $processes[] = new Process([\PHP_BINARY, '-d', 'display_errors=stderr', $this->work->path . '/contender.php', $autoload, $lockDir, (string)$startAt, (string)$i]);
            }

            $this->runTogether(...$processes);
        }
    }

    private function runTogether(Process ...$processes): void
    {
        try {
            foreach ($processes as $process) {
                $process->start();
            }

            foreach ($processes as $process) {
                $process->wait();
                self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            }
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
        }
    }
}
