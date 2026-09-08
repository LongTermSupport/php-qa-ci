<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool\Exception;

use InvalidArgumentException;

/**
 * @internal
 */
final class UnknownToolException extends InvalidArgumentException
{
    public static function forToken(string $token): self
    {
        return new self('Invalid tool: ' . $token);
    }
}
