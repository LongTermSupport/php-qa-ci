<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Runner;

/**
 * CI and every other non-TTY run: a failure is final.
 *
 * @internal
 */
final readonly class NonInteractiveRetryPrompt implements RetryPromptInterface
{
    public function shouldRetry(string $label): bool
    {
        return false;
    }
}
