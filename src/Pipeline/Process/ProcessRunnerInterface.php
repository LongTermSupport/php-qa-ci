<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Process;

use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;

/**
 * The one way the pipeline runs an external command. Tools never touch
 * symfony/process directly, so a test substitutes a fake and asserts the
 * exact argv a tool built.
 *
 * @internal
 */
interface ProcessRunnerInterface
{
    public function run(ProcessSpecDto $spec): ProcessResultDto;
}
