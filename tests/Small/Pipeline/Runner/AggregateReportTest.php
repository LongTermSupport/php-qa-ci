<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Runner;

use LTS\PHPQA\Pipeline\Runner\AggregateReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @internal
 */
#[CoversClass(AggregateReport::class)]
#[Small]
final class AggregateReportTest extends TestCase
{
    private const string DBF_LINE = '        Defence Before Fix (DBF): https://defence-before-fix.github.io/ - agent prompt: https://defence-before-fix.github.io/defence-before-fix-project-prompt.md';

    #[Test]
    public function aFailedRunListsTheToolsAndPointsAtTheMethodAndItsAgentPrompt(): void
    {
        $output = new BufferedOutput();

        self::assertFalse(new AggregateReport($output)->report('phpLint', 'phpstan'));

        $printed = $output->fetch();
        self::assertStringContainsString('Aggregate (read-only) run: 2 tool(s) FAILED', $printed);
        self::assertStringContainsString(self::DBF_LINE . "\n", $printed);
    }

    #[Test]
    public function aGreenRunDoesNotPrintThePointer(): void
    {
        $output = new BufferedOutput();

        self::assertTrue(new AggregateReport($output)->report());

        self::assertStringNotContainsString('Defence Before Fix', $output->fetch());
    }
}
