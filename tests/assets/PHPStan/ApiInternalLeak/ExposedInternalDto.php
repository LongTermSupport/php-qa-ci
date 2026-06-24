<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ApiInternalLeak;

/**
 * An internal type. A consumer under a different root namespace gets a
 * `class.internal` error for touching it — so an @api class must never expose it.
 *
 * @internal
 */
final readonly class ExposedInternalDto
{
    public function __construct(public string $value)
    {
    }
}
