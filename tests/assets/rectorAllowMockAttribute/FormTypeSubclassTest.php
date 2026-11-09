<?php

declare(strict_types=1);

namespace Fixture\AllowMock;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Form\Test\TypeTestCase;

/**
 * A subclass of Symfony's TypeTestCase with no mock at all: the correct construction
 * carries no AllowMockObjectsWithoutExpectations attribute.
 *
 * @internal
 */
#[CoversNothing]
final class FormTypeSubclassTest extends TypeTestCase
{
    #[Test]
    public function itAsserts(): void
    {
        self::assertTrue(true);
    }
}
