<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Runner;

/**
 * Asked after a tool fails. Non-interactive runs always answer no.
 *
 * @internal
 */
interface RetryPromptInterface
{
    public function shouldRetry(string $label): bool;
}
