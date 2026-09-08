<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Tool;

use LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto;
use LTS\PHPQA\Pipeline\Tool\Exception\UnknownToolException;
use LTS\PHPQA\Pipeline\Tool\PhaseEnum;
use LTS\PHPQA\Pipeline\Tool\ToolGateEnum;
use LTS\PHPQA\Pipeline\Tool\ToolRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The registry's behaviour. The frozen alias map, path-support classification
 * and phase order are asserted by ToolRegistryCharacterisationTest; this test
 * covers the operations on top of that data.
 *
 * @internal
 */
#[CoversClass(ToolRegistry::class)]
#[CoversClass(ToolDefinitionDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(UnknownToolException::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(ToolGateEnum::class)]
#[Small]
final class ToolRegistryTest extends TestCase
{
    #[Test]
    public function anAliasResolvesToItsCanonicalDefinition(): void
    {
        $registry = ToolRegistry::shipped();

        self::assertSame('phpstan', $registry->resolve('stan')->name);
        self::assertSame('phpstan', $registry->resolve('phpstan')->name);
        self::assertSame('phpunit', $registry->resolve('uniterate')->target);
    }

    #[Test]
    public function aCanonicalNameThatIsNotAlsoAnAliasIsNotAValidToken(): void
    {
        $this->expectException(UnknownToolException::class);

        ToolRegistry::shipped()->resolve('psr4Validate');
    }

    #[Test]
    public function theUnknownToolExceptionNamesTheToken(): void
    {
        try {
            ToolRegistry::shipped()->resolve('nope');
        } catch (UnknownToolException $unknownToolException) {
            self::assertStringContainsString('Invalid tool: nope', $unknownToolException->getMessage());

            return;
        }

        self::fail('expected UnknownToolException');
    }

    #[Test]
    public function theMetaTargetsSelectAPhase(): void
    {
        $registry = ToolRegistry::shipped();

        self::assertSame(PhaseEnum::Linting, $registry->resolve('allLints')->phase);
        self::assertTrue($registry->resolve('allLints')->isPhaseRunner);
        self::assertFalse($registry->resolve('lint')->isPhaseRunner);
    }

    #[Test]
    public function toolsForPhaseAreInRegistryOrderAndExcludeMetaAndPseudoTools(): void
    {
        $names = array_map(
            static fn (ToolDefinitionDto $tool): string => $tool->name,
            ToolRegistry::shipped()->toolsForPhase(PhaseEnum::Testing),
        );

        self::assertSame(['phpunit', 'infection'], $names);
    }

    #[Test]
    public function pathSupportIsClassifiedPerToken(): void
    {
        $registry = ToolRegistry::shipped();

        self::assertTrue($registry->supportsPaths('stan'));
        self::assertTrue($registry->supportsPaths('phpstan'));
        self::assertFalse($registry->supportsPaths('cr'));
        self::assertFalse($registry->supportsPaths('nope'));
        self::assertContains('rector', $registry->pathSupportingTokens());
        self::assertNotContains('cr', $registry->pathSupportingTokens());
    }

    #[Test]
    public function gatesDecideWhetherAPhasedToolRuns(): void
    {
        $registry = ToolRegistry::shipped();

        self::assertSame(ToolGateEnum::NotQuick, $registry->definition('phpstan')->gate);
        self::assertSame(ToolGateEnum::Infection, $registry->definition('infection')->gate);
        self::assertSame(ToolGateEnum::None, $registry->definition('phpLint')->gate);

        self::assertTrue(ToolGateEnum::None->allows(quickTests: true, infectionEnabled: false));
        self::assertFalse(ToolGateEnum::NotQuick->allows(quickTests: true, infectionEnabled: true));
        self::assertTrue(ToolGateEnum::NotQuick->allows(quickTests: false, infectionEnabled: false));
        self::assertFalse(ToolGateEnum::Infection->allows(quickTests: false, infectionEnabled: false));
        self::assertFalse(ToolGateEnum::Infection->allows(quickTests: true, infectionEnabled: true));
        self::assertTrue(ToolGateEnum::Infection->allows(quickTests: false, infectionEnabled: true));
    }

    #[Test]
    public function usageLinesAreDerivedFromTheRegistryInOrder(): void
    {
        $lines = ToolRegistry::shipped()->usageLines();

        self::assertStringStartsWith('allCS', $lines[0]);
        self::assertContains(
            \sprintf('%-26s %s', 'stan|phpstan', 'phpstan'),
            $lines,
        );
    }

    #[Test]
    public function definitionByCanonicalNameFailsForUnknownNames(): void
    {
        $this->expectException(UnknownToolException::class);

        ToolRegistry::shipped()->definition('nope');
    }

    #[Test]
    public function theJsonCapableToolsAreKnown(): void
    {
        $registry = ToolRegistry::shipped();

        self::assertTrue($registry->definition('phpstan')->supportsJson);
        self::assertFalse($registry->definition('phpunit')->supportsJson);
    }
}
