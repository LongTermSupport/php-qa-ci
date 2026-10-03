<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\AgentContext;

use LTS\PHPQA\AgentContext\ActiveDefencesSummary;
use LTS\PHPQA\AgentContext\AgentContextRegion;
use LTS\PHPQA\DefectRecord\Dto\DefectRecordDto;
use LTS\PHPQA\DefectRecord\Dto\DeferredDefectDto;
use LTS\PHPQA\DefectRecord\Dto\NoPatternConclusionDto;
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
#[UsesClass(DefectRecordDto::class)]
#[UsesClass(DeferredDefectDto::class)]
#[UsesClass(NoPatternConclusionDto::class)]
#[Small]
final class ActiveDefencesSummaryTest extends TestCase
{
    private const string ROOT = '/project';

    private const string PHASE = 'linting';

    private const string HEADER = AgentContextRegion::START . "\n"
        . "## php-qa-ci — Active defences\n\n"
        . "Generated from this project's active configuration by `rules --write-agent-summary`; do not\n"
        . "edit. Each line is a standing rule the pipeline enforces; `rule-doc <identifier>` (Composer bin\n"
        . "dir) prints its page offline.\n\n";

    private const string RECORD_HEADING = "\n\n### Deferred defects\n\n";

    private const string CHANGELOG_LINE = '- `phpqaci.changelog` — CHANGELOG.md records every consumer-facing change';

    #[Test]
    public function everyDefenceIsOneLineWithItsIdentifierAndPage(): void
    {
        $listing = new ActiveDefencesListingDto(
            self::ROOT . '/qaConfig/phpstan.neon',
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
            AgentContextRegion::START . "\n"
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
            . '- `phpqaci.undocumented` — A lane with no page'
            . self::RECORD_HEADING
            . "None recorded. A Defect found and not fixed now, or the conclusion that no pattern exists, is\n"
            . "recorded in `qaConfig/defect-record.neon`, not only in conversation (`rule-doc\n"
            . "phpqaci.phpstanIgnoreJustification` prints the format).\n"
            . AgentContextRegion::END,
            new ActiveDefencesSummary()->render($listing, self::ROOT),
        );
    }

    #[Test]
    public function aProjectRootGivenWithATrailingSlashStillShortensThePage(): void
    {
        $listing = new ActiveDefencesListingDto(
            self::ROOT . '/qaConfig/phpstan.neon',
            [new ActiveRuleEntryDto('LTS\Rules\Dangerous', 'phpqaci.dangerousFunctions', 'No exec/eval/unserialize and similar', self::ROOT . '/docs/rule.md')],
            [],
            [],
        );

        self::assertStringContainsString(
            "\n- `phpqaci.dangerousFunctions` — No exec/eval/unserialize and similar (`docs/rule.md`)\n",
            new ActiveDefencesSummary()->render($listing, self::ROOT . '/'),
        );
    }

    /**
     * Method specification section 2 (1.1.0): the record is what puts a deferred Defect in
     * front of the Owner, so the agent summary carries each entry rather than only a path.
     */
    #[Test]
    public function theDeferredDefectsAndNoPatternConclusionsAreCarriedOneLineEach(): void
    {
        $record = new DefectRecordDto(
            self::ROOT . '/qaConfig/defect-record.neon',
            [
                new DeferredDefectDto('The cache key omits the locale.', 'A cache key built from a subset of its inputs.', 'src/Cache/KeyBuilder.php', 'Owner'),
                new DeferredDefectDto('A timeout is swallowed.', null, 'src/Http/Retry.php', 'Owner, pending the client rewrite'),
            ],
            [
                new NoPatternConclusionDto('The total was rounded twice.', 'src/Invoice/Total.php', 'No pattern exists.', ['a text search', 'reading every caller']),
            ],
            [],
        );

        self::assertSame(
            self::HEADER
            . self::CHANGELOG_LINE
            . self::RECORD_HEADING
            . "Recorded in `qaConfig/defect-record.neon`; whether a deferred one stays unfixed is the Owner's\n"
            . "decision, and the attempt at a Defence is owed when its fix is taken up.\n\n"
            . "- Deferred — The cache key omits the locale. (class: A cache key built from a subset of its inputs.; found: src/Cache/KeyBuilder.php; deferred by: Owner)\n"
            . "- Deferred — A timeout is swallowed. (class: none apparent yet; found: src/Http/Retry.php; deferred by: Owner, pending the client rewrite)\n"
            . "- No pattern — The total was rounded twice. (found: src/Invoice/Total.php; No pattern exists.)\n"
            . AgentContextRegion::END,
            new ActiveDefencesSummary()->render($this->listingWith($record), self::ROOT),
        );
    }

    /** A long record would crowd the defences out of the context, so past ten entries it is a count. */
    #[Test]
    public function aLongRecordIsACountAndThePath(): void
    {
        $deferred = [];
        for ($entry = 1; $entry <= 10; ++$entry) {
            $deferred[] = new DeferredDefectDto('Defect ' . $entry, null, 'src/A.php', 'Owner');
        }

        $record = new DefectRecordDto(
            self::ROOT . '/qaConfig/defect-record.neon',
            $deferred,
            [new NoPatternConclusionDto('One more.', 'src/B.php', 'None.', ['a', 'b'])],
            [],
        );

        self::assertSame(
            self::HEADER
            . self::CHANGELOG_LINE
            . self::RECORD_HEADING
            . "10 deferred defects and 1 no-pattern conclusion are recorded in `qaConfig/defect-record.neon`;\n"
            . "`rules` lists them. Whether a deferred one stays unfixed is the Owner's decision, and the attempt\n"
            . "at a Defence is owed when its fix is taken up.\n"
            . AgentContextRegion::END,
            new ActiveDefencesSummary()->render($this->listingWith($record), self::ROOT),
        );
    }

    private function listingWith(DefectRecordDto $record): ActiveDefencesListingDto
    {
        return new ActiveDefencesListingDto(
            self::ROOT . '/qaConfig/phpstan.neon',
            [],
            [new PipelineLaneDto('changelog', 'phpqaci.changelog', 'CHANGELOG.md records every consumer-facing change', self::PHASE, null)],
            [],
            $record,
        );
    }
}
