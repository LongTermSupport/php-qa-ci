<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\VariadicOverArrayParameter;

/**
 * The root of the contract: nothing constrains this signature, so its
 * list-shaped parameter is still reportable.
 */
interface Repository
{
    /** @param list<string> $ids */
    public function findAll(array $ids): array;
}
