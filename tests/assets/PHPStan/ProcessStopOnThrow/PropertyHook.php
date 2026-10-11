<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

use Symfony\Component\Process\Process;

/** A property hook's body is a statement list like a method's, so its try statements guard too. */
final class PropertyHook
{
    public string $guarded {
        get {
            $process = new Process(['true']);
            try {
                $process->start();
                $process->wait();
            } finally {
                $process->stop(0);
            }

            return 'guarded';
        }
    }

    public string $startedBeforeTheTry {
        set(string $value) {
            $process = new Process(['true']);
            $process->start();
            try {
                $process->wait();
            } finally {
                $process->stop(0);
            }

            $this->startedBeforeTheTry = $value;
        }
    }

    public string $unguarded {
        get {
            $process = new Process(['true']);
            $process->start();
            $process->wait();

            return 'unguarded';
        }
    }
}
