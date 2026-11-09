<?php

declare(strict_types=1);

namespace Symfony\Component\Form\Test;

use PHPUnit\Framework\TestCase;

/**
 * Stand-in for Symfony's TypeTestCase: symfony/form is not a dependency of php-qa-ci.
 * AllowMockObjectsWhereParentClassRector matches the parent by its fully qualified name only.
 *
 * @internal
 */
abstract class TypeTestCase extends TestCase
{
}
