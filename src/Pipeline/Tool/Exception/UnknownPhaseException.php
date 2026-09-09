<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool\Exception;

use InvalidArgumentException;

/**
 * @internal
 */
final class UnknownPhaseException extends InvalidArgumentException
{
    public static function forName(string $name, string ...$known): self
    {
        return new self(\sprintf('Unknown phase: %s (known: %s)', $name, implode(', ', $known)));
    }
}
