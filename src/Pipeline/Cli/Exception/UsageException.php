<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Cli\Exception;

use InvalidArgumentException;

/**
 * The command line could not be honoured. The message is printed, followed
 * by the usage text when $showUsage is set, and the process exits 1.
 *
 * @internal
 */
final class UsageException extends InvalidArgumentException
{
    public function __construct(string $message, public readonly bool $showUsage)
    {
        parent::__construct($message);
    }

    public static function help(): self
    {
        return new self('', true);
    }

    public static function withUsage(string $message): self
    {
        return new self($message, true);
    }

    public static function plain(string $message): self
    {
        return new self($message, false);
    }
}
