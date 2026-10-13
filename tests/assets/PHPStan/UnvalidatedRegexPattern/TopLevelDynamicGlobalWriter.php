<?php

declare(strict_types=1);

function phpqaciFixtureOverwriteAnyGlobal(string $name, string $value): void
{
    $GLOBALS[$name] = $value;
}
