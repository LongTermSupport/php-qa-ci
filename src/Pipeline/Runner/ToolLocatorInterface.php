<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Runner;

use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * Turns a canonical tool name into the implementation that runs it: the
 * project's qaConfig/tools/<name>.php override when present, else the
 * shipped lane.
 *
 * @internal
 */
interface ToolLocatorInterface
{
    public function locate(string $name): ToolInterface;
}
