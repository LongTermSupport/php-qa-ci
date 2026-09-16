<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Agent;

/**
 * What an agent-mode run concluded, and the exit code that says so.
 *
 * The codes are deliberately all different. An agent loop that cannot tell
 * "the lock was held so nothing ran" from "your file has errors" will either
 * chase findings that were never reported or trust a green that never
 * happened, so contention and a crash each get their own code rather than
 * sharing the generic failure 1.
 *
 * @internal
 */
enum AgentStatusEnum: string
{
    public static function forErrorCount(int $errors): self
    {
        return $errors > 0 ? self::Errors : self::Clean;
    }

    public function exitCode(): int
    {
        return match ($this) {
            self::Clean    => 0,
            self::Errors   => 1,
            self::LockHeld => 2,
            self::Crashed  => 3,
        };
    }

    case Clean = 'clean';

    case Errors = 'errors';

    case Crashed = 'crashed';

    case LockHeld = 'lock-held';
}
