<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Tool;

use LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto;
use LTS\PHPQA\Pipeline\Tool\Exception\UnknownPhaseException;
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
#[\PHPUnit\Framework\Attributes\UsesClass(UnknownPhaseException::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(ToolGateEnum::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(PhaseEnum::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\PhaseDto::class)]
#[Small]
final class ToolRegistryTest extends TestCase
{
    private const string PHPSTAN = 'phpstan';

    private const string PHPUNIT = 'phpunit';

    private const string UNKNOWN_TOKEN = 'nope';

    private const string CHANGELOG = 'changelog';

    #[Test]
    public function anAliasResolvesToItsCanonicalDefinition(): void
    {
        $registry = ToolRegistry::shipped();

        self::assertSame(self::PHPSTAN, $registry->resolve('stan')->name);
        self::assertSame(self::PHPSTAN, $registry->resolve(self::PHPSTAN)->name);
        self::assertSame(self::PHPUNIT, $registry->resolve('uniterate')->target);
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
            ToolRegistry::shipped()->resolve(self::UNKNOWN_TOKEN);
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

        self::assertSame(PhaseEnum::Linting->value, $registry->resolve('allLints')->phase);
        self::assertTrue($registry->resolve('allLints')->isPhaseRunner);
        self::assertFalse($registry->resolve('lint')->isPhaseRunner);
    }

    #[Test]
    public function toolsForPhaseAreInRegistryOrderAndExcludeMetaAndPseudoTools(): void
    {
        $names = array_map(
            static fn (ToolDefinitionDto $tool): string => $tool->name,
            ToolRegistry::shipped()->toolsForPhase(PhaseEnum::Testing->value),
        );

        self::assertSame([self::PHPUNIT, 'infection'], $names);
    }

    #[Test]
    public function pathSupportIsClassifiedPerToken(): void
    {
        $registry = ToolRegistry::shipped();

        self::assertTrue($registry->supportsPaths('stan'));
        self::assertTrue($registry->supportsPaths(self::PHPSTAN));
        self::assertFalse($registry->supportsPaths('cr'));
        self::assertFalse($registry->supportsPaths(self::UNKNOWN_TOKEN));
        self::assertContains('rector', $registry->pathSupportingTokens());
        self::assertNotContains('cr', $registry->pathSupportingTokens());
    }

    #[Test]
    public function gatesDecideWhetherAPhasedToolRuns(): void
    {
        $registry = ToolRegistry::shipped();

        self::assertSame(ToolGateEnum::NotQuick, $registry->definition(self::PHPSTAN)->gate);
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
            \sprintf('%-26s %s', 'stan|phpstan', self::PHPSTAN),
            $lines,
        );
    }

    #[Test]
    public function definitionByCanonicalNameFailsForUnknownNames(): void
    {
        $this->expectException(UnknownToolException::class);

        ToolRegistry::shipped()->definition(self::UNKNOWN_TOKEN);
    }

    #[Test]
    public function theChangelogLaneIsALintingToolSelectedByItsTwoTokensWithoutPaths(): void
    {
        $registry = ToolRegistry::shipped();

        self::assertSame(self::CHANGELOG, $registry->resolve('cl')->name);
        self::assertSame(self::CHANGELOG, $registry->resolve(self::CHANGELOG)->name);
        self::assertSame(PhaseEnum::Linting->value, $registry->definition(self::CHANGELOG)->phase);
        self::assertFalse($registry->supportsPaths('cl'));
        self::assertFalse($registry->supportsPaths(self::CHANGELOG));
    }

    #[Test]
    public function aToolNamingAPhaseTheRegistryLacksIsRefused(): void
    {
        $this->expectException(UnknownPhaseException::class);
        $this->expectExceptionMessageIsOrContains('Unknown phase: nowhere (known: ' . PhaseEnum::Linting->value . ')');

        new ToolRegistry([PhaseEnum::Linting->phase()], [new ToolDefinitionDto('stray', ['s'], 'a tool in no known phase', 'nowhere', false)]);
    }

    #[Test]
    public function allListsThePhaseRunnersThenTheToolsInOrder(): void
    {
        $registry = new ToolRegistry(
            [PhaseEnum::Linting->phase()],
            [new ToolDefinitionDto('lintA', ['a'], 'a', PhaseEnum::Linting->value, false), new ToolDefinitionDto('pseudo', ['p'], 'p', null, false)],
        );

        self::assertSame(
            ['allLintingTools', 'lintA', 'pseudo'],
            array_map(static fn (ToolDefinitionDto $tool): string => $tool->name, $registry->all()),
        );
    }

    #[Test]
    public function theJsonCapableToolsAreKnown(): void
    {
        $registry = ToolRegistry::shipped();

        self::assertTrue($registry->definition(self::PHPSTAN)->supportsJson);
        self::assertFalse($registry->definition(self::PHPUNIT)->supportsJson);
    }
}
