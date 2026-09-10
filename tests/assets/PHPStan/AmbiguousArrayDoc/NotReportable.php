<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\AmbiguousArrayDoc;

final class NotReportable
{
    /** @var list<string> */
    private array $names = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array{name: string, tags: list<string>} */
    private array $shape = ['name' => '', 'tags' => []];

    /**
     * @param non-empty-list<string>          $link
     * @param array<int, list<string>>        $nested
     * @param iterable<string>                $iterable
     * @param array<string, array<int, int>>  $map
     * @param string                          $plain
     *
     * @return list<array<string, int>>
     */
    public function every(array $link, array $nested, iterable $iterable, array $map, string $plain): array
    {
        return [$link, $nested, $iterable, $map, $plain, $this->names, $this->counts, $this->shape];
    }

    public function noDocblock(array $values): int
    {
        /** @var list<int> $ints */
        $ints = [1, 2];

        return \count($values) + \count($ints);
    }
}
