<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\UnautoloadableClassReference\Elsewhere;

/**
 * Named after its file, but its namespace says another directory: the autoloader
 * looks in Elsewhere/, not Drifted/.
 */
final class Misplaced
{
}
