<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Changelog;

use Iterator;
use LTS\PHPQA\Changelog\ChangelogTrailers;
use LTS\PHPQA\Changelog\Dto\ChangelogTrailerVerdictDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ChangelogTrailers::class)]
#[CoversClass(ChangelogTrailerVerdictDto::class)]
#[Small]
final class ChangelogTrailersTest extends TestCase
{
    private const string BARE_NONE = 'none';

    #[Test]
    #[DataProvider('validOptOuts')]
    public function noneWithADashAndAReasonOptsOut(string $value): void
    {
        $verdict = new ChangelogTrailers()->verdict($value);

        self::assertTrue($verdict->optsOut);
        self::assertSame([], $verdict->invalid);
    }

    /** @return Iterator<string, array{string}> */
    public static function validOptOuts(): Iterator
    {
        yield 'an em dash'       => ['none — tests only'];
        yield 'a hyphen'         => ['none - CI workflow tidy'];
        yield 'any case'         => ['None — internal refactor, no behaviour change'];
        yield 'no space at dash' => ['none—docs only'];
        yield 'surrounding space' => ["  none — tests only\t"];
        yield 'exactly the minimum reason' => ['none — docs fix'];
    }

    #[Test]
    #[DataProvider('invalidOptOuts')]
    public function aTrailerWithoutAUsableReasonIsInvalid(string $value): void
    {
        $verdict = new ChangelogTrailers()->verdict($value);

        self::assertFalse($verdict->optsOut);
        self::assertSame([$value], $verdict->invalid);
    }

    /** @return Iterator<string, array{string}> */
    public static function invalidOptOuts(): Iterator
    {
        yield 'bare none'         => [self::BARE_NONE];
        yield 'a dash only'       => ['none —'];
        yield 'one short word'    => ['none — wip'];
        yield 'one long word'     => ['none — refactoring'];
        yield 'short two words'   => ['none — a b'];
        yield 'one char too few'  => ['none — doc fix'];
        yield 'not none'          => ['yes — added it'];
        yield 'no dash'           => ['none tests only'];
    }

    #[Test]
    public function oneValidTrailerAmongInvalidOnesOptsOutAndTheInvalidOnesAreStillListed(): void
    {
        $verdict = new ChangelogTrailers()->verdict(self::BARE_NONE, 'none — docs only', 'whatever');

        self::assertTrue($verdict->optsOut);
        self::assertSame([self::BARE_NONE, 'whatever'], $verdict->invalid);
    }

    #[Test]
    public function noTrailersNeitherOptOutNorFail(): void
    {
        $verdict = new ChangelogTrailers()->verdict();

        self::assertFalse($verdict->optsOut);
        self::assertSame([], $verdict->invalid);
    }
}
