<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Tool\Dto;

use LTS\PHPQA\Pipeline\Tool\Dto\PhaseDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto;
use LTS\PHPQA\Pipeline\Tool\PhaseEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PhaseDto::class)]
#[CoversClass(PhaseEnum::class)]
#[UsesClass(ToolDefinitionDto::class)]
#[Small]
final class PhaseDtoTest extends TestCase
{
    #[Test]
    public function aPhaseDerivesItsRunnerDefinition(): void
    {
        $runner = new PhaseDto('security', 'Running All Security Tools', 'allSecurityTools', ['allSec'], 'all security tools')->runner();

        self::assertSame('allSecurityTools', $runner->name);
        self::assertSame(['allSec'], $runner->aliases);
        self::assertSame('all security tools', $runner->description);
        self::assertSame('security', $runner->phase);
        self::assertTrue($runner->isPhaseRunner);
        self::assertFalse($runner->supportsPaths);
        self::assertNull($runner->target);
    }

    #[Test]
    public function theShippedPhasesKeepTheirNamesRunnersAndBanners(): void
    {
        $expected = [
            'codingStandards' => ['allCodingStandardsTools', 'allCS', 'Running All Coding Standards Tools (That Might Make Code Changes)'],
            'linting'         => ['allLintingTools', 'allLints', 'Running All Linting Tools'],
            'staticAnalysis'  => ['allStaticAnalysisTools', 'allStatic', 'Running All Static Analysis Tools'],
            'testing'         => ['allTestingTools', 'allTests', 'Running All Testing Tools'],
        ];

        $actual = [];
        foreach (PhaseEnum::cases() as $case) {
            $phase                = $case->phase();
            $actual[$phase->name] = [$phase->runnerName, implode('|', $phase->runnerAliases), $phase->banner];
            self::assertSame($case->value, $phase->name);
        }

        self::assertSame($expected, $actual);
    }
}
