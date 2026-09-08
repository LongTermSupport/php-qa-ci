<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool\Dto;

use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;

/**
 * @internal
 */
final readonly class ToolResultDto
{
    private function __construct(
        public ToolOutcomeEnum $outcome,
        /** One line for the aggregate summary; the tool has already printed the detail. */
        public string $summary,
    ) {
    }

    public static function passed(string $summary = ''): self
    {
        return new self(ToolOutcomeEnum::Passed, $summary);
    }

    public static function failed(string $summary): self
    {
        return new self(ToolOutcomeEnum::Failed, $summary);
    }

    public static function crashed(string $summary): self
    {
        return new self(ToolOutcomeEnum::Crashed, $summary);
    }

    public static function skipped(string $summary): self
    {
        return new self(ToolOutcomeEnum::Skipped, $summary);
    }

    /** Map a process exit code: 0 passed, $failureCodes failed, anything else crashed. */
    public static function fromExitCode(int $exitCode, string $label, int ...$failureCodes): self
    {
        if (0 === $exitCode) {
            return self::passed();
        }

        if ([] === $failureCodes || \in_array($exitCode, $failureCodes, true)) {
            return self::failed(\sprintf('%s failed (exit %d)', $label, $exitCode));
        }

        return self::crashed(\sprintf('%s crashed (exit %d)', $label, $exitCode));
    }

    public function isSuccess(): bool
    {
        return $this->outcome->isSuccess();
    }
}
