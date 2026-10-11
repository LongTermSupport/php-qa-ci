<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * A script with no namespace: its top-level statements, a function's and a
 * declare block's are read too, so each start() here is clean.
 */

function startsBeforeAStoppingTry(Process $process): void
{
    $process->start();
    try {
        $process->wait();
    } finally {
        $process->stop(0);
    }
}

$process = new Process(['sleep', '1']);
$process->start();
try {
    $process->wait();
} finally {
    $process->stop(0);
}

declare(ticks=1) {
    $process->start();
    try {
        $process->wait();
    } finally {
        $process->stop(0);
    }
}
