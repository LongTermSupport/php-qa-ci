<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool;

use LTS\PHPQA\Pipeline\Tool\Dto\PhaseDto;

/**
 * The four shipped phases, in execution order: code modification first so
 * every later phase validates the final state, then linting, static analysis,
 * testing. The value is the phase name a tool definition joins it by, so a
 * project-registered tool names a shipped phase as PhaseEnum::Linting->value.
 *
 * @api
 */
enum PhaseEnum: string
{
    public function phase(): PhaseDto
    {
        return match ($this) {
            self::CodingStandards => new PhaseDto($this->value, 'Running All Coding Standards Tools (That Might Make Code Changes)', 'allCodingStandardsTools', ['allCS'], 'all coding standards tools'),
            self::Linting         => new PhaseDto($this->value, 'Running All Linting Tools', 'allLintingTools', ['allLints'], 'all linting tools'),
            self::StaticAnalysis  => new PhaseDto($this->value, 'Running All Static Analysis Tools', 'allStaticAnalysisTools', ['allStatic'], 'all static analysis tools'),
            self::Testing         => new PhaseDto($this->value, 'Running All Testing Tools', 'allTestingTools', ['allTests'], 'all testing tools'),
        };
    }

    case CodingStandards = 'codingStandards';
    case Linting         = 'linting';
    case StaticAnalysis  = 'staticAnalysis';
    case Testing         = 'testing';
}
