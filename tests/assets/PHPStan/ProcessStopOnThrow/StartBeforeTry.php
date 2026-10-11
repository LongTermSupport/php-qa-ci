<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

use Symfony\Component\Process\Process;

/**
 * start() as the statement immediately before a try whose finally stops the
 * process: with no callback, nothing of the caller's runs between start()
 * returning and the try being entered, so it is clean. A callback can run
 * inside start(), and a statement in between can throw, so those are reported.
 */
final class StartBeforeTry
{
    public function __construct(private readonly Process $process)
    {
    }

    public function startImmediatelyBeforeAStoppingTry(Process $process): void
    {
        $process->start();
        try {
            $this->work();
            $process->wait();
        } finally {
            $process->stop(0);
        }
    }

    public function propertyStartImmediatelyBeforeAStoppingTry(): void
    {
        $this->process?->start();

        try {
            $this->process->wait();
        } finally {
            $this->process->stop(0);
        }
    }

    /** At the top of a branch: the statement list is the if's, not the method's. */
    public function startInABranchImmediatelyBeforeAStoppingTry(Process $process, bool $go): void
    {
        if ($go) {
            $process->start(null);
            try {
                $process->wait();
            } finally {
                $process->stop(0);
            }
        }
    }

    public function startWithACallbackBeforeAStoppingTry(Process $process): void
    {
        $process->start(static function (string $type, string $buffer): void {
            echo $buffer;
        });
        try {
            $process->wait();
        } finally {
            $process->stop(0);
        }
    }

    public function aStatementBetweenTheStartAndTheTry(Process $process): void
    {
        $process->start();
        $this->work();
        try {
            $process->wait();
        } finally {
            $process->stop(0);
        }
    }

    public function startBeforeATryThatStopsAnother(Process $process, Process $other): void
    {
        $process->start();
        try {
            $process->wait();
        } finally {
            $other->stop(0);
        }
    }

    public function startBeforeATryWithNoFinally(Process $process): void
    {
        $process->start();
        try {
            $process->wait();
        } catch (\RuntimeException $exception) {
            $process->stop(0);

            throw $exception;
        }
    }

    /** The try comes first: a start after it is not guarded by it. */
    public function startAfterAStoppingTry(Process $process): void
    {
        try {
            $this->work();
        } finally {
            $process->stop(0);
        }
        $process->start();
    }

    /** Every block's statement list is read: each start() below is clean. */
    public function everyKindOfBlock(Process $process, int $kind, iterable $items): void
    {
        if (0 === $kind) {
            $this->work();
        } elseif (1 === $kind) {
            $process->start();
            try {
                $process->wait();
            } finally {
                $process->stop(0);
            }
        } else {
            $process->start();
            try {
                $process->wait();
            } finally {
                $process->stop(0);
            }
        }

        try {
            $process->start();
            try {
                $process->wait();
            } finally {
                $process->stop(0);
            }
        } catch (\RuntimeException) {
            $process->start();
            try {
                $process->wait();
            } finally {
                $process->stop(0);
            }
        } finally {
            $process->start();
            try {
                $process->wait();
            } finally {
                $process->stop(0);
            }
        }

        switch ($kind) {
            case 2:
                $process->start();
                try {
                    $process->wait();
                } finally {
                    $process->stop(0);
                }
        }

        foreach ($items as $item) {
            $process->start();
            try {
                $process->wait();
            } finally {
                $process->stop(0);
            }
        }

        for ($i = 0; $i < $kind; ++$i) {
            $process->start();
            try {
                $process->wait();
            } finally {
                $process->stop(0);
            }
        }

        while ($kind-- > 3) {
            $process->start();
            try {
                $process->wait();
            } finally {
                $process->stop(0);
            }
        }

        do {
            $process->start();
            try {
                $process->wait();
            } finally {
                $process->stop(0);
            }
        } while ($kind-- > 4);

        {
            $process->start();
            try {
                $process->wait();
            } finally {
                $process->stop(0);
            }
        }

        $closure = static function () use ($process): void {
            $process->start();
            try {
                $process->wait();
            } finally {
                $process->stop(0);
            }
        };
        $closure();
    }

    private function work(): void
    {
    }
}
