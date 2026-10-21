<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\UnautoloadableClassReference;

/**
 * A second declaration of a class the autoloader finds at Duplicated.php. This file is
 * analysed but never loaded beside that one: loading both fails with "Cannot redeclare class".
 */
final class Duplicated
{
}
