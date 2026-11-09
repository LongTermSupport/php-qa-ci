<?php

declare(strict_types=1);

namespace Fixture\AllowMock;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A data-provider test whose mock has a ->method() with no expects(). The correct
 * construction is createStub(), which needs no attribute.
 *
 * @internal
 */
#[CoversNothing]
final class DataProviderMockTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function texts(): iterable
    {
        yield 'one' => ['one'];
    }

    #[DataProvider('texts')]
    public function testReadsFromTheMock(string $text): void
    {
        $mock = $this->createMock(\Stringable::class);
        $mock->method('__toString')->willReturn($text);

        self::assertSame($text, (string) $mock);
    }
}
