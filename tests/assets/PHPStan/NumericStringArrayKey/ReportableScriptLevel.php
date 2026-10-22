<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\NumericStringArrayKey;

/*
 * A variable written at the top level of a script is a global: a function that
 * imports it with `global` shares it, so a key written here is a key of the
 * array that function returns.
 */
$registry               = [];
$name                   = (string)getenv('REGISTRY_KEY');
$registry[$name]        = 1;

/** @return array<string, int> */
function scriptLevelRegistry(): array
{
    global $registry;

    return $registry;
}
