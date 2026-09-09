<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "a conformance declaration that has drifted from the shape a
 * reader checks it by".
 *
 * composer.json carries extra.defence-before-fix: the version of the method specification
 * this package follows, the version of the toolchain specification the shipped artefact is
 * graded against, and a project object carrying the same keys for the repository's own
 * conformance as a project. Each level carries its own known-gaps list, and every gap names
 * the clause it fails so a reader can find it without re-running the audit. A declaration
 * missing a level, or a gap that names no clause, is a claim nobody can check.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class DefenceBeforeFixDeclarationTest extends TestCase
{
    private const string MANIFEST = __DIR__ . '/../../composer.json';

    private const string METHOD_VERSION = '1.0.1';

    private const string TOOLCHAIN_VERSION = '0.2.0';

    private const string GAP_SHAPE = '/^(method|detector|toolchain) \d+\.\d+: \S/';

    public function testTheArtefactLevelDeclaresTheVersionsAndItsGaps(): void
    {
        $declaration = $this->declaration();

        self::assertSame(self::METHOD_VERSION, $declaration['method'] ?? null);
        self::assertSame(self::TOOLCHAIN_VERSION, $declaration['toolchain'] ?? null);
        $this->assertGapsNameTheirClause($declaration['known-gaps'] ?? null, 'artefact');
    }

    public function testTheProjectLevelDeclaresTheSameKeysSeparately(): void
    {
        $declaration = $this->declaration();

        self::assertIsArray($declaration['project'] ?? null, 'extra.defence-before-fix.project must be an object');
        $project = $declaration['project'];
        self::assertSame(self::METHOD_VERSION, $project['method'] ?? null);
        self::assertSame(self::TOOLCHAIN_VERSION, $project['toolchain'] ?? null);
        $this->assertGapsNameTheirClause($project['known-gaps'] ?? null, 'project');
    }

    private function assertGapsNameTheirClause(mixed $gaps, string $level): void
    {
        self::assertIsArray($gaps, \sprintf('The %s level must carry a known-gaps list, empty when there are none', $level));
        foreach ($gaps as $gap) {
            self::assertIsString($gap);
            self::assertMatchesRegularExpression(
                self::GAP_SHAPE,
                $gap,
                \sprintf('A %s-level gap must open with the document and clause it fails', $level),
            );
        }
    }

    /** @return array<array-key, mixed> */
    private function declaration(): array
    {
        $manifest = \Safe\json_decode(\Safe\file_get_contents(self::MANIFEST), true);
        self::assertIsArray($manifest);
        $extra = $manifest['extra'] ?? null;
        self::assertIsArray($extra, 'composer.json must carry an extra section');
        $declaration = $extra['defence-before-fix'] ?? null;
        self::assertIsArray($declaration, 'composer.json must carry extra.defence-before-fix');

        return $declaration;
    }
}
