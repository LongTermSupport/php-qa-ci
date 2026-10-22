<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Tool;

use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Tool\Dto\PhaseDto;
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
#[\PHPUnit\Framework\Attributes\UsesClass(PhaseDto::class)]
#[Small]
final class ToolRegistryTest extends TestCase
{
    private const string PHPSTAN = 'phpstan';

    private const string PHPUNIT = 'phpunit';

    private const string UNKNOWN_TOKEN = 'nope';

    private const string CHANGELOG = 'changelog';

    private const string INFECTION = 'infection';

    private const string STAN = 'stan';

    private const string ALL_LINTS = 'allLints';

    private const string LINT_A = 'lintA';

    private const string PHP_ARKITECT = 'phpArkitect';

    #[Test]
    public function anAliasResolvesToItsCanonicalDefinition(): void
    {
        $registry = ToolRegistry::shipped();

        self::assertSame(self::PHPSTAN, $registry->resolve(self::STAN)->name);
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
    public function acceptsAnswersExactlyWhatResolveAccepts(): void
    {
        $registry = ToolRegistry::shipped();

        self::assertTrue($registry->accepts(self::STAN));
        self::assertTrue($registry->accepts('allCS'));
        self::assertFalse($registry->accepts('allCs'), 'tokens are case-sensitive');
        self::assertFalse($registry->accepts('markdownLinks'), 'a canonical name that is not also an alias is not a token');
        self::assertFalse($registry->accepts(self::UNKNOWN_TOKEN));
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

        self::assertSame(PhaseEnum::Linting->value, $registry->resolve(self::ALL_LINTS)->phase);
        self::assertTrue($registry->resolve(self::ALL_LINTS)->isPhaseRunner);
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

        self::assertTrue($registry->supportsPaths(self::STAN));
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
            [new ToolDefinitionDto(self::LINT_A, ['a'], 'a', PhaseEnum::Linting->value, false), new ToolDefinitionDto('pseudo', ['p'], 'p', null, false)],
        );

        self::assertSame(
            ['allLintingTools', self::LINT_A, 'pseudo'],
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

    /**
     * Every shipped row, in registry order: tokens, phase, path support, gate, runner flag,
     * target, JSON and agent-mode support. A changed flag is a changed CLI, so it shows here.
     */
    #[Test]
    public function theShippedRegistryIsExactlyThisTable(): void
    {
        $rows = [];
        foreach (ToolRegistry::shipped()->all() as $tool) {
            $rows[$tool->name] = implode(' ', [
                $tool->display(),
                $tool->phase ?? '-',
                $tool->supportsPaths ? 'paths' : '-',
                $tool->gate->name,
                $tool->isPhaseRunner ? 'runner' : '-',
                $tool->target ?? '-',
                $tool->supportsJson ? 'json' : '-',
                $tool->supportsAgentMode ? 'agent' : '-',
            ]);
        }

        self::assertSame(
            [
                'allCodingStandardsTools'    => 'allCS codingStandards - None runner - - -',
                'allLintingTools'            => 'allLints linting - None runner - - -',
                'allStaticAnalysisTools'     => 'allStatic staticAnalysis - None runner - - -',
                'allTestingTools'            => 'allTests testing - None runner - - -',
                'dependencyFreshness'        => 'df|dependencyFreshness - - None - - - -',
                'rector'                     => 'r|rector codingStandards paths None - - - -',
                'phpCsFixer'                 => 'f|fixer|csfixer codingStandards paths None - - - -',
                'twigCsFixer'                => 'twigcs codingStandards - None - - - -',
                'markdownFormat'             => 'mdf|markdownFormat codingStandards - None - - - -',
                'psr4Validate'               => 'psr|psr4 linting - None - - - -',
                'composerChecks'             => 'com|composer linting - None - - - -',
                'packageType'                => 'pt|packagetype|packageType linting - None - - - -',
                'configTemplateIgnoreList'   => 'cti|configTemplateIgnoreList linting - None - - - -',
                'infectionConfigSourceDirs'  => 'icsd|infectionConfigSourceDirs linting - None - - - -',
                'analysedPaths'              => 'ap|analysedPaths linting - None - - - -',
                'versionPins'                => 'vp|versionPins linting - None - - - -',
                self::CHANGELOG              => 'cl|changelog linting - None - - - -',
                'phpStrictTypes'             => 'st|stricttypes linting paths None - - - -',
                'phpLint'                    => 'lint|phplint linting paths None - - - -',
                'opcache'                    => 'oc|opcache linting paths None - - - -',
                'composerRequireChecker'     => 'cr linting - None - - - -',
                'composerDependencyAnalyser' => 'cda linting - None - - - -',
                'markdownLinks'              => 'ml|markdown linting - None - - - -',
                'docsProse'                  => 'dp|prose|docsProse linting - None - - - -',
                'documentedQaCommands'       => 'dqc|documentedQaCommands linting - None - - - -',
                'yamlLint'                   => 'yaml linting - None - - - -',
                'shellCheck'                 => 'sc|shellcheck|shellCheck linting paths None - - - -',
                'branchNamePolicy'           => 'bnp|branchNamePolicy staticAnalysis - None - - - -',
                'phpstanIgnoreJustification' => 'pij|phpstanIgnoreJustification staticAnalysis - None - - - -',
                self::PHPSTAN                => 'stan|phpstan staticAnalysis paths NotQuick - - json agent',
                'deadCode'                   => 'dcd|deadcode|deadCode staticAnalysis - NotQuick - - - -',
                self::PHP_ARKITECT           => 'arch|arkitect|phparkitect staticAnalysis - None - - - agent',
                'sensitiveParameterUsage'    => 'spu|sensitiveparameter|sensitiveParameterUsage staticAnalysis - None - - - -',
                self::PHPUNIT                => 'unit|phpunit testing paths NotQuick - - - -',
                self::INFECTION              => 'infect|infection testing - Infection - - - -',
                'phpcpd'                     => 'cpd|phpcpd - paths None - - - -',
                'uniterate'                  => 'uniterate - - None - phpunit - -',
            ],
            $rows,
        );
    }

    #[Test]
    public function thePhasesAreAListInExecutionOrder(): void
    {
        self::assertSame(
            [PhaseEnum::CodingStandards->value, PhaseEnum::Linting->value, PhaseEnum::StaticAnalysis->value, PhaseEnum::Testing->value],
            array_map(static fn (PhaseDto $phase): string => $phase->name, ToolRegistry::shipped()->phases()),
        );
    }

    #[Test]
    public function onlySymfonyAddsALaneAndOnlyToTheLintingPhase(): void
    {
        $lanes = ToolRegistry::platformLanes(PlatformEnum::Symfony, PhaseEnum::Linting->value);

        self::assertCount(1, $lanes);
        self::assertSame('twigLint', $lanes[0]->name);
        self::assertSame([], $lanes[0]->aliases);
        self::assertSame(PhaseEnum::Linting->value, $lanes[0]->phase);
        self::assertFalse($lanes[0]->supportsPaths);
        self::assertSame('Running Twig Linter', $lanes[0]->banner);
        self::assertSame([], ToolRegistry::platformLanes(PlatformEnum::Symfony, PhaseEnum::Testing->value));
        self::assertSame([], ToolRegistry::platformLanes(PlatformEnum::Generic, PhaseEnum::Linting->value));
    }

    #[Test]
    public function theAgentModeToolsAreListedInRegistryOrder(): void
    {
        self::assertSame([self::PHPSTAN, self::PHP_ARKITECT], ToolRegistry::shipped()->agentModeTools());
    }

    /** Every token of every path-supporting tool, the canonical name included even when it is not an alias. */
    #[Test]
    public function thePathSupportingTokensAreEveryTokenOfEveryPathSupportingTool(): void
    {
        self::assertSame(
            ['r', 'rector', 'phpCsFixer', 'f', 'fixer', 'csfixer', 'phpStrictTypes', 'st', 'stricttypes', 'phpLint', 'lint', 'phplint', 'oc', 'opcache', 'sc', 'shellcheck', 'shellCheck', self::STAN, self::PHPSTAN, 'unit', self::PHPUNIT, 'cpd', 'phpcpd'],
            ToolRegistry::shipped()->pathSupportingTokens(),
        );
    }

    /** A canonical name that is not one of its aliases still answers for its path support. */
    #[Test]
    public function aCanonicalNameAnswersForPathSupportWhenItIsNoAlias(): void
    {
        $registry = ToolRegistry::shipped();

        self::assertTrue($registry->supportsPaths('phpCsFixer'));
        self::assertFalse($registry->supportsPaths('psr4Validate'));
    }

    /** A pseudo-tool placed in a phase is not one of that phase's tools: it runs another tool. */
    #[Test]
    public function aPhasedPseudoToolIsNotOneOfItsPhasesTools(): void
    {
        $registry = new ToolRegistry(
            [PhaseEnum::Linting->phase()],
            [
                new ToolDefinitionDto(self::LINT_A, ['a'], 'a', PhaseEnum::Linting->value, false),
                new ToolDefinitionDto('lintAgain', ['again'], 'again', PhaseEnum::Linting->value, false, target: self::LINT_A),
            ],
        );

        self::assertSame([self::LINT_A], array_map(static fn (ToolDefinitionDto $tool): string => $tool->name, $registry->toolsForPhase(PhaseEnum::Linting->value)));
        self::assertSame('allLintingTools', $registry->resolve(self::ALL_LINTS)->name);
    }

    #[Test]
    public function thereIsOneUsageLinePerTool(): void
    {
        $registry = ToolRegistry::shipped();

        self::assertCount(\count($registry->all()), $registry->usageLines());
        self::assertSame(\sprintf('%-26s %s', 'uniterate', 'phpunit iterative mode - prioritise broken tests and fail on error'), $registry->usageLines()[36]);
    }
}
