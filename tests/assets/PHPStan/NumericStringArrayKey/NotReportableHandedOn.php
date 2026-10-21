<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\NumericStringArrayKey;

/**
 * Arrays handed on by reference, through `global` or as a value, where nothing
 * takes them as string-keyed at the depth of a key that may be all digits.
 */
final class NotReportableHandedOn
{
    /** @var array<array-key, int> */
    private array $honest = [];

    public function writtenThroughAReferenceToAnHonestProperty(string $name): void
    {
        $alias        = &$this->honest;
        $alias[$name] = 1;
    }

    public function aLocalNamedAsAGlobalIsItsOwnVariable(string $name): int
    {
        $registry        = [];
        $registry[$name] = 1;

        return \count($registry);
    }

    public function readFromAGlobal(): void
    {
        global $registry;
        foreach ($registry as $key => $_) {
            $this->takes((string) $key);
        }
    }

    /** @return \Generator<int, array<array-key, int>> */
    public function yieldedHonestly(string $name): \Generator
    {
        $map        = [];
        $map[$name] = 1;

        yield $map;
    }

    /** @return list<array<string, int>> */
    public function aKeyAtADepthNothingTakesAsStringKeyed(string $name): array
    {
        $map          = [];
        $map[$name][] = ['fixed' => 1];

        return array_values($map)[0] ?? [];
    }

    /** @param array<array-key, int> $out */
    public function handedBackByReferenceHonestly(array &$out, string $name): void
    {
        $out[$name] = 1;
    }

    private function takes(string $name): void
    {
    }
}
