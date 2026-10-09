<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config;

/**
 * How much of src/ the Infection lane mutates.
 *
 * - Auto (the default): on a branch other than the default one, the files
 *   committed since the merge base with the default branch; on the default
 *   branch, or when no merge base can be found, everything, with the reason
 *   printed.
 * - Full: everything, always (withInfectionFullRun(), infectionDiffBase=full).
 * - Ref: the files committed since a configured git ref
 *   (withInfectionDiffBase('origin/main'), infectionDiffBase=origin/main).
 *
 * @api
 */
enum InfectionDiffModeEnum: string
{
    case Auto = 'auto';
    case Full = 'full';
    case Ref  = 'ref';
}
