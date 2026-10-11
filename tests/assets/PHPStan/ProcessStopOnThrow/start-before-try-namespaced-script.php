<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow\Script;

use Symfony\Component\Process\Process;

/*
 * A script in a namespace: its top-level statements are the namespace's
 * statement list, read too, so the start() here is clean.
 */

$process = new Process(['sleep', '1']);
$process->start();
try {
    $process->wait();
} finally {
    $process->stop(0);
}
