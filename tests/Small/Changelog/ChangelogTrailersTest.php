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

    private const string TRAILER_LINE = "Changelog: none — tests only\n";

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
    public function aChangelogLineGitReadsAsATrailerIsNotMisplaced(): void
    {
        $body = "Why.\n\n" . self::TRAILER_LINE . "Co-Authored-By: X <x@example.invalid>\n";

        self::assertSame([], new ChangelogTrailers()->misplaced($body, self::TRAILER_LINE));
    }

    #[Test]
    public function aChangelogLineGitDoesNotReadAsATrailerIsMisplaced(): void
    {
        $body = "Changelog: none — tests only\n\nCo-Authored-By: X <x@example.invalid>\n";

        self::assertSame(['Changelog: none — tests only'], new ChangelogTrailers()->misplaced($body, ''));
    }

    #[Test]
    public function anIndentedOrMidLineChangelogIsProseAndNotJudged(): void
    {
        $body = "  Changelog: none — indented prose\nThe Changelog: mid-line\n";

        self::assertSame([], new ChangelogTrailers()->misplaced($body, ''));
    }

    #[Test]
    public function theKeyIsMatchedAsGitMatchesItInAnyCaseWithSpaceBeforeTheColon(): void
    {
        $body = "changelog: none — lower case above\nCHANGELOG : none — spaced above\n\nCo-Authored-By: X <x@example.invalid>\n";

        self::assertSame(['changelog: none — lower case above', 'CHANGELOG : none — spaced above'], new ChangelogTrailers()->misplaced($body, ''));
    }

    #[Test]
    public function aLowerCaseOrSpacedLineGitReadsAsATrailerIsNotMisplaced(): void
    {
        $body = "Why.\n\nchangelog : none — tests only\nCo-Authored-By: X <x@example.invalid>\n";

        self::assertSame([], new ChangelogTrailers()->misplaced($body, "changelog: none — tests only\n"));
    }

    #[Test]
    public function aShorterLineAboveTheBlockIsTheOneNamedNotTheFullLineInIt(): void
    {
        $body = "Changelog: none\n\n" . self::TRAILER_LINE . "Co-Authored-By: X <x@example.invalid>\n";

        self::assertSame(['Changelog: none'], new ChangelogTrailers()->misplaced($body, self::TRAILER_LINE));
    }

    #[Test]
    public function aBareKeyAboveTheBlockIsTheOneNamedNotTheLineInIt(): void
    {
        $body = "Changelog:\n\n" . self::TRAILER_LINE;

        self::assertSame(['Changelog:'], new ChangelogTrailers()->misplaced($body, self::TRAILER_LINE));
    }

    #[Test]
    public function aLineInsideAFencedBlockInTheBodyIsMisplaced(): void
    {
        $body = "For example:\n\n```\n" . self::TRAILER_LINE . "```\n\nCo-Authored-By: X <x@example.invalid>\n";

        self::assertSame(['Changelog: none — tests only'], new ChangelogTrailers()->misplaced($body, ''));
    }

    #[Test]
    public function eachTrailerAccountsForOneLineSoADuplicateAboveTheBlockIsMisplaced(): void
    {
        $body = self::TRAILER_LINE . "\n" . self::TRAILER_LINE;

        self::assertSame(['Changelog: none — tests only'], new ChangelogTrailers()->misplaced($body, self::TRAILER_LINE));
    }

    #[Test]
    public function aFoldedTrailerAccountsForItsFirstLine(): void
    {
        $body = "Changelog: none — tests only,\n  continued here\n";

        self::assertSame([], new ChangelogTrailers()->misplaced($body, "Changelog: none — tests only, continued here\n"));
    }

    #[Test]
    public function trailingWhitespaceDoesNotMakeALineMisplaced(): void
    {
        self::assertSame([], new ChangelogTrailers()->misplaced("Changelog:  none — tests only \t\n", self::TRAILER_LINE));
    }

    #[Test]
    public function noTrailersNeitherOptOutNorFail(): void
    {
        $verdict = new ChangelogTrailers()->verdict();

        self::assertFalse($verdict->optsOut);
        self::assertSame([], $verdict->invalid);
    }
}
