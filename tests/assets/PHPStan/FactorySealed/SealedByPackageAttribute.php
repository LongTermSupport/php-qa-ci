<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\FactorySealed;

use LTS\PHPQA\PHPStan\Attribute\FactorySealedBy;

/**
 * Fixture: a class sealed with the package's reference attribute.
 *
 * @internal
 */
#[FactorySealedBy('Acme\\Widget\\WidgetFactory')]
final class SealedByPackageAttribute
{
}
