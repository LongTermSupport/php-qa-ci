<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\ComposerChecks;

/**
 * What the cooldown assertion found: problems (each says what is wrong and the
 * correct construction) and the security overrides it lists on every run, so
 * none can hide.
 *
 * @internal
 */
final readonly class CooldownReport
{
    /**
     * @param list<string> $problems
     * @param list<string> $overrides one line per ignore entry outside the internal vendors
     */
    public function __construct(
        public array $problems = [],
        public array $overrides = [],
    ) {
    }

    public function merge(self $other): self
    {
        return new self([...$this->problems, ...$other->problems], [...$this->overrides, ...$other->overrides]);
    }

    public function hasProblems(): bool
    {
        return [] !== $this->problems;
    }
}
