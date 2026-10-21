<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\UnautoloadableClassReference;

use RuntimeException;

/**
 * A class at its PSR-4 path, with three more declared at its foot. Its own uses of
 * them are never reported: whatever loads this file loads them with it.
 */
final class HostTest
{
    public function run(): FileLocalHelper
    {
        new StrandedHelper();

        return new FileLocalHelper();
    }
}

/**
 * Referenced from other files: the hazard.
 */
final class StrandedHelper
{
    public const string NAME = 'stranded';

    public static function make(): self
    {
        return new self();
    }
}

final class StrandedException extends RuntimeException
{
}

/**
 * Used only in this file: no reference can reach it before its file is loaded.
 */
final class FileLocalHelper
{
}
