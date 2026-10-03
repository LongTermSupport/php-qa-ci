<?php

declare(strict_types=1);

namespace LTS\PHPQA\DefectRecord\Dto;

/**
 * The conclusion that a Defect is no Instance of a detectable pattern (method
 * specification section 2): the Defect, where it was found, the one-sentence
 * conclusion, and the independent techniques tried at expressing the Rule.
 *
 * @internal
 */
final readonly class NoPatternConclusionDto
{
    /** @param list<string> $techniques at least two, each different */
    public function __construct(
        public string $defect,
        public string $found,
        public string $conclusion,
        public array $techniques,
    ) {
    }
}
