<?php

declare(strict_types=1);

namespace Fixture\RectorVersusPhpstan\NullsafeCoalesceOnPropertyFetch;

/*
 * Clash: Rector TernaryToNullsafeCoalesceRector (php80 set) versus PHPStan nullsafe.neverNull.
 *
 * Rector rewrites `null !== $x->name ? $x->name->name : 'anonymous'` into
 * `$x->name?->name ?? 'anonymous'`; PHPStan reports the `?->` on the left of `??` as
 * unnecessary, because `??` already guards the whole property chain, and wants
 * `$x->name->name ?? 'anonymous'`. A method call on the right is not affected.
 */
final class Name
{
    public string $name = '';
}

final class Node
{
    public ?Name $name = null;
}

final class Labeller
{
    public function label(Node $x): string
    {
        return null !== $x->name ? $x->name->name : 'anonymous';
    }
}
