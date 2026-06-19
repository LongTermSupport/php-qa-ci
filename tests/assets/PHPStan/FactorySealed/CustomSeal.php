<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\FactorySealed;

use Attribute;

/**
 * Fixture: a project-defined sealing attribute living in the project's OWN
 * namespace (not the package's). Proves the rule resolves the authorised factory
 * from the attribute's first argument generically — a project can keep its
 * sealing attribute in production code without coupling to a dev-only QA tool.
 *
 * @internal
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class CustomSeal
{
    public function __construct(public string $factory)
    {
    }
}
