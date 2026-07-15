<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline;

use PHPUnit\Framework\TestCase;

/**
 * Gate-liveness guard for the bash tool fragments.
 *
 * HISTORY: includes/generic/psr4Validate.inc.bash was accidentally emptied in
 * 2023 (commit e0240dc) and includes/generic/phpunitAnnotations.inc.bash was
 * fully commented out — in both cases `bin/qa` kept sourcing the fragment and
 * reporting SUCCESS while validating nothing, for years. A sourced fragment
 * that contains no executable code is a silently-dead quality gate.
 *
 * These tests make that failure mode structurally impossible:
 *  - every tool fragment must contain executable (non-comment) code;
 *  - every tool name reachable from the -t alias map must resolve to a
 *    fragment file that actually exists.
 *
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
#[\PHPUnit\Framework\Attributes\Small]
final class ToolFragmentLivenessTest extends TestCase
{
    private const string INCLUDES_DIR = __DIR__ . '/../../../includes';

    #[\PHPUnit\Framework\Attributes\DataProvider('provideToolFragments')]
    public function testFragmentContainsExecutableCode(string $path): void
    {
        $contents = \Safe\file_get_contents($path);

        $codeLines = [];
        foreach (explode("\n", $contents) as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed) {
                continue;
            }

            if (str_starts_with($trimmed, '#')) {
                continue;
            }

            $codeLines[] = $trimmed;
        }

        self::assertNotSame(
            [],
            $codeLines,
            \sprintf(
                'Tool fragment %s contains NO executable code (only comments/blank lines). '
                . 'bin/qa would source it and report success while checking nothing — '
                . 'a silently-dead quality gate. Restore the tool or remove the fragment '
                . 'and every reference to it (options.inc.bash, phase files, docs).',
                $path,
            ),
        );
    }

    /** @return array<string, array{0: string}> */
    public static function provideToolFragments(): array
    {
        $cases = [];
        foreach (['generic', 'symfony'] as $platform) {
            $paths = \Safe\glob(self::INCLUDES_DIR . '/' . $platform . '/*.inc.bash');
            foreach ($paths as $path) {
                self::assertIsString($path);
                $cases[$platform . '/' . basename($path)] = [$path];
            }
        }

        if ([] === $cases) {
            self::fail('No tool fragments found under ' . self::INCLUDES_DIR . ' — the glob or repo layout is broken.');
        }

        return $cases;
    }

    public function testEveryRegisteredToolResolvesToAFragment(): void
    {
        // Since M-011 the alias→tool mapping lives in the declarative tool
        // registry (SSoT), not in an options.inc.bash `case`. Derive the set of
        // canonical tools every -t token resolves to (a tool's TARGET, or its
        // own name) and assert each has a real fragment — the liveness guarantee
        // the emptied psr4Validate / commented phpunitAnnotations fragments once
        // violated.
        $registryPath = self::INCLUDES_DIR . '/generic/toolRegistry.inc.bash';
        $contents     = \Safe\file_get_contents($registryPath);

        $names   = $this->parseIndexedArray($contents, 'QA_TOOL_NAMES');
        $targets = $this->parseAssocArray($contents, 'QA_TOOL_TARGET');
        self::assertNotSame([], $names, 'No tools parsed from the registry — the registry or the parse is broken.');

        $tools = [];
        foreach ($names as $name) {
            $tools[] = $targets[$name] ?? $name;
        }

        $tools = array_unique($tools);

        foreach ($tools as $tool) {
            $candidates = [
                self::INCLUDES_DIR . '/generic/' . $tool . '.inc.bash',
                self::INCLUDES_DIR . '/symfony/' . $tool . '.inc.bash',
            ];
            $found = array_filter($candidates, is_file(...));
            self::assertNotSame(
                [],
                $found,
                \sprintf(
                    'Tool "%s" is registered in the tool registry but no fragment exists at %s. '
                    . 'Either restore the fragment or remove the tool from the registry.',
                    $tool,
                    implode(' or ', $candidates),
                ),
            );
        }
    }

    /** @return list<string> */
    private function parseIndexedArray(string $contents, string $name): array
    {
        $body  = $this->arrayBody($contents, $name);
        $words = \Safe\preg_split('/\s+/', trim($body));

        $result = [];
        foreach ($words as $word) {
            self::assertIsString($word);
            if ('' !== $word) {
                $result[] = $word;
            }
        }

        return $result;
    }

    /** @return array<string, string> */
    private function parseAssocArray(string $contents, string $name): array
    {
        $body = $this->arrayBody($contents, 'declare -A ' . $name);
        \Safe\preg_match_all('/\[(\w+)\]=(?:"([^"]*)"|(\S+))/', $body, $matches, \PREG_SET_ORDER);
        self::assertIsArray($matches);
        $result = [];
        foreach ($matches as $match) {
            self::assertIsArray($match);
            $key = $match[1] ?? null;
            self::assertIsString($key);
            // Quoted value in group 2, bare value in group 3; only one is present.
            $quoted = $match[2] ?? '';
            $bare   = $match[3] ?? '';
            self::assertIsString($quoted);
            self::assertIsString($bare);
            $result[$key] = '' !== $quoted ? $quoted : $bare;
        }

        return $result;
    }

    private function arrayBody(string $contents, string $declaration): string
    {
        // Body runs from "=(" to the first ")" anchored at the start of a line.
        $pattern = '/' . preg_quote($declaration, '/') . '=\((?<body>.*?)^\)/ms';
        self::assertSame(1, \Safe\preg_match($pattern, $contents, $m), \sprintf('Could not locate array %s in the registry.', $declaration));
        self::assertIsArray($m);
        self::assertArrayHasKey('body', $m);

        return $m['body'];
    }
}
