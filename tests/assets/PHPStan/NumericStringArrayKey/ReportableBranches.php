<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\NumericStringArrayKey;

/**
 * Keys that reach a string-keyed declaration through an operator or a branch
 * (`+`, `+=`, `??=`, `??`, `?:`, a ternary, a match arm, a cast), and the
 * functions besides array_merge() that renumber int keys or hand keys to code.
 */
final class ReportableBranches
{
    /** @var array<string, int> */
    private array $byName = [];

    /** @var array<string, int>|null */
    private ?array $maybe = null;

    public function unionLiteralFirst(string $name): void
    {
        $this->byName = [$name => 1] + $this->byName;
    }

    public function unionLiteralLast(string $name): void
    {
        $this->byName = $this->byName + [$name => 1];
    }

    public function unionAssigned(string $name): void
    {
        $this->byName += [$name => 1];
    }

    public function coalesceAssigned(string $name): void
    {
        $this->maybe ??= [$name => 1];
    }

    public function ternaryIntoProperty(string $name, bool $c): void
    {
        $this->byName = $c ? [$name => 1] : [];
    }

    /** @return array<string, int> */
    public function ternaryReturned(string $name, bool $c): array
    {
        return $c ? [$name => 1] : [];
    }

    /** @return array<string, int> */
    public function matchReturned(string $name, int $c): array
    {
        return match ($c) {
            1       => [$name => 1],
            default => [],
        };
    }

    /** @return array<string, int> */
    public function coalesceReturned(string $name): array
    {
        return $this->maybe ?? [$name => 1];
    }

    /** @return array<string, int> */
    public function localUnionAssignedThenReturned(string $name): array
    {
        $m = ['a' => 1];
        $m += [$name => 2];

        return $m;
    }

    /** @return array<string, int> */
    public function localAssignedAUnionThenReturned(string $name): array
    {
        $m = $this->byName + [$name => 2];

        return $m;
    }

    /** @return array<string, int> */
    public function localReturnedThroughATernary(string $name, bool $c): array
    {
        $m        = [];
        $m[$name] = 1;

        return $c ? $m : [];
    }

    public function ternaryPassed(string $name, bool $c): void
    {
        $this->takesMap($c ? [$name => 1] : []);
    }

    /** @param list<string> $names */
    public function flippedThroughATernary(array $names, bool $c): void
    {
        $this->byName = $c ? array_flip($names) : [];
    }

    /** @return array<string, int> */
    public function shortTernaryReturned(string $name): array
    {
        return $this->byName ?: [$name => 1];
    }

    /** @return array<string, int> */
    public function castReturned(string $name): array
    {
        $m        = [];
        $m[$name] = 1;

        return (array) $m;
    }

    /** @param list<string> $names */
    public function keysReadThroughATernary(array $names, bool $c): void
    {
        $m = [];
        foreach ($names as $name) {
            $m[$name] = 1;
        }

        foreach ($c ? $m : [] as $key => $_) {
            $this->takes($key);
        }
    }

    /** @return array<string, int> */
    public function localCopiedThroughACoalesce(string $name): array
    {
        $m        = [];
        $m[$name] = 1;
        $copy     = $this->maybe ?? $m;

        return $copy;
    }

    /** @return array<string, int> */
    public function padded(string $name): array
    {
        $m        = [];
        $m[$name] = 1;

        return array_map(static fn (int $v): int => $v, array_pad($m, 10, 0));
    }

    public function multisorted(string $name): void
    {
        $m        = [];
        $m[$name] = 1;
        array_multisort($m);
    }

    public function encoded(string $name): string
    {
        $m        = [];
        $m[$name] = 1;

        return json_encode($m, \JSON_THROW_ON_ERROR);
    }

    public function encodedBySafe(string $name): string
    {
        return \Safe\json_encode([$name => 1]);
    }

    public function askedWhetherAList(string $name): bool
    {
        $m        = [];
        $m[$name] = 1;

        return array_is_list($m);
    }

    public function pickedAtRandom(string $name): void
    {
        $m        = [];
        $m[$name] = 1;
        $this->takes((string) array_rand($m));
    }

    public function comparedByAKeyCallback(string $name): int
    {
        $m        = [];
        $m[$name] = 1;

        return \count(array_diff_ukey($m, ['a' => 1], static fn (string $a, string $b): int => $a <=> $b));
    }

    /** @return array<string, int> */
    public function keptByAValueCallback(string $name): array
    {
        $m        = [];
        $m[$name] = 1;

        return array_udiff($m, [], static fn (int $a, int $b): int => $a <=> $b);
    }

    public function queryWithANumericPrefix(string $name): string
    {
        $m        = [];
        $m[$name] = 1;

        return http_build_query($m, 'p');
    }

    /** @return list<array<string, int>> */
    public function chunkedKeepingKeys(string $name): array
    {
        $m        = [];
        $m[$name] = 1;

        return array_chunk($m, 2, true);
    }

    public function walkedBySafe(string $name): void
    {
        $m        = [];
        $m[$name] = 1;
        \Safe\array_walk_recursive($m, function (int $value, string $key): void {
            $this->takes($key);
        });
    }

    /** @return array<string, int> */
    public function passedToAGenericThatHandsTheKeysBack(string $name): array
    {
        $m        = [];
        $m[$name] = 1;

        return $this->kept($m);
    }

    /**
     * @template K of array-key
     *
     * @param array<K, int> $map
     *
     * @return array<K, int>
     */
    private function kept(array $map): array
    {
        return $map;
    }

    private function takes(string $name): void
    {
    }

    /** @param array<string, int> $map */
    private function takesMap(array $map): void
    {
    }
}
