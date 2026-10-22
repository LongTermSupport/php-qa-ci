<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\NumericStringArrayKey;

/**
 * Bodies whose writes NumericStringArrayKeyWritesCollector records: tracked,
 * through a reference, as array literal items, and bodies that may write any
 * variable unseen.
 */
final class Writes
{
    public function tracked(string $name): int
    {
        $list        = ['fixed' => 1];
        $list[$name] = 2;
        $alias       = &$list;

        return \count($alias);
    }

    public function closureOwnBody(): \Closure
    {
        $outer = 1;

        return static function (int $inner) use ($outer): int {
            $sum = $inner + $outer;

            return $sum;
        };
    }

    public function opaque(): int
    {
        $count = 0;
        again:
        ++$count;
        if ($count < 2) {
            goto again;
        }

        return $count;
    }

    public function variableVariable(string $name): int
    {
        ${$name} = 1;

        return 1;
    }

    /** @return list<mixed> */
    public function allNames(): array
    {
        return \func_get_args();
    }

    public function passedByReference(): int
    {
        $sorted = ['b' => 1, 'a' => 2];
        ksort($sorted);
        preg_match('/x/', 'x', $matches);

        return \count($sorted) + \count($matches);
    }

    public function arrowFunctionCopy(): int
    {
        $map  = ['b' => 1, 'a' => 2];
        $sort = fn () => sort($map);

        return \count($map) + (int)$sort();
    }
}
