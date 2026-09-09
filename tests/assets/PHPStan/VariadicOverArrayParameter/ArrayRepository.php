<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\VariadicOverArrayParameter;

/**
 * Implements Repository::findAll(). The signature belongs to the interface, so
 * the same docblock here is not reportable.
 */
final class ArrayRepository implements Repository
{
    /** @param list<string> $ids */
    public function findAll(array $ids): array
    {
        return $ids;
    }
}
