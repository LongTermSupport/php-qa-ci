<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\VariadicOverArrayParameter;

/**
 * A parent with no supertype of its own: its list-shaped parameter is still
 * reportable, exactly like the interface.
 */
abstract class BaseCollector
{
    /** @param list<string> $items */
    public function collect(array $items): void
    {
    }
}
