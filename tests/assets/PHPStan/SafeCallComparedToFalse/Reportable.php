<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\SafeCallComparedToFalse;

use function Safe\realpath;

/**
 * Every check here was written for the native function's false, which the
 * Safe\ form never returns: it throws on the failure instead.
 */
final class Reportable
{
    public function falseOnTheLeft(string $path): ?string
    {
        if (false === \Safe\realpath($path)) {
            return null;
        }

        return $path;
    }

    public function falseOnTheRight(string $path): bool
    {
        return \Safe\realpath($path) !== false;
    }

    public function importedName(string $path): bool
    {
        return realpath($path) === false;
    }

    public function looseComparisons(string $path): bool
    {
        return false == \Safe\realpath($path) || \Safe\realpath($path) != false;
    }

    public function assignedInsideTheComparison(string $path): string
    {
        if (false === ($resolved = \Safe\realpath($path))) {
            return $path;
        }

        return $resolved;
    }

    public function conditions(string $path): string
    {
        if (\Safe\realpath($path)) {
            return 'if';
        }
        if ('' === $path) {
            return 'empty';
        } elseif (!\Safe\realpath($path)) {
            return 'not';
        }
        while (\Safe\realpath($path)) {
            break;
        }

        return \Safe\realpath($path) ? 'ternary' : 'never';
    }

    public function shortTernaryAndLogicalOperands(string $path): string
    {
        $resolved = \Safe\realpath($path) ?: $path;

        return (\Safe\realpath($path) && '' !== $resolved) ? $resolved : $path;
    }
}
