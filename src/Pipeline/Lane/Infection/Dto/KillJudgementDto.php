<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Infection\Dto;

use LogicException;
use RoundingMode;

/**
 * What VacuousKillDetector found in Infection's JSON log: how many killed
 * mutants it judged, those of them killed although no test ran, and, when the
 * log's stats were read, the counts Infection scores from: the detected
 * mutants, and the tested ones (skipped and ignored left out) and those of
 * them a test covers.
 *
 * @internal
 */
final readonly class KillJudgementDto
{
    /** @param list<VacuousKillDto> $vacuous */
    public function __construct(
        public int $judged,
        public array $vacuous,
        public ?int $detected = null,
        public int $tested = 0,
        public int $testedCovered = 0,
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

    /** Infection's MSI with the vacuous kills counted as not detected. */
    public function msiWithoutVacuous(): float
    {
        return $this->share($this->detectedWithoutVacuous(), $this->tested);
    }

    /** Infection's covered-code MSI with the vacuous kills counted as not detected. */
    public function coveredMsiWithoutVacuous(): float
    {
        return $this->share($this->detectedWithoutVacuous(), $this->testedCovered);
    }

    /** Whether Infection would let both scores through without the vacuous kills: it fails a score below its floor, and 0 is no floor. */
    public function floorsHoldWithoutVacuous(int $minMsi, int $minCoveredMsi): bool
    {
        return $this->msiWithoutVacuous() >= $minMsi && $this->coveredMsiWithoutVacuous() >= $minCoveredMsi;
    }

    private function detectedWithoutVacuous(): int
    {
        if (null === $this->detected) {
            throw new LogicException("the scores need the counts from the Infection JSON log's stats, which this judgement was made without");
        }

        return $this->detected - \count($this->vacuous);
    }

    /** As Infection rounds a score: to two places, half up; over no mutants, 0. */
    private function share(int $detected, int $of): float
    {
        return 0 === $of ? 0.0 : round(100 * $detected / $of, 2, RoundingMode::HalfAwayFromZero);
    }
}
