<?php

declare(strict_types=1);

namespace LTS\PHPQA\Arkitect\Exception;

use RuntimeException;
use Throwable;

/**
 * The probe produced no verdict: the path or the clause was unusable, or the
 * phar crashed or printed no report. Never a miss, which is a verdict.
 *
 * @internal
 */
final class ProbeFailedException extends RuntimeException
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self($reason, 0, $previous);
    }
}
