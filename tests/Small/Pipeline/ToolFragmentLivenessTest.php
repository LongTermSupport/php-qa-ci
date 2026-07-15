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
    private const INCLUDES_DIR = __DIR__ . '/../../../includes';

    /** @return array<string, array{0: string}> */
    public static function provideToolFragments(): array
    {
        $cases = [];
        foreach (['generic', 'symfony'] as $platform) {
            $paths = \glob(self::INCLUDES_DIR . '/' . $platform . '/*.inc.bash');
            self::assertNotFalse($paths);
            foreach ($paths as $path) {
                $cases[$platform . '/' . \basename($path)] = [$path];
            }
        }
        if ([] === $cases) {
            self::fail('No tool fragments found under ' . self::INCLUDES_DIR . ' — the glob or repo layout is broken.');
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideToolFragments')]
    public function testFragmentContainsExecutableCode(string $path): void
    {
        $contents = \file_get_contents($path);
        self::assertNotFalse($contents, "Could not read fragment {$path}");

        $codeLines = [];
        foreach (\explode("\n", $contents) as $line) {
            $trimmed = \trim($line);
            if ('' === $trimmed) {
                continue;
            }
            if (\str_starts_with($trimmed, '#')) {
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

    public function testEveryRegisteredToolResolvesToAFragment(): void
    {
        $optionsPath = self::INCLUDES_DIR . '/options.inc.bash';
        $contents    = \file_get_contents($optionsPath);
        self::assertNotFalse($contents, "Could not read {$optionsPath}");

        \preg_match_all('/singleToolToRun="(?<tool>[A-Za-z0-9]+)"/', $contents, $matches);
        $tools = \array_unique($matches['tool']);
        self::assertNotSame([], $tools, 'No -t tool mappings parsed from options.inc.bash — the parse regex or the alias map is broken.');

        foreach ($tools as $tool) {
            $candidates = [
                self::INCLUDES_DIR . '/generic/' . $tool . '.inc.bash',
                self::INCLUDES_DIR . '/symfony/' . $tool . '.inc.bash',
            ];
            $found = \array_filter($candidates, static fn (string $p): bool => \is_file($p));
            self::assertNotSame(
                [],
                $found,
                \sprintf(
                    'Tool "%s" is offered via -t in options.inc.bash but no fragment exists at %s. '
                    . 'Either restore the fragment or remove the tool from the alias map/usage text.',
                    $tool,
                    \implode(' or ', $candidates),
                ),
            );
        }
    }
}
