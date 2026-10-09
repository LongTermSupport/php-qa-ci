<?php

declare(strict_types=1);

namespace DiffScope\Tests;

use DiffScope\Tested;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Tested::class)]
final class TestedTest extends TestCase
{
    #[Test]
    public function itAdds(): void
    {
        self::assertSame(5, new Tested()->add(2, 3));
        self::assertSame(-1, new Tested()->add(2, -3));
    }
}
