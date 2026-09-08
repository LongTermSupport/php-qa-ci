<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool;

/**
 * The four phases, in execution order: code modification first so every later
 * phase validates the final state, then linting, static analysis, testing.
 *
 * @internal
 */
enum PhaseEnum: string
{
    public function banner(): string
    {
        return match ($this) {
            self::CodingStandards => 'Running All Coding Standards Tools (That Might Make Code Changes)',
            self::Linting         => 'Running All Linting Tools',
            self::StaticAnalysis  => 'Running All Static Analysis Tools',
            self::Testing         => 'Running All Testing Tools',
        };
    }
    case CodingStandards = 'codingStandards';
    case Linting         = 'linting';
    case StaticAnalysis  = 'staticAnalysis';
    case Testing         = 'testing';
}
