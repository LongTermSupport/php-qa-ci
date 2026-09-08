<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool;

/**
 * Whether a phased tool runs given the run's flags.
 *
 * @internal
 */
enum ToolGateEnum
{
    public function allows(bool $quickTests, bool $infectionEnabled): bool
    {
        return match ($this) {
            self::None      => true,
            self::NotQuick  => !$quickTests,
            self::Infection => !$quickTests && $infectionEnabled,
        };
    }

    /** Always runs. */
    case None;
    /** Skipped when phpqaQuickTests=1. */
    case NotQuick;
    /** Skipped when phpqaQuickTests=1, and only runs when Infection is enabled. */
    case Infection;
}
