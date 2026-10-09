<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config\Dto;

use LogicException;
use LTS\PHPQA\Pipeline\Config\InfectionDiffModeEnum;

/**
 * What the Infection lane runs with.
 *
 * `$enabled` is forced off when coverage is off, since mutation testing cannot
 * run without it. The MSI floors are percentages. `$diffMode` says how much
 * of the source is mutated (InfectionDiffModeEnum); `$diffBase` is the git ref
 * of a Ref run and null otherwise. In any diff run `$diffCoveredMsi` is the
 * floor that applies instead of the two whole-codebase ones.
 *
 * Without `$diffMode` the mode follows the base: Ref with one, Auto without.
 * A mode that contradicts the base is refused.
 *
 * @api
 */
final readonly class InfectionOptionsDto
{
    public InfectionDiffModeEnum $diffMode;

    public function __construct(
        public bool $enabled,
        public int $threads,
        public bool $onlyCovered,
        public int $minMsi,
        public int $minCoveredMsi,
        public ?string $diffBase,
        public int $diffCoveredMsi,
        ?InfectionDiffModeEnum $diffMode = null,
    ) {
        $mode = $diffMode ?? (null === $diffBase ? InfectionDiffModeEnum::Auto : InfectionDiffModeEnum::Ref);
        if ((InfectionDiffModeEnum::Ref === $mode) !== (null !== $diffBase)) {
            throw new LogicException(\sprintf('InfectionOptionsDto: diff mode %s %s a diff base; only a Ref run has one.', $mode->value, null === $diffBase ? 'without' : 'with'));
        }

        $this->diffMode = $mode;
    }
}
