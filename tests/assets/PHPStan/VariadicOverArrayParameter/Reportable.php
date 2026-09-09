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

    /**
     * A docblock refinement has no native spelling, so the suggestion names the
     * base type the engine can actually check.
     *
     * @param list<class-string> $classes
     */
    public function classStringParam(array $classes): int
    {
        return \count($classes);
    }

    /** @param list<int<0, max>> $offsets */
    public function boundedIntParam(array $offsets): int
    {
        return array_sum($offsets);
    }

    /** @param list<positive-int> $sizes */
    public function positiveIntParam(array $sizes): int
    {
        return array_sum($sizes);
    }

    /** @param list<negative-int> $debts */
    public function negativeIntParam(array $debts): int
    {
        return array_sum($debts);
    }

    /** @param list< string > $values */
    public function spacedGenericParam(array $values): string
    {
        return implode(', ', $values);
    }

    /**
     * $item is a map and is last, so the search continues past it. Its shorter
     * name must not be satisfied by the $itemNames entry.
     *
     * @param list<string>         $itemNames
     * @param array<string, mixed> $item
     */
    public function similarlyNamedParams(array $itemNames, array $item): int
    {
        return \count($itemNames) + \count($item);
    }
}

/** @param list<string> $parts */
function joinAll(array $parts): string
{
    return implode('/', $parts);
}

/**
 * Not last, and nothing after it has a default, so it can be moved to final
 * position and converted. The message says so.
 *
 * @param list<string> $parts
 */
function joinWithSuffix(array $parts, string $suffix): string
{
    return implode('/', $parts) . $suffix;
}
