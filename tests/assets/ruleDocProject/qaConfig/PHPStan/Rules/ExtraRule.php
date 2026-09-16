<?php

declare(strict_types=1);

namespace FixtureApp\PHPStan\Rules;

/**
 * A fixture standing in for a consuming project's own PHPStan rule, so the
 * resolver has a real file to point a project index row's source path at.
 *
 * It is never loaded or executed: the resolver only reports where a rule lives.
 */
final readonly class ExtraRule
{
    public const string IDENTIFIER = 'fixtureApp.extraRule';
}
