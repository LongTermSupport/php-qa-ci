<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\VariadicOverArrayParameter;

/**
 * A constructor whose list parameter is NOT promoted, so nothing but the
 * `__construct` exemption itself keeps it out of the report.
 *
 * The exemption exists because PHPStan's own neon DI container passes each
 * `arguments:` entry as one positional value: a variadic constructor would
 * re-read an array argument as the first element of a spread.
 */
final class ConstructorTakesAList
{
    /** @var list<string> */
    private array $items;

    /** @param list<string> $items */
    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public function count(): int
    {
        return \count($this->items);
    }
}
