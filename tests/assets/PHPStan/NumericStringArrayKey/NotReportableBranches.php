<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\NumericStringArrayKey;

/**
 * Keys through an operator or a branch that cannot be all digits, and the
 * key readers in the forms where an int key behaves as its string would.
 */
final class NotReportableBranches
{
    /** @var array<string, int> */
    private array $byName = [];

    public function constantKeysThroughBranches(bool $c): void
    {
        $this->byName = $c ? ['a' => 1] : ['b' => 2] + $this->byName;
        $this->byName += ['c' => 3];
    }

    /** @return array<array-key, int> */
    public function honestDeclarationThroughATernary(string $name, bool $c): array
    {
        return $c ? [$name => 1] : [];
    }

    public function encodedAsAnObject(string $name): string
    {
        $m        = [];
        $m[$name] = 1;

        return json_encode($m, \JSON_FORCE_OBJECT | \JSON_THROW_ON_ERROR);
    }

    public function queryWithoutANumericPrefix(string $name): string
    {
        $m        = [];
        $m[$name] = 1;

        return http_build_query($m);
    }

    /** @return list<list<int>> */
    public function chunkedRenumbered(string $name): array
    {
        $m        = [];
        $m[$name] = 1;

        return array_chunk($m, 2);
    }

    /**
     * A function whose own template takes the keys and hands them nowhere
     * declares nothing about them: usort() and array_values() drop them,
     * uasort() keeps them in place.
     *
     * @param list<string> $names
     */
    public function passedToFunctionsGenericInTheirKeys(array $names): int
    {
        $m = [];
        foreach ($names as $name) {
            $m[$name] = 1;
        }

        $sorted = $m;
        usort($sorted, static fn (int $a, int $b): int => $a <=> $b);
        uasort($m, static fn (int $a, int $b): int => $a <=> $b);
        $this->countAny($m);

        return \count(array_values($m)) + \count($sorted);
    }

    public function intsAdded(int $a, int $b): int
    {
        $sum = $a + $b;

        return $sum;
    }

    /**
     * @template K of array-key
     *
     * @param array<K, int> $map
     */
    private function countAny(array $map): int
    {
        return \count($map);
    }
}
