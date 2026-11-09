<?php

declare(strict_types=1);

namespace Fixture\DoesNotPerformAssertions;

use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Each test overrides an asserting parent test only to attach a data provider, and its whole
 * body is parent::<the same method>(). The parent asserts, so the correct construction carries
 * no #[DoesNotPerformAssertions]: with it, PHPUnit reports the test risky ("This test is not
 * expected to perform assertions but performed 1 assertion").
 *
 * @internal
 */
#[CoversNothing]
final class ParentDelegatingTest extends AbstractGreetingTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function names(): iterable
    {
        yield 'ada' => ['Ada'];
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function namesWithLength(): iterable
    {
        yield 'grace' => ['Grace', 5];
    }

    #[Override]
    #[DataProvider('names')]
    public function testGreetsByName(string $name): void
    {
        parent::testGreetsByName($name);
    }

    #[Override]
    #[Test]
    #[DataProvider('namesWithLength')]
    public function itCountsTheLetters(string $name, int $letters): void
    {
        parent::itCountsTheLetters($name, $letters);
    }
}
