<?php

declare(strict_types=1);

namespace PhpQaCiFixture\HandFormattedExitCode;

use LTS\PHPQA\ComposerPlugin\ChildExit;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;

final class Reports
{
    /** @return list<string|ToolResultDto> */
    public function handFormatted(ProcessResultDto $result): array
    {
        $copy = $result->exitCode;
        $line = 'failed with exit ';
        $line .= $result->exitCode;

        return [
            sprintf('failed (exit %d)', $result->exitCode),
            strval($result->exitCode),
            'failed (exit ' . $result->exitCode . ')',
            "failed (exit {$result->exitCode})",
            'failed with ' . ChildExit::describe($result->exitCode),
            ChildExit::killedBy($result->exitCode),
            ToolResultDto::fromExitCode($result->exitCode, 'Tool'),
            sprintf('failed (exit %d)', $copy),
            (string) $result->exitCode,
            $line,
        ];
    }

    /** A code that may be a signal's, narrowed but not out of the range, is still reported. */
    public function narrowedIntoTheRange(ProcessResultDto $result): string
    {
        if ($result->exitCode > 1) {
            return sprintf('crashed (exit %d)', $result->exitCode);
        }

        return '';
    }

    /** @return list<bool|string|ToolResultDto> */
    public function describedAsItEnded(ProcessResultDto $result, Other $other): array
    {
        $text = 'failed';
        $text .= ' (' . $result->describeExit() . ')';

        return [
            $text,
            sprintf('failed (%s)', $result->describeExit()),
            ToolResultDto::fromProcessResult($result, 'Tool'),
            ChildExit::killedBySignal($result->exitCode),
            ChildExit::isSegmentationFault($result->exitCode),
            $result->exitCode > 1,
            sprintf('other (exit %d)', $other->exitCode),
        ];
    }

    /** A code PHPStan proves is no signal's cannot misstate how the process ended. */
    public function provenNoSignal(ProcessResultDto $result): string
    {
        if (1 === $result->exitCode) {
            return sprintf('failed (exit %d)', $result->exitCode);
        }

        if ($result->exitCode < 129) {
            return 'failed (exit ' . $result->exitCode . ')';
        }

        return '';
    }

    /** A variable of the same name in another function holds no exit code. */
    public function unrelated(int $copy): string
    {
        return sprintf('copied %d files', $copy);
    }
}

final class Other
{
    public int $exitCode = 3;
}
