<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\ProjectRecord;

use LTS\PHPQA\PHPStan\ProjectRecord\Dto\JustificationFindingDto;
use LTS\PHPQA\PHPStan\ProjectRecord\IgnoreErrorsJustificationDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * PHPStan's ignoreErrors is this toolchain's project record, and PHPStan gives
 * an entry no field for a reason. The convention is therefore the toolchain's
 * to define and check: every entry is immediately preceded by a comment that
 * names the hazard accepted and why it is acceptable at that path, and a
 * comment that could be pasted onto any entry unchanged does not count.
 *
 * @internal
 */
#[CoversClass(IgnoreErrorsJustificationDetector::class)]
#[UsesClass(JustificationFindingDto::class)]
#[Small]
final class IgnoreErrorsJustificationDetectorTest extends TestCase
{
    private const string JUSTIFIED = <<<'NEON'
        parameters:
            level: max
            ignoreErrors:
                # Symfony DI attributes are referenced by name for string comparison in this
                # rule; they are not a runtime dependency, so the class cannot be found here.
                -
                    identifier: class.notFound
                    path: ../src/PHPStan/Rules/RequireExplicitDIAttributeRule.php
                    reportUnmatched: false
                # The legacy importer builds SQL from trusted constants only, and is deleted
                # in the next release; scoped to that one file.
                - '#Raw SQL#'
            paths:
                - src
        NEON;

    private const string NO_JUSTIFICATION = 'no justification: add a comment directly above the entry naming the hazard accepted and why it is acceptable at this path';

    private const string UNJUSTIFIED = <<<'NEON'
        parameters:
            ignoreErrors:
                -
                    identifier: class.notFound
                    path: ../src/A.php
                # needed for now
                - '#Raw SQL#'
                # short one
                -
                    message: '#Undefined variable#'
        NEON;

    #[Test]
    public function justifiedEntriesProduceNoFindings(): void
    {
        self::assertSame([], new IgnoreErrorsJustificationDetector()->check(self::JUSTIFIED));
    }

    #[Test]
    public function eachUnjustifiedEntryIsReportedWithItsLineAndFault(): void
    {
        $findings = new IgnoreErrorsJustificationDetector()->check(self::UNJUSTIFIED);

        self::assertCount(3, $findings);
        self::assertSame(3, $findings[0]->line);
        self::assertStringContainsString('no justification', $findings[0]->fault);
        self::assertStringContainsString('class.notFound', $findings[0]->entry);
        self::assertSame(7, $findings[1]->line);
        self::assertStringContainsString('paste', $findings[1]->fault);
        self::assertSame(9, $findings[2]->line);
        self::assertStringContainsString('too short', $findings[2]->fault);
    }

    /**
     * What names each entry: the inline text after the dash with its padding
     * trimmed, else the first line of its body that is neither blank nor a
     * comment, else nothing for a bare dash that ends the file.
     */
    #[Test]
    public function eachEntryIsNamedByItsInlineTextOrItsFirstMeaningfulBodyLine(): void
    {
        $neon = "parameters:\n    ignoreErrors:\n        - '#Raw SQL#'   \n        -\n            # matched literally\n            message: '#Undefined#'\n        -\n\n            identifier: a.b\n        -\n";

        self::assertSame(
            [
                [3, "'#Raw SQL#'", self::NO_JUSTIFICATION],
                [4, "message: '#Undefined#'", self::NO_JUSTIFICATION],
                [7, 'identifier: a.b', self::NO_JUSTIFICATION],
                [10, '', self::NO_JUSTIFICATION],
            ],
            $this->findings($neon),
        );
    }

    /**
     * A whitespace-only line ends neither the list nor the comment above an
     * entry; any other line between them does, so an entry never borrows the
     * comment of the entry above it.
     */
    #[Test]
    public function onlyTheCommentDirectlyAboveAnEntryJustifiesIt(): void
    {
        $neon = "parameters:\n    ignoreErrors:\n        # The legacy importer builds SQL from trusted constants only; scoped to it.\n  \n        - '#Raw SQL#'\n        - '#Undefined#'\n";

        self::assertSame([[6, "'#Undefined#'", self::NO_JUSTIFICATION]], $this->findings($neon));
    }

    /** Comment lines are joined with single spaces, each stripped of its `#` and padding, and bare `#` lines add nothing. */
    #[Test]
    public function theCommentIsJoinedFromItsLinesBeforeItIsJudged(): void
    {
        $neon = "parameters:\n    ignoreErrors:\n        #\n        #   legacy\n        #\n        - '#a#'\n        # too\n        # short\n        - '#b#'\n";

        self::assertSame(
            [
                [6, "'#a#'", 'the justification "legacy" could be pasted onto any entry unchanged; name the hazard accepted and the scope'],
                [9, "'#b#'", 'the justification "too short" is too short to name a hazard and a scope'],
            ],
            $this->findings($neon),
        );
    }

    #[Test]
    public function aJustificationOfExactlyTheMinimumLengthIsLongEnough(): void
    {
        $enough   = str_pad('The importer builds SQL from constants', IgnoreErrorsJustificationDetector::MIN_LENGTH, '!');
        $tooShort = substr($enough, 1);

        self::assertSame(
            [[6, "'#b#'", \sprintf('the justification "%s" is too short to name a hazard and a scope', $tooShort)]],
            $this->findings("parameters:\n    ignoreErrors:\n        # " . $enough . "\n        - '#a#'\n        # " . $tooShort . "\n        - '#b#'\n"),
        );
    }

    #[Test]
    public function anIgnoreErrorsKeyOnTheFirstLineIsRead(): void
    {
        self::assertSame(1, new IgnoreErrorsJustificationDetector()->entryCount("ignoreErrors:\n    - '#a#'\n"));
    }

    #[Test]
    public function aFileWithoutIgnoreErrorsHasNothingToCheck(): void
    {
        self::assertSame([], new IgnoreErrorsJustificationDetector()->check("parameters:\n    level: max\n"));
    }

    #[Test]
    public function anEmptyIgnoreErrorsListHasNothingToCheck(): void
    {
        self::assertSame([], new IgnoreErrorsJustificationDetector()->check("parameters:\n    ignoreErrors: []\n"));
    }

    #[Test]
    public function theEntryCountIsTheItemsNotEveryDashUnderThem(): void
    {
        $neon = "parameters:\n    ignoreErrors:\n        -\n            identifier: a.b\n            paths:\n                - ../src/A.php\n                - ../src/B.php\n        - '#plain#'\n    level: max\n";

        self::assertSame(2, new IgnoreErrorsJustificationDetector()->entryCount($neon));
        self::assertSame(0, new IgnoreErrorsJustificationDetector()->entryCount("parameters:\n    ignoreErrors: ['#a#', '#b#']\n"));
    }

    /** @return list<array{int, string, string}> each finding as [line, entry, fault] */
    private function findings(string $neon): array
    {
        return array_map(
            static fn (JustificationFindingDto $finding): array => [$finding->line, $finding->entry, $finding->fault],
            new IgnoreErrorsJustificationDetector()->check($neon),
        );
    }
}
