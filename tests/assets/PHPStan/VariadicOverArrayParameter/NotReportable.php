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

    /** @return list<list<string>> */
    public static function rowsProvider(): array
    {
        return [['a']];
    }
}
