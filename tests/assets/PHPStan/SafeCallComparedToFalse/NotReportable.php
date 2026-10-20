<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\SafeCallComparedToFalse;

use SplFileInfo;

/**
 * Nothing here is reported: the function is not a Safe\ one, the result is
 * not tested against false, or false and falsy are values the Safe\ function
 * can itself return.
 */
final class NotReportable
{
    public function nativeRealpath(string $path): bool
    {
        return false === \realpath($path);
    }

    public function splFileInfo(string $path): bool
    {
        return false === new SplFileInfo($path)->getRealPath();
    }

    public function assignedAndUsed(string $path): string
    {
        $resolved = \Safe\realpath($path);

        return $resolved . '/composer.json';
    }

    public function nonSafeNamespacedFunction(string $path): bool
    {
        return false === Lookup\find($path) || !Lookup\find($path);
    }

    public function falseIsAValueTheSafeFunctionReturns(string $json): bool
    {
        return false === \Safe\json_decode($json);
    }

    public function aFalsyResultTheSafeFunctionReturns(string $subject, string $file): bool
    {
        if (\Safe\preg_match('/x/', $subject)) {
            return true;
        }

        return !\Safe\file_get_contents($file);
    }

    public function comparedWithSomethingElse(string $path): bool
    {
        return null === \Safe\realpath($path) || true === \Safe\realpath($path);
    }
}
