<?php

declare(strict_types=1);

function phpqaciFixtureOverwriteGlobalPattern(string $value): void
{
    global $globalPattern;
    $globalPattern = $value;
}
