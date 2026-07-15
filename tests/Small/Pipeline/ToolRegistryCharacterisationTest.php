<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation ("golden master") of the tool-registry behaviour that the
 * M-011 SSoT refactor must preserve byte-for-byte.
 *
 * The GOLDEN_* constants below are the frozen behavioural contract:
 *   - GOLDEN_ALIAS_MAP  — every -t input token accepted by bin/qa and the
 *                         canonical tool name it resolves to.
 *   - GOLDEN_PATH_SUPPORT — every token classified by the path-support gate
 *                         (options.inc.bash tool_supports_paths), true = the
 *                         gate treats it as path-supporting.
 *   - GOLDEN_PHASE_ORDER — the ordered list of tools each phase runner invokes.
 *
 * These values are asserted against whatever is the CURRENT single source of
 * truth. Before the refactor that source is the hand-written structures in
 * includes/options.inc.bash and the four includes/generic/all*Tools.inc.bash
 * files; after the refactor it is includes/generic/toolRegistry.inc.bash. The
 * golden constants do not change — only the extraction target moves, which is
 * precisely the point: the registry becomes the SSoT without altering any
 * observable resolution/classification/ordering behaviour.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class ToolRegistryCharacterisationTest extends TestCase
{
    private const INCLUDES_DIR = __DIR__ . '/../../../includes';

    /**
     * Every accepted `-t <token>` and the canonical tool it resolves to.
     *
     * @var array<string, string>
     */
    private const GOLDEN_ALIAS_MAP = [
        'allLints'                 => 'allLintingTools',
        'allStatic'                => 'allStaticAnalysisTools',
        'allTests'                 => 'allTestingTools',
        'allCS'                    => 'allCodingStandardsTools',
        'psr'                      => 'psr4Validate',
        'psr4'                     => 'psr4Validate',
        'com'                      => 'composerChecks',
        'composer'                 => 'composerChecks',
        'st'                       => 'phpStrictTypes',
        'stricttypes'              => 'phpStrictTypes',
        'lint'                     => 'phpLint',
        'phplint'                  => 'phpLint',
        'stan'                     => 'phpstan',
        'phpstan'                  => 'phpstan',
        'arch'                     => 'phpArkitect',
        'arkitect'                 => 'phpArkitect',
        'phparkitect'              => 'phpArkitect',
        'unit'                     => 'phpunit',
        'phpunit'                  => 'phpunit',
        'uniterate'                => 'phpunit',
        'infect'                   => 'infection',
        'infection'                => 'infection',
        'cr'                       => 'composerRequireChecker',
        'ml'                       => 'markdownLinks',
        'markdown'                 => 'markdownLinks',
        'l'                        => 'phploc',
        'loc'                      => 'phploc',
        'f'                        => 'phpCsFixer',
        'fixer'                    => 'phpCsFixer',
        'csfixer'                  => 'phpCsFixer',
        'r'                        => 'rector',
        'rector'                   => 'rector',
        'bnp'                      => 'branchNamePolicy',
        'branchNamePolicy'         => 'branchNamePolicy',
        'spu'                      => 'sensitiveParameterUsage',
        'sensitiveparameter'       => 'sensitiveParameterUsage',
        'sensitiveParameterUsage'  => 'sensitiveParameterUsage',
        'pt'                       => 'packageType',
        'packagetype'              => 'packageType',
        'packageType'              => 'packageType',
    ];

    /**
     * Every token the path-support gate classifies, and whether it is treated
     * as path-supporting (true) or not (false).
     *
     * @var array<string, bool>
     */
    private const GOLDEN_PATH_SUPPORT = [
        // path-supporting
        'phpstan'        => true,
        'stan'           => true,
        'phpCsFixer'     => true,
        'fixer'          => true,
        'f'              => true,
        'csfixer'        => true,
        'rector'         => true,
        'r'              => true,
        'phpLint'        => true,
        'lint'           => true,
        'phplint'        => true,
        'phpStrictTypes' => true,
        'stricttypes'    => true,
        'st'             => true,
        'phploc'         => true,
        'loc'            => true,
        'l'              => true,
        'phpunit'        => true,
        'unit'           => true,
        // NOT path-supporting
        'composerChecks'          => false,
        'composer'                => false,
        'com'                     => false,
        'infection'               => false,
        'infect'                  => false,
        'composerRequireChecker'  => false,
        'cr'                      => false,
        'markdownLinks'           => false,
        'markdown'                => false,
        'ml'                      => false,
        'psr4Validate'            => false,
        'psr'                     => false,
        'psr4'                    => false,
        'uniterate'               => false,
        'branchNamePolicy'        => false,
        'bnp'                     => false,
        'sensitiveParameterUsage' => false,
        'spu'                     => false,
        'sensitiveparameter'      => false,
        'packageType'             => false,
        'pt'                      => false,
        'packagetype'             => false,
        'phpArkitect'             => false,
        'arch'                    => false,
        'arkitect'                => false,
        'phparkitect'             => false,
        'allLintingTools'         => false,
        'allLints'                => false,
        'allStaticAnalysisTools'  => false,
        'allStatic'               => false,
        'allTestingTools'         => false,
        'allTests'                => false,
        'allCodingStandardsTools' => false,
        'allCS'                   => false,
    ];

    /**
     * The ordered list of leaf tools each phase runner executes.
     *
     * @var array<string, list<string>>
     */
    private const GOLDEN_PHASE_ORDER = [
        'allCodingStandardsTools' => ['rector', 'phpCsFixer'],
        'allLintingTools'         => [
            'psr4Validate',
            'composerChecks',
            'packageType',
            'phpStrictTypes',
            'phpLint',
            'composerRequireChecker',
            'markdownLinks',
        ],
        'allStaticAnalysisTools' => ['branchNamePolicy', 'phpstan', 'phpArkitect', 'sensitiveParameterUsage'],
        'allTestingTools'        => ['phpunit', 'infection'],
    ];

    public function testAliasMapMatchesGolden(): void
    {
        // Alias resolution is a key lookup, so the map is a set of token=>tool
        // mappings; declaration order is not a behavioural contract. Compare by
        // key to assert identical membership and mapping, order-independent.
        $expected = self::GOLDEN_ALIAS_MAP;
        $actual   = $this->extractAliasMap();
        \ksort($expected);
        \ksort($actual);
        self::assertSame($expected, $actual);
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

    #[DataProvider('providePathSupport')]
    public function testPathSupportClassificationMatchesGolden(string $token, bool $expected): void
    {
        $actual = $this->extractPathClassification();
        self::assertArrayHasKey(
            $token,
            $actual,
            "Token '{$token}' is no longer classified by the path-support gate.",
        );
        self::assertSame(
            $expected,
            $actual[$token],
            "Path-support classification for token '{$token}' changed.",
        );
    }

    public function testPathClassificationHasNoUnexpectedTokens(): void
    {
        $actual = \array_keys($this->extractPathClassification());
        $golden = \array_keys(self::GOLDEN_PATH_SUPPORT);
        \sort($actual);
        \sort($golden);
        self::assertSame($golden, $actual, 'The set of path-classified tokens drifted from the golden set.');
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

    /**
     * @param list<string> $expected
     */
    #[DataProvider('providePhaseOrder')]
    public function testPhaseOrderMatchesGolden(string $phaseRunner, array $expected): void
    {
        $actual = $this->extractPhaseOrder();
        self::assertArrayHasKey($phaseRunner, $actual, "Phase runner '{$phaseRunner}' produced no tool sequence.");
        self::assertSame($expected, $actual[$phaseRunner], "Tool ordering for '{$phaseRunner}' changed.");
    }

    // ---------------------------------------------------------------------
    // Extraction of the single source of truth — the tool registry.
    //
    // These derive the alias map, path classification and phase ordering from
    // the DECLARATIVE data in includes/generic/toolRegistry.inc.bash, applying
    // exactly the same composition the registry's own bash helpers apply
    // (qaResolveSingleTool / qaBuildPathSupportArrays / qaToolsForPhase). The
    // golden constants above are the frozen behavioural contract; the SSoT moved
    // from the hand-written options/phase structures into the registry, which is
    // the point of the refactor. (The runtime resolution path is additionally
    // exercised end-to-end by the `bin/qa -t <alias>` smoke checks and by
    // ToolFragmentLivenessTest.)
    // ---------------------------------------------------------------------

    private const REGISTRY = self::INCLUDES_DIR . '/generic/toolRegistry.inc.bash';

    /** @return array<string, string> */
    private function extractAliasMap(): array
    {
        $aliases = $this->parseAssocArray('QA_TOOL_ALIASES');
        $targets = $this->parseAssocArray('QA_TOOL_TARGET');

        $map = [];
        foreach ($this->parseIndexedArray('QA_TOOL_NAMES') as $name) {
            $target = $targets[$name] ?? $name;
            foreach ($this->splitWords($aliases[$name] ?? '') as $alias) {
                $map[$alias] = $target;
            }
        }

        return $map;
    }

    /** @return array<string, bool> */
    private function extractPathClassification(): array
    {
        $aliases = $this->parseAssocArray('QA_TOOL_ALIASES');
        $paths   = $this->parseAssocArray('QA_TOOL_PATHS');

        $classification = [];
        foreach ($this->parseIndexedArray('QA_TOOL_NAMES') as $name) {
            $tokens = $this->splitWords($aliases[$name] ?? '');
            if (!\in_array($name, $tokens, true)) {
                \array_unshift($tokens, $name);
            }
            foreach ($tokens as $token) {
                $classification[$token] = 'yes' === ($paths[$name] ?? 'no');
            }
        }

        return $classification;
    }

    /** @return array<string, list<string>> */
    private function extractPhaseOrder(): array
    {
        $phaseToRunner = [
            'codingStandards' => 'allCodingStandardsTools',
            'linting'         => 'allLintingTools',
            'staticAnalysis'  => 'allStaticAnalysisTools',
            'testing'         => 'allTestingTools',
        ];

        $phases = $this->parseAssocArray('QA_TOOL_PHASE');

        $order = [];
        foreach ($this->parseIndexedArray('QA_TOOL_NAMES') as $name) {
            $phase = $phases[$name] ?? '';
            if ('' === $phase) {
                continue;
            }
            self::assertArrayHasKey($phase, $phaseToRunner, "Registry declares unknown phase '{$phase}' for '{$name}'.");
            $order[$phaseToRunner[$phase]][] = $name;
        }

        return $order;
    }

    /** @return list<string> */
    private function parseIndexedArray(string $name): array
    {
        $body = $this->arrayBody($name);
        return $this->splitWords($body);
    }

    /** @return array<string, string> */
    private function parseAssocArray(string $name): array
    {
        $body = $this->arrayBody('declare -A ' . $name);
        // Values may be quoted ([k]="v") or bare ([k]=v) in the registry.
        \preg_match_all('/\[([A-Za-z0-9_]+)\]=(?:"([^"]*)"|(\S+))/', $body, $matches, \PREG_SET_ORDER);
        $result = [];
        foreach ($matches as $match) {
            // Quoted value in group 2, bare value in group 3; only one is present.
            $quoted            = $match[2] ?? '';
            $result[$match[1]] = '' !== $quoted ? $quoted : ($match[3] ?? '');
        }

        return $result;
    }

    private function arrayBody(string $declaration): string
    {
        $contents = \file_get_contents(self::REGISTRY);
        self::assertNotFalse($contents, 'Could not read ' . self::REGISTRY);

        // Body runs from the "=(" opener to the first ")" anchored at line start.
        $pattern = '/' . \preg_quote($declaration, '/') . '=\((?<body>.*?)^\)/ms';
        self::assertSame(1, \preg_match($pattern, $contents, $m), "Could not locate array {$declaration} in the registry.");

        return $m['body'];
    }

    /** @return list<string> */
    private function splitWords(string $value): array
    {
        $words = \preg_split('/\s+/', \trim($value));
        self::assertNotFalse($words);

        return \array_values(\array_filter($words, static fn (string $w): bool => '' !== $w));
    }
}
