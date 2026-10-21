<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\Pipeline\Runner\RetryPromptInterface;

/**
 * A retry prompt that always answers yes.
 *
 * @internal
 */
final readonly class AlwaysRetry implements RetryPromptInterface
{
    public function shouldRetry(string $label): bool
    {
        return true;
    }
}
