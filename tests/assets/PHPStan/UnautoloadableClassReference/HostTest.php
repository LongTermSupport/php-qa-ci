<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\UnautoloadableClassReference;

use Attribute;
use RuntimeException;

/**
 * A class at its PSR-4 path, with more declared at its foot. Its own uses of them are
 * never reported: whatever loads this file loads them with it.
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

abstract class StrandedBase
{
}

interface StrandedMarker
{
}

final class StrandedMarked implements StrandedMarker
{
}

/**
 * Each method's signature decides whether an implementing class makes PHP load a
 * class to check it.
 */
interface StrandedContract
{
    public function contracted(): object;

    public function accept(StrandedMarked $marked): void;

    public function widened(AtItsPath $value): void;

    public function same(): StrandedHelper;
}

trait StrandedTrait
{
}

#[Attribute]
final class StrandedAttribute
{
}

/**
 * Used only in this file: no reference can reach it before its file is loaded.
 */
final class FileLocalHelper
{
}
