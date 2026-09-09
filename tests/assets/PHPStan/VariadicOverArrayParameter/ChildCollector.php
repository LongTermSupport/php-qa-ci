<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\VariadicOverArrayParameter;

/**
 * Overrides BaseCollector::collect(). The signature belongs to the parent, so
 * the same docblock here is not reportable.
 */
final class ChildCollector extends BaseCollector
{
    /** @param list<string> $items */
    public function collect(array $items): void
    {
    }
}
