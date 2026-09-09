<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool\Exception;

use InvalidArgumentException;

/**
 * Thrown from PipelineBuilder when a tool, an insertion point or a phase order
 * names a phase the pipeline does not have.
 *
 * @api
 */
final class UnknownPhaseException extends InvalidArgumentException
{
    public static function forName(string $name, string ...$known): self
    {
        return new self(\sprintf('Unknown phase: %s (known: %s)', $name, implode(', ', $known)));
    }
}
