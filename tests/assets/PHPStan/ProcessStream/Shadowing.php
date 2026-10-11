<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStream\Shadowing;

/** This namespace's own readline(), which an unqualified call here resolves to before PHP's. */
function readline(string $line): string
{
    return trim($line);
}

/** This namespace's own readline_add_history(), likewise. */
function readline_add_history(string $line): bool
{
    return '' !== $line;
}

final class Shadowing
{
    public function callsItsNamespacesOwnFunctions(string $line): bool
    {
        return readline_add_history(readline($line));
    }

    public function callsPhpsOwnReadlineByItsQualifiedName(): string|false
    {
        return \readline('> ');
    }
}
