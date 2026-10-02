<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Changelog;

use LTS\PHPQA\Changelog\ComposerRequirementChanges;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ComposerRequirementChanges::class)]
#[Small]
final class ComposerRequirementChangesTest extends TestCase
{
    private const string PHP = '^8.5';

    #[Test]
    public function anAddedRequirementAndAChangedConstraintAreReported(): void
    {
        $changes = new ComposerRequirementChanges()->between(
            $this->composer(['php' => self::PHP, 'ext-json' => '*', 'symfony/console' => '^8.0']),
            $this->composer(['php' => self::PHP, 'ext-json' => '*', 'symfony/console' => '^8.1', 'ext-pcntl' => '*']),
        );

        self::assertSame(['symfony/console ^8.0 → ^8.1', 'ext-pcntl * added'], $changes);
    }

    #[Test]
    public function aRemovedRequirementOrADevRequirementIsNotBreaking(): void
    {
        $base = \Safe\json_encode(['require' => ['php' => self::PHP, 'ext-json' => '*'], 'require-dev' => ['a/b' => '^1']]);
        $head = \Safe\json_encode(['require' => ['php' => self::PHP], 'require-dev' => ['a/b' => '^2', 'c/d' => '^1']]);

        self::assertSame([], new ComposerRequirementChanges()->between($base, $head));
    }

    #[Test]
    public function aMissingComposerJsonOnEitherSideComparesNothing(): void
    {
        $composer = $this->composer(['php' => self::PHP]);

        self::assertSame([], new ComposerRequirementChanges()->between(null, $composer));
        self::assertSame([], new ComposerRequirementChanges()->between($composer, null));
    }

    #[Test]
    public function aComposerJsonWithoutARequireSectionRequiresNothing(): void
    {
        self::assertSame(['php ^8.5 added'], new ComposerRequirementChanges()->between('{"name": "x/y"}', $this->composer(['php' => self::PHP])));
        self::assertSame([], new ComposerRequirementChanges()->between($this->composer(['php' => self::PHP]), '{"require": "nonsense"}'));
    }

    #[Test]
    public function anUnparseableSideIsReportedRatherThanIgnored(): void
    {
        $changes = new ComposerRequirementChanges()->between('{', $this->composer(['php' => self::PHP]));

        self::assertSame(['composer.json does not parse on one side of the range, so its requirements cannot be compared (Syntax error)'], $changes);
    }

    /** @param array<string, string> $require */
    private function composer(array $require): string
    {
        return \Safe\json_encode(['name' => 'x/y', 'require' => $require]);
    }
}
