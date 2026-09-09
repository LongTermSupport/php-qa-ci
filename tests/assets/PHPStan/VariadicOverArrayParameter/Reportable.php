<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\VariadicOverArrayParameter;

final class Item
{
}

final class Reportable
{
    /** @param list<string> $items */
    public function listParam(array $items): string
    {
        return implode(', ', $items);
    }

    /** @param non-empty-list<int> $items */
    public function nonEmptyListParam(array $items): int
    {
        return array_sum($items);
    }

    /** @param array<Item> $items */
    public function singleArgArrayParam(array $items): int
    {
        return \count($items);
    }

    /**
     * The last parameter is the reportable one even though it is not the only one.
     *
     * @param list<string> $items
     */
    public function multipleParamsLastIsList(string $prefix, array $items): string
    {
        return $prefix . implode(', ', $items);
    }
}

/** @param list<string> $parts */
function joinAll(array $parts): string
{
    return implode('/', $parts);
}
