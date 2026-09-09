<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\AmbiguousArrayDoc;

final class Reportable
{
    /** @var string[] */
    private array $names = [];

    /** @var array<int> */
    private array $counts = [];

    /**
     * @param string[]      $link
     * @param array<string> $errors
     *
     * @return array<array<string>>
     */
    public function both(array $link, array $errors): array
    {
        return [$link, $errors, $this->names, $this->counts];
    }

    /** @return non-empty-array<int> the counts */
    public function nonEmpty(): array
    {
        return [1];
    }

    public function inline(): int
    {
        /** @var int[] $values */
        $values = [1, 2];

        return \count($values);
    }
}
