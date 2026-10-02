<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LogicException;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\ProcessRunnerInterface;

/**
 * Answers one command with a canned result and records the contents of the
 * file its last argument names AT THE MOMENT it ran, so a test can prove that
 * the file a command checked is the file that was then used.
 */
final class FileCapturingProcessRunner implements ProcessRunnerInterface
{
    public ?ProcessSpecDto $spec = null;

    public string $captured = '';

    public function __construct(private readonly int $exitCode, private readonly string $output = '')
    {
    }

    public function run(ProcessSpecDto $spec): ProcessResultDto
    {
        $file = array_last($spec->command);
        if (null === $file || !is_file($file)) {
            throw new LogicException('FileCapturingProcessRunner expected a file as the last argument of: ' . $spec->commandLine());
        }

        $this->spec     = $spec;
        $this->captured = \Safe\file_get_contents($file);

        return new ProcessResultDto($this->exitCode, $this->output, $this->output);
    }

    public function lastSpec(): ProcessSpecDto
    {
        return $this->spec ?? throw new LogicException('FileCapturingProcessRunner ran nothing');
    }
}
