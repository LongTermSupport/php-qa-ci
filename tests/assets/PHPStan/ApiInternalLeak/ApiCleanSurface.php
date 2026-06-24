<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ApiInternalLeak;

/**
 * An @api class whose public surface exposes only scalars and other @api types —
 * no leak.
 *
 * @api
 */
final class ApiCleanSurface
{
    public function __construct(public string $name)
    {
    }

    public function rename(string $name): self
    {
        return new self($name);
    }
}
