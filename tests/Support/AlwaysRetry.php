<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\Pipeline\Runner\RetryPromptInterface;

/**
 * A retry prompt that always answers yes, as a user choosing to retry every failed tool would.
 */
final readonly class AlwaysRetry implements RetryPromptInterface
{
    public function shouldRetry(string $label): bool
    {
        return true;
    }
}
