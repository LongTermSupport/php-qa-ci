<?php

declare(strict_types=1);

namespace Consumer;

use Acme\Widget\Internal\Engine;

/**
 * Reaches into the library's @internal namespace — MUST be flagged.
 */
final class BadService
{
    public function __construct(private readonly Engine $engine)
    {
    }

    public function run(): string
    {
        return $this->engine->spin();
    }
}
