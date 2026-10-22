<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\SafeCallComparedToFalse\Lookup;

/**
 * A namespaced function that is not a Safe\ one, with the same name shape and
 * result type as Safe\realpath().
 *
 * @return non-empty-string
 */
function find(string $path): string
{
    return '/' . $path;
}
