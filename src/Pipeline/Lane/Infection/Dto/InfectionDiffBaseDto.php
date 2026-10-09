<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Infection\Dto;

/**
 * What one Infection run mutates: the git ref the changed files are read
 * against, or none for a full run; whether uncommitted work under src/tests
 * refuses the run (an explicit base) or is reported and left out of scope
 * (auto mode); and the one line the lane prints to say which.
 *
 * @internal
 */
final readonly class InfectionDiffBaseDto
{
    private function __construct(
        public ?string $ref,
        public bool $strict,
        public string $description,
    ) {
    }

    public static function fullRun(string $description): self
    {
        return new self(null, false, $description);
    }

    public static function diff(string $ref, bool $strict, string $description): self
    {
        return new self($ref, $strict, $description);
    }

    public function isFullRun(): bool
    {
        return null === $this->ref;
    }
}
