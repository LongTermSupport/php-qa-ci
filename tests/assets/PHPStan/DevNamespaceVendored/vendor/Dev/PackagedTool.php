<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\DevNamespaceVendored\vendor\Dev;

/**
 * A `Dev` segment inside a DEPENDENCY's own tree. The hazard belongs to whoever
 * publishes that package, and the analysed project cannot move a file it does not
 * own, so reporting it here would be noise nobody can act on. The directory is
 * literally named `vendor` because that is what makes it vendored code.
 */
final class PackagedTool
{
    public function run(): void
    {
    }
}
