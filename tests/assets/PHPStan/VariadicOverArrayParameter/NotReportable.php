<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\VariadicOverArrayParameter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;

final class NotReportable
{
    /** @param list<string> $items */
    public function __construct(private readonly array $items)
    {
    }

    /** @param list<string> $items */
    public function fillItems(array &$items): void
    {
        $items[] = 'x';
    }

    /** @param list<array<string>> $groups */
    public function acceptsGroups(array ...$groups): void
    {
    }

    /** @param array<string, mixed> $options */
    public function configure(array $options): void
    {
    }

    /** @param iterable<string, mixed> $options */
    public function configureIterable(array $options): void
    {
    }

    /** @param array{name: string, count: int} $options */
    public function configureShape(array $options): void
    {
    }

    /** Builds a summary line. No docblock type is given for the parameter below. */
    public function summarize(array $items): string
    {
        return implode(', ', $items);
    }

    public function untouched(array $items): void
    {
    }

    /** @param list<string> $rows */
    #[DataProvider('rowsProvider')]
    public function testRows(array $rows): void
    {
    }

    /** @param list<string> $rows */
    #[DataProviderExternal(NotReportable::class, 'rowsProvider')]
    public function testExternalRows(array $rows): void
    {
    }

    /**
     * A later parameter has a default, so moving $items last would force every
     * caller naming $separator to stop compiling: PHP rejects argument
     * unpacking after a named argument.
     *
     * @param list<string> $items
     */
    public function laterParamIsOptional(array $items, string $separator = ','): string
    {
        return implode($separator, $items);
    }

    /**
     * The parameter carries its own default. PHP has no syntax for
     * `string ...$items = ['a']`, so converting would silently drop the default
     * and change what a caller passing nothing receives.
     *
     * @param list<string> $items
     */
    public function defaultedListParam(array $items = ['a']): string
    {
        return implode(', ', $items);
    }

    /**
     * The one variadic slot is already spent, so $items is not convertible even
     * though its docblock says it is list-shaped.
     *
     * @param list<string> $items
     */
    public function alreadyHasAVariadic(array $items, string ...$rest): string
    {
        return implode(',', [...$items, ...$rest]);
    }

    /**
     * The rule is about the native `array` declaration. `iterable` accepts a
     * Traversable too, so the list docblock does not make it convertible.
     *
     * @param list<string> $items
     */
    public function iterableIsNotArray(iterable $items): void
    {
    }

    /** @return list<list<string>> */
    public static function rowsProvider(): array
    {
        return [['a']];
    }
}
