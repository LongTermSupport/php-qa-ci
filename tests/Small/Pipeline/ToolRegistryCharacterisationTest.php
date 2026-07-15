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
        self::assertSame(self::GOLDEN_ALIAS_MAP, $this->extractAliasMap());
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
    // Extraction of the CURRENT single source of truth.
    //
    // Pre-refactor: parse the hand-written structures in options.inc.bash and
    // the four all*Tools.inc.bash phase files. Post-refactor these methods are
    // updated to query includes/generic/toolRegistry.inc.bash instead — the
    // golden constants above stay identical, proving behaviour is preserved.
    // ---------------------------------------------------------------------

    /** @return array<string, string> */
    private function extractAliasMap(): array
    {
        $contents = $this->readFile(self::INCLUDES_DIR . '/options.inc.bash');

        $map = [];
        // Match case arms of the form:  tokA | tokB ) singleToolToRun="canonical"
        \preg_match_all(
            '/^\s*([A-Za-z0-9|\s]+?)\s*\)\s*singleToolToRun="([A-Za-z0-9]+)"/m',
            $contents,
            $matches,
            \PREG_SET_ORDER,
        );
        foreach ($matches as $match) {
            $canonical = $match[2];
            foreach (\explode('|', $match[1]) as $token) {
                $token = \trim($token);
                if ('' === $token) {
                    continue;
                }
                $map[$token] = $canonical;
            }
        }

        return $map;
    }

    /** @return array<string, bool> */
    private function extractPathClassification(): array
    {
        $contents = $this->readFile(self::INCLUDES_DIR . '/options.inc.bash');

        $classification = [];
        foreach (
            [
                'PATH_SUPPORTING_TOOLS'     => true,
                'NON_PATH_SUPPORTING_TOOLS' => false,
            ] as $arrayName => $supported
        ) {
            // Anchor the closing paren at the start of a line: inline comments in
            // the array body contain ")" (e.g. "(ClassSet::fromDir)"), so a plain
            // non-greedy ".*?\)" would stop early and drop later tokens.
            if (1 !== \preg_match('/' . $arrayName . '=\((?<body>.*?)^\)/ms', $contents, $m)) {
                self::fail("Could not locate {$arrayName} array in options.inc.bash");
            }
            \preg_match_all('/"([^"]+)"/', $m['body'], $tokenMatches);
            foreach ($tokenMatches[1] as $token) {
                $classification[$token] = $supported;
            }
        }

        return $classification;
    }

    /** @return array<string, list<string>> */
    private function extractPhaseOrder(): array
    {
        $order = [];
        foreach (\array_keys(self::GOLDEN_PHASE_ORDER) as $phaseRunner) {
            $contents = $this->readFile(self::INCLUDES_DIR . '/generic/' . $phaseRunner . '.inc.bash');
            \preg_match_all('/^\s*runToolGuarded\s+([A-Za-z0-9]+)/m', $contents, $m);
            $order[$phaseRunner] = $m[1];
        }

        return $order;
    }

    private function readFile(string $path): string
    {
        $contents = \file_get_contents($path);
        self::assertNotFalse($contents, "Could not read {$path}");

        return $contents;
    }
}
