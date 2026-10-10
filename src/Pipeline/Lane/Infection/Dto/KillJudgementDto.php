<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Infection\Dto;

/**
 * What VacuousKillDetector found in Infection's JSON log: how many killed
 * mutants it judged, and those of them killed although no test ran.
 *
 * @internal
 */
final readonly class KillJudgementDto
{
    /** @param list<VacuousKillDto> $vacuous */
    public function __construct(
        public int $judged,
        public array $vacuous,
    ) {
    }

    /**
     * No test ran for any killed mutant: the suite does not start under
     * Infection. One kill made by a test proves it does, and then a vacuous
     * kill is a mutant that stopped the bootstrap itself. With nothing killed
     * there is nothing to judge.
     */
    public function suiteNeverStarted(): bool
    {
        return $this->judged > 0 && \count($this->vacuous) === $this->judged;
    }
}
