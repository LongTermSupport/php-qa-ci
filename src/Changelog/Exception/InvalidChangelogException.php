<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog\Exception;

use RuntimeException;

/**
 * CHANGELOG.md cannot be read without guessing. Carries every problem found,
 * each with its line number, so one run reports them all.
 *
 * @api
 */
final class InvalidChangelogException extends RuntimeException
{
    /** @param list<string> $problems */
    private function __construct(public readonly array $problems)
    {
        parent::__construct("CHANGELOG.md is invalid:\n  - " . implode("\n  - ", $problems));
    }

    public static function withProblems(string ...$problems): self
    {
        return new self(array_values($problems));
    }
}
