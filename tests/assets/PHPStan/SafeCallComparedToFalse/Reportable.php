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

    public function ifCondition(string $path): string
    {
        if (\Safe\realpath($path)) {
            return 'if';
        }

        return $path;
    }

    public function elseIfNegatedCondition(string $path): string
    {
        if ('' === $path) {
            return 'empty';
        } elseif (!\Safe\realpath($path)) {
            return 'not';
        }

        return $path;
    }

    public function whileAndTernaryConditions(string $path): string
    {
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

    public function alwaysTruthyResult(string $path): bool
    {
        return !\Safe\fopen($path, 'r');
    }

    public function loopConditions(string $path): void
    {
        do {
            $path .= '/';
        } while (\Safe\realpath($path));

        for ($i = 0; \Safe\realpath($path); ++$i) {
            break;
        }
    }

    public function errorSuppressed(string $path): string
    {
        if (false === @\Safe\realpath($path)) {
            return 'compared';
        }

        if (@\Safe\realpath($path)) {
            return 'condition';
        }

        return $path;
    }

    public function errorSuppressedInsideAssignmentAndParentheses(string $path): string
    {
        if (false === ($resolved = @\Safe\realpath($path))) {
            return $path;
        }

        if (false === @($real = \Safe\realpath($path))) {
            return $resolved;
        }

        return ((@\Safe\realpath($path))) ? $real : $path;
    }

    public function pipedAndCompared(string $path): bool
    {
        return false === ($path |> \Safe\realpath(...)) || $path |> realpath(...) !== false;
    }

    public function pipedAsConditions(string $path): string
    {
        if ($path |> \Safe\realpath(...)) {
            return 'condition';
        }

        return !($path |> \Safe\realpath(...)) ? 'negated' : $path;
    }

    public function pipedThroughAChain(string $path): string
    {
        return ($path |> \trim(...) |> \Safe\realpath(...)) ? 'chained' : $path;
    }

    public function pipedThroughSuppressionAndAssignment(string $path): string
    {
        if (false === @($path |> \Safe\realpath(...))) {
            return 'suppressed';
        }

        if ($resolved = $path |> \Safe\realpath(...)) {
            return $resolved;
        }

        return false == (@$path |> \Safe\realpath(...)) ? 'loose' : $path;
    }
}
