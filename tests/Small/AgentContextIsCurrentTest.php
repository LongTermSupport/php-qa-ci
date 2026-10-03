<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use LTS\PHPQA\AgentContext\ActiveDefencesSummary;
use LTS\PHPQA\AgentContext\AgentContextRegion;
use LTS\PHPQA\PHPStan\ActiveRulesLister;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Toolchain specification 7.1 and 7.2 on this repository and in the block it
 * ships. The active-defences region of CLAUDE.md is generated, so it is only
 * worth loading while it matches what `bin/rules` lists now: a defence added
 * or removed without regenerating it fails here. The consumer block template
 * must carry exactly one region, or the deploy has nowhere to write it.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class AgentContextIsCurrentTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    #[Test]
    public function theActiveDefencesInClaudeMdMatchTheConfiguration(): void
    {
        $root    = \Safe\realpath(self::ROOT);
        $current = new ActiveDefencesSummary()->render(new ActiveRulesLister($root)->list($root), $root);

        self::assertSame(
            $current,
            new AgentContextRegion()->current(\Safe\file_get_contents($root . '/CLAUDE.md')),
            'CLAUDE.md lists defences that are not the active ones; run bin/rules . --write-agent-summary=CLAUDE.md',
        );
    }

    #[Test]
    public function theShippedBlockTemplateHasTheRegionTheDeployFills(): void
    {
        self::assertNotNull(new AgentContextRegion()->current(\Safe\file_get_contents(self::ROOT . '/templates/root-CLAUDE-phpqaci-block.md.template')));
    }
}
