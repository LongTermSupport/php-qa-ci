<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Tool;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ShippedTools;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use LTS\PHPQA\Pipeline\Tool\ToolRegistry;
use LTS\PHPQA\Tests\Support\StubTool;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Defence for toolchain specification 4.1: every defence names itself.
 *
 * A registry entry that is a lane, rather than a phase runner or another
 * tool's mode, can fail a run, and a failure has to print a stable
 * identifier a reader can resolve. Five lanes once shipped without one; the
 * instances were fixed and nothing stopped the next. A lane is identified
 * through its shipped ToolInterface, so a lane with no shipped
 * implementation, or one whose identifier is not under the `phpqaci.` prefix,
 * fails here.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class EveryLaneNamesItselfTest extends TestCase
{
    private const string LANE = 'a lane';

    private const string PHASE = 'linting';

    private const string NAMED = 'named';

    #[Test]
    public function everyShippedLaneHasAStableIdentifier(): void
    {
        self::assertSame([], $this->unnamed(ShippedTools::all(), ...ToolRegistry::shipped()->all()));
    }

    #[Test]
    public function aLaneWithNoImplementationOrAForeignIdentifierIsReported(): void
    {
        $shipped = [
            self::NAMED => new StubTool(self::NAMED),
            'foreign'   => new class implements ToolInterface {
                public function name(): string
                {
                    return 'foreign';
                }

                public function identifier(): string
                {
                    return 'other.foreign';
                }

                public function run(ToolContext $context): ToolResultDto
                {
                    return ToolResultDto::passed();
                }
            },
        ];

        self::assertSame(
            ['unimplemented: no shipped implementation, so no identifier', 'foreign: identifier "other.foreign" is not under phpqaci.'],
            $this->unnamed(
                $shipped,
                new ToolDefinitionDto(self::NAMED, [self::NAMED], self::LANE, self::PHASE, false),
                new ToolDefinitionDto('unimplemented', ['unimplemented'], self::LANE, self::PHASE, false),
                new ToolDefinitionDto('foreign', ['foreign'], self::LANE, self::PHASE, false),
                new ToolDefinitionDto('allLintingTools', ['allLint'], 'a phase', self::PHASE, false, isPhaseRunner: true),
                new ToolDefinitionDto('mode', ['mode'], 'another tool in a mode', null, false, target: self::NAMED),
            ),
        );
    }

    /**
     * @param array<string, ToolInterface> $shipped
     *
     * @return list<string>
     */
    private function unnamed(array $shipped, ToolDefinitionDto ...$definitions): array
    {
        $unnamed = [];
        foreach ($definitions as $definition) {
            if ($definition->isPhaseRunner || null !== $definition->target) {
                continue;
            }

            $tool = $shipped[$definition->name] ?? null;
            if (null === $tool) {
                $unnamed[] = $definition->name . ': no shipped implementation, so no identifier';

                continue;
            }

            if (!str_starts_with($tool->identifier(), RuleIdentifierInterface::PREFIX . '.')) {
                $unnamed[] = \sprintf('%s: identifier "%s" is not under %s.', $definition->name, $tool->identifier(), RuleIdentifierInterface::PREFIX);
            }
        }

        return $unnamed;
    }
}
