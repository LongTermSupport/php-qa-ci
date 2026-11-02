<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\ComposerChecks;

use LTS\PHPQA\Pipeline\Lane\ComposerChecks\CooldownReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CooldownReport::class)]
#[Small]
final class CooldownReportTest extends TestCase
{
    #[Test]
    public function anEmptyReportHasNoProblems(): void
    {
        $report = new CooldownReport();

        self::assertFalse($report->hasProblems());
        self::assertSame([], $report->overrides);
    }

    #[Test]
    public function mergingKeepsBothSidesInOrder(): void
    {
        $merged = new CooldownReport(['a'], ['x'])->merge(new CooldownReport(['b'], ['y']));

        self::assertTrue($merged->hasProblems());
        self::assertSame(['a', 'b'], $merged->problems);
        self::assertSame(['x', 'y'], $merged->overrides);
    }
}
