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
}
