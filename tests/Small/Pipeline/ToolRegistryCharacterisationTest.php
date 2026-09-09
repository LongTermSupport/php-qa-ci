<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline;

use LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto;
use LTS\PHPQA\Pipeline\Tool\ToolRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation ("golden master") of the tool-registry behaviour the
 * shipped ToolRegistry must preserve byte-for-byte.
 *
 * The GOLDEN_* constants below are the frozen behavioural contract:
 *   - GOLDEN_ALIAS_MAP  — every -t input token accepted by bin/qa and the
 *                         canonical tool name it resolves to.
 *   - GOLDEN_PATH_SUPPORT — every token classified by the path-support gate,
 *                         true = the gate treats it as path-supporting.
 *   - GOLDEN_PHASE_ORDER — the ordered list of tools each phase runner invokes.
 *
 * They are derived from the registry with the same operations the CLI and
 * the runner use (resolve / tokens / toolsForPhase). Changing a golden value
 * is a deliberate behavioural change, not a refactor.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class ToolRegistryCharacterisationTest extends TestCase
{
    /**
     * Every accepted `-t <token>` and the canonical tool it resolves to.
     *
     * @var array<string, string>
     */
    private const array GOLDEN_ALIAS_MAP = [
        'allLints'                   => 'allLintingTools',
        'allStatic'                  => 'allStaticAnalysisTools',
        'allTests'                   => 'allTestingTools',
        'allCS'                      => 'allCodingStandardsTools',
        'psr'                        => 'psr4Validate',
        'psr4'                       => 'psr4Validate',
        'com'                        => 'composerChecks',
        'composer'                   => 'composerChecks',
        'st'                         => 'phpStrictTypes',
        'stricttypes'                => 'phpStrictTypes',
        'twigcs'                     => 'twigCsFixer',
        'lint'                       => 'phpLint',
        'phplint'                    => 'phpLint',
        'stan'                       => 'phpstan',
        'phpstan'                    => 'phpstan',
        'arch'                       => 'phpArkitect',
        'arkitect'                   => 'phpArkitect',
        'phparkitect'                => 'phpArkitect',
        'unit'                       => 'phpunit',
        'phpunit'                    => 'phpunit',
        'uniterate'                  => 'phpunit',
        'infect'                     => 'infection',
        'infection'                  => 'infection',
        'cr'                         => 'composerRequireChecker',
        'cda'                        => 'composerDependencyAnalyser',
        'cpd'                        => 'phpcpd',
        'phpcpd'                     => 'phpcpd',
        'ml'                         => 'markdownLinks',
        'markdown'                   => 'markdownLinks',
        'f'                          => 'phpCsFixer',
        'fixer'                      => 'phpCsFixer',
        'csfixer'                    => 'phpCsFixer',
        'r'                          => 'rector',
        'rector'                     => 'rector',
        'bnp'                        => 'branchNamePolicy',
        'pij'                        => 'phpstanIgnoreJustification',
        'phpstanIgnoreJustification' => 'phpstanIgnoreJustification',
        'branchNamePolicy'           => 'branchNamePolicy',
        'spu'                        => 'sensitiveParameterUsage',
        'sensitiveparameter'         => 'sensitiveParameterUsage',
        'sensitiveParameterUsage'    => 'sensitiveParameterUsage',
        'pt'                         => 'packageType',
        'packagetype'                => 'packageType',
        'packageType'                => 'packageType',
        'cti'                        => 'configTemplateIgnoreList',
        'configTemplateIgnoreList'   => 'configTemplateIgnoreList',
        'icsd'                       => 'infectionConfigSourceDirs',
        'infectionConfigSourceDirs'  => 'infectionConfigSourceDirs',
        'vp'                         => 'versionPins',
        'versionPins'                => 'versionPins',
    ];

    /**
     * Every token the path-support gate classifies, and whether it is treated
     * as path-supporting (true) or not (false).
     *
     * @var array<string, bool>
     */
    private const array GOLDEN_PATH_SUPPORT = [
        // path-supporting
        'phpstan'                    => true,
        'stan'                       => true,
        'phpCsFixer'                 => true,
        'fixer'                      => true,
        'f'                          => true,
        'csfixer'                    => true,
        'rector'                     => true,
        'r'                          => true,
        'phpLint'                    => true,
        'lint'                       => true,
        'phplint'                    => true,
        'phpStrictTypes'             => true,
        'stricttypes'                => true,
        'st'                         => true,
        'phpcpd'                     => true,
        'cpd'                        => true,
        'phpunit'                    => true,
        'unit'                       => true,
        // NOT path-supporting
        'twigCsFixer'                => false,
        'twigcs'                     => false,
        'composerChecks'             => false,
        'composer'                   => false,
        'com'                        => false,
        'infection'                  => false,
        'infect'                     => false,
        'composerRequireChecker'     => false,
        'cr'                         => false,
        'composerDependencyAnalyser' => false,
        'cda'                        => false,
        'markdownLinks'              => false,
        'markdown'                   => false,
        'ml'                         => false,
        'psr4Validate'               => false,
        'psr'                        => false,
        'psr4'                       => false,
        'uniterate'                  => false,
        'branchNamePolicy'           => false,
        'bnp'                        => false,
        'phpstanIgnoreJustification' => false,
        'pij'                        => false,
        'sensitiveParameterUsage'    => false,
        'spu'                        => false,
        'sensitiveparameter'         => false,
        'packageType'                => false,
        'configTemplateIgnoreList'   => false,
        'cti'                        => false,
        'infectionConfigSourceDirs'  => false,
        'icsd'                       => false,
        'versionPins'                => false,
        'vp'                         => false,
        'pt'                         => false,
        'packagetype'                => false,
        'phpArkitect'                => false,
        'arch'                       => false,
        'arkitect'                   => false,
        'phparkitect'                => false,
        'allLintingTools'            => false,
        'allLints'                   => false,
        'allStaticAnalysisTools'     => false,
        'allStatic'                  => false,
        'allTestingTools'            => false,
        'allTests'                   => false,
        'allCodingStandardsTools'    => false,
        'allCS'                      => false,
    ];

    /**
     * The ordered list of leaf tools each phase runner executes.
     *
     * @var array<string, list<string>>
     */
    private const array GOLDEN_PHASE_ORDER = [
        'allCodingStandardsTools' => ['rector', 'phpCsFixer', 'twigCsFixer'],
        'allLintingTools'         => [
            'psr4Validate',
            'composerChecks',
            'packageType',
            'configTemplateIgnoreList',
            'infectionConfigSourceDirs',
            'versionPins',
            'phpStrictTypes',
            'phpLint',
            'composerRequireChecker',
            'composerDependencyAnalyser',
            'markdownLinks',
        ],
        'allStaticAnalysisTools'  => ['branchNamePolicy', 'phpstanIgnoreJustification', 'phpstan', 'phpArkitect', 'sensitiveParameterUsage'],
        'allTestingTools'         => ['phpunit', 'infection'],
    ];

    public function testAliasMapMatchesGolden(): void
    {
        // Alias resolution is a key lookup, so the map is a set of token=>tool
        // mappings; declaration order is not a behavioural contract. Compare by
        // key to assert identical membership and mapping, order-independent.
        $expected = self::GOLDEN_ALIAS_MAP;
        $actual   = $this->extractAliasMap();
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
    }

    #[DataProvider('providePathSupport')]
    public function testPathSupportClassificationMatchesGolden(string $token, bool $expected): void
    {
        $actual = $this->extractPathClassification();
        self::assertArrayHasKey(
            $token,
            $actual,
            \sprintf("Token '%s' is no longer classified by the path-support gate.", $token),
        );
        self::assertSame(
            $expected,
            $actual[$token],
            \sprintf("Path-support classification for token '%s' changed.", $token),
        );
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function providePathSupport(): array
    {
        $cases = [];
        foreach (self::GOLDEN_PATH_SUPPORT as $token => $supported) {
            $cases[$token] = [$token, $supported];
        }

        return $cases;
    }

    public function testPathClassificationHasNoUnexpectedTokens(): void
    {
        $actual = array_keys($this->extractPathClassification());
        $golden = array_keys(self::GOLDEN_PATH_SUPPORT);
        sort($actual);
        sort($golden);
        self::assertSame($golden, $actual, 'The set of path-classified tokens drifted from the golden set.');
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('providePhaseOrder')]
    public function testPhaseOrderMatchesGolden(string $phaseRunner, array $expected): void
    {
        $actual = $this->extractPhaseOrder();
        self::assertArrayHasKey($phaseRunner, $actual, \sprintf("Phase runner '%s' produced no tool sequence.", $phaseRunner));
        self::assertSame($expected, $actual[$phaseRunner], \sprintf("Tool ordering for '%s' changed.", $phaseRunner));
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function providePhaseOrder(): array
    {
        $cases = [];
        foreach (self::GOLDEN_PHASE_ORDER as $phase => $tools) {
            $cases[$phase] = [$phase, $tools];
        }

        return $cases;
    }

    /** @return array<string, string> */
    private function extractAliasMap(): array
    {
        $map = [];
        foreach (ToolRegistry::shipped()->all() as $tool) {
            foreach ($tool->aliases as $alias) {
                $map[$alias] = $tool->target ?? $tool->name;
            }
        }

        return $map;
    }

    /** @return array<string, bool> */
    private function extractPathClassification(): array
    {
        $classification = [];
        foreach (ToolRegistry::shipped()->all() as $tool) {
            foreach ($tool->tokens() as $token) {
                $classification[$token] = $tool->supportsPaths;
            }
        }

        return $classification;
    }

    /** @return array<string, list<string>> */
    private function extractPhaseOrder(): array
    {
        $registry = ToolRegistry::shipped();
        $order    = [];
        foreach ($registry->all() as $runner) {
            if (!$runner->isPhaseRunner || null === $runner->phase) {
                continue;
            }

            $order[$runner->name] = array_map(
                static fn (ToolDefinitionDto $tool): string => $tool->name,
                $registry->toolsForPhase($runner->phase),
            );
        }

        return $order;
    }
}
