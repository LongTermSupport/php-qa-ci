<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool;

use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;

/**
 * One lane of the pipeline. A tool prints its own detail to the context's
 * output and returns how it ended; the runner owns retries, aggregation and
 * exit codes. A tool must never exit the process.
 *
 * @internal
 */
interface ToolInterface
{
    /** The canonical registry name, e.g. "phpstan". */
    public function name(): string;

    /** The stable identifier printed with a failure and resolved by bin/rule-doc. */
    public function identifier(): string;

    public function run(ToolContext $context): ToolResultDto;
}
