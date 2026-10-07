<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\AgentContext;

use LTS\PHPQA\AgentContext\ActiveDefencesSummary;
use LTS\PHPQA\AgentContext\AgentContextRegion;
use LTS\PHPQA\PHPStan\Dto\ActiveDefencesListingDto;
use LTS\PHPQA\PHPStan\Dto\ActiveRuleEntryDto;
use LTS\PHPQA\PHPStan\Dto\PipelineLaneDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Toolchain specification 7.1: one terse line per active defence, phrased as
 * a standing instruction, carrying its identifier and the route to its
 * documentation, generated from the listing bin/rules builds.
 *
 * @internal
 */
#[CoversClass(ActiveDefencesSummary::class)]
#[UsesClass(ActiveDefencesListingDto::class)]
#[UsesClass(ActiveRuleEntryDto::class)]
#[UsesClass(PipelineLaneDto::class)]
#[Small]
final class ActiveDefencesSummaryTest extends TestCase
{
    private const string ROOT = '/project';

    private const string PHASE = 'linting';

    private const string PHPSTAN_NEON = self::ROOT . '/qaConfig/phpstan.neon';

    #[Test]
    public function everyDefenceIsOneLineWithItsIdentifierAndPage(): void
    {
        $listing = new ActiveDefencesListingDto(
            self::PHPSTAN_NEON,
            [
                new ActiveRuleEntryDto('LTS\Rules\Dangerous', 'phpqaci.dangerousFunctions', 'No exec/eval/unserialize and similar', self::ROOT . '/vendor/lts/php-qa-ci/docs/phpstan-rules/forbid-dangerous-functions.md'),
                new ActiveRuleEntryDto('App\PHPStan\OwnRule', null, null, null),
                new ActiveRuleEntryDto('Other\Rule', 'other.rule', 'Outside the project', '/elsewhere/rule.md'),
                new ActiveRuleEntryDto('Strict\RuleA', null, null, null, 'phpstan/phpstan-strict-rules'),
                new ActiveRuleEntryDto('Strict\RuleB', null, null, null, 'phpstan/phpstan-strict-rules'),
                new ActiveRuleEntryDto('Coverage\Rule', 'typeCoverage.paramTypeCoverage', null, null, 'tomasvotruba/type-coverage'),
                new ActiveRuleEntryDto('App\PHPStan\SecondRule', null, null, null),
            ],
            [
                new PipelineLaneDto('changelog', 'phpqaci.changelog', 'CHANGELOG.md records every consumer-facing change', self::PHASE, 'useChangelogCheck', self::ROOT . '/vendor/lts/php-qa-ci/docs/tools/changelog.md'),
                new PipelineLaneDto('allLintingTools', null, 'run every linting tool', self::PHASE, null),
                new PipelineLaneDto('undocumented', 'phpqaci.undocumented', 'A lane with no page', self::PHASE, null),
            ],
            [],
        );

        self::assertSame(
            AgentContextRegion::START . "\n\n"
            . "## php-qa-ci — Active defences\n\n"
            . "Generated from this project's active configuration by `rules --write-agent-summary`; do not\n"
            . "edit. Each line is a standing rule the pipeline enforces; `rule-doc <identifier>` (Composer bin\n"
            . "dir) prints its page offline.\n\n"
            . "- `phpqaci.dangerousFunctions` — No exec/eval/unserialize and similar (`vendor/lts/php-qa-ci/docs/phpstan-rules/forbid-dangerous-functions.md`)\n"
            . "- `other.rule` — Outside the project (`/elsewhere/rule.md`)\n"
            . "- `typeCoverage.paramTypeCoverage` — a rule from `tomasvotruba/type-coverage`\n"
            . "- 2 rules in this project's own configuration declare no identifier: `App\\PHPStan\\OwnRule`, `App\\PHPStan\\SecondRule`\n"
            . "- `phpstan/phpstan-strict-rules` — 2 rules from this PHPStan extension; their findings carry PHPStan identifiers, which `rule-doc` routes\n"
            . "- `phpqaci.changelog` — CHANGELOG.md records every consumer-facing change (`vendor/lts/php-qa-ci/docs/tools/changelog.md`; opt-in: `useChangelogCheck`)\n"
            . "- `phpqaci.undocumented` — A lane with no page\n"
            . "\n" . AgentContextRegion::END,
            new ActiveDefencesSummary()->render($listing, self::ROOT),
        );
    }

    /**
     * The region is written in the markdown formatter's canonical form, so a
     * formatter run over the document (the hooks daemon formats CLAUDE.md
     * after every edit) leaves it as generated: a blank line inside each
     * marker, and a summary's backslash escaped where it is text and left
     * alone where it is code.
     */
    #[Test]
    public function theRegionIsInTheMarkdownFormattersCanonicalForm(): void
    {
        $listing = new ActiveDefencesListingDto(
            self::PHPSTAN_NEON,
            [],
            [new PipelineLaneDto('spu', 'phpqaci.spu', 'assert #[\SensitiveParameter] is used, as `#[\SensitiveParameter]` in code', self::PHASE, null)],
            [],
        );

        $region = new ActiveDefencesSummary()->render($listing, self::ROOT);

        self::assertStringStartsWith(AgentContextRegion::START . "\n\n## ", $region);
        self::assertStringEndsWith("\n\n" . AgentContextRegion::END, $region);
        self::assertStringContainsString('- `phpqaci.spu` — assert #[\\\SensitiveParameter] is used, as `#[\SensitiveParameter]` in code', $region);
    }

    #[Test]
    public function aProjectRootGivenWithATrailingSlashStillShortensThePage(): void
    {
        $listing = new ActiveDefencesListingDto(
            self::PHPSTAN_NEON,
            [new ActiveRuleEntryDto('LTS\Rules\Dangerous', 'phpqaci.dangerousFunctions', 'No exec/eval/unserialize and similar', self::ROOT . '/docs/rule.md')],
            [],
            [],
        );

        self::assertStringContainsString(
            "\n- `phpqaci.dangerousFunctions` — No exec/eval/unserialize and similar (`docs/rule.md`)\n",
            new ActiveDefencesSummary()->render($listing, self::ROOT . '/'),
        );
    }
}
