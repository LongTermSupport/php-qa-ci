<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\NumericStringArrayKey;

/**
 * Arrays handed on by reference, through `static` or `global`, as a yielded
 * value, nested in an element of another, or inside a Traversable, before
 * they are taken as string-keyed.
 */
final class ReportableHandedOn
{
    /** @var array<string, int> */
    private array $byName = [];

    /** @var array<string, array<string, int>> */
    private array $nested = [];

    /** @return array<string, int> */
    public function writtenThroughAnAlias(string $name): array
    {
        $map          = [];
        $alias        = &$map;
        $alias[$name] = 1;

        return $map;
    }

    /** @return array<string, int> */
    public function returnedThroughAnAlias(string $name): array
    {
        $map        = [];
        $map[$name] = 1;
        $alias      = &$map;

        return $alias;
    }

    public function writtenThroughAReferenceToAProperty(string $name): void
    {
        $alias        = &$this->byName;
        $alias[$name] = 1;
    }

    /**
     * @param array<string, array<string, int>> $groups
     *
     * @return array<string, array<string, int>>
     */
    public function writtenThroughAForeachReference(array $groups, string $name): array
    {
        foreach ($groups as &$group) {
            $group[$name] = 1;
        }

        unset($group);

        return $groups;
    }

    /** @param list<string> $names */
    public function readThroughAForeachValue(array $names, string $name): void
    {
        $groups = [];
        foreach ($names as $group) {
            $groups[$group][$name] = 1;
        }

        foreach ($groups as $inner) {
            foreach ($inner as $key => $_) {
                $this->takes($key);
            }
        }
    }

    /** @param array<string, int> $out */
    public function handedBackByReference(array &$out, string $name): void
    {
        $out[$name] = 1;
    }

    /** @return array<string, int> */
    public function keptInAStatic(string $name): array
    {
        static $cache = [];
        $cache[$name] = 1;

        return $cache;
    }

    public function writtenIntoAGlobal(string $name): void
    {
        global $registry;
        $registry[$name] = 1;
    }

    public function writtenIntoGlobals(string $name): void
    {
        $GLOBALS['registry'][$name] = 1;
    }

    public function readFromAGlobal(): void
    {
        global $registry;
        foreach ($registry as $key => $_) {
            $this->takes($key);
        }
    }

    /** @return \Generator<int, array<string, int>> */
    public function yieldedAsAValue(string $name): \Generator
    {
        $map        = [];
        $map[$name] = 1;

        yield $map;
        yield [$name => 2];
    }

    /** @return \Generator<int, int, mixed, array<string, int>> */
    public function returnedFromAGenerator(string $name): \Generator
    {
        $map        = [];
        $map[$name] = 1;

        yield 1;

        return $map;
    }

    /** @return array<string, array<string, int>> */
    public function literalWrittenIntoAnElement(string $name): array
    {
        $map          = [];
        $map['fixed'] = [$name => 1];

        return $map;
    }

    public function literalWrittenIntoAPropertyElement(string $name): void
    {
        $this->nested['fixed'] = [$name => 1];
    }

    /** @return array<string, array<string, int>> */
    public function localWrittenIntoAnElement(string $name): array
    {
        $inner        = [];
        $inner[$name] = 1;
        $map          = [];
        $map['fixed'] = $inner;

        return $map;
    }

    /** @return array<string, int> */
    public function readOutOfAnElement(string $name): array
    {
        $map                 = [];
        $map['fixed'][$name] = 1;
        $inner               = $map['fixed'];

        return $inner;
    }

    /** @return \Iterator<string, int> */
    public function wrappedInAnArrayIterator(string $name): \Iterator
    {
        $map        = [];
        $map[$name] = 1;

        return new \ArrayIterator($map);
    }

    public function literalWrappedInAnArrayObject(string $name): void
    {
        $this->takesObject(new \ArrayObject([$name => 1]));
    }

    /** @param \ArrayObject<string, int> $object */
    private function takesObject(\ArrayObject $object): void
    {
    }

    private function takes(string $name): void
    {
    }
}
