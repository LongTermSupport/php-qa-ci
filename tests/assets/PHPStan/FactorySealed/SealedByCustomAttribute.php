<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\FactorySealed;

/**
 * Fixture: a class sealed with a project-defined attribute ({@see CustomSeal}).
 *
 * @internal
 */
#[CustomSeal('Acme\\Order\\OrderFactory')]
final class SealedByCustomAttribute
{
}
