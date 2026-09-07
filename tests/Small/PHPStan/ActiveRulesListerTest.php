<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan;

use LTS\PHPQA\PHPStan\ActiveRulesLister;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Defence for the class "a project's active defences can only be learned by
 * violating them one at a time". ActiveRulesLister must derive its listing
 * from the resolved PHPStan configuration — following includes, collecting
 * both `rules:` classes and `phpstan.rules.rule`-tagged services, resolving
 * identifiers via RuleDocResolver where a rule declares one — and must also
 * enumerate the project record (ignoreErrors) with any preceding comment
 * recovered as justification.
 *
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ActiveRulesLister::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\Dto\ActiveDefencesListingDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\Dto\ActiveRuleEntryDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\Dto\PipelineLaneDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\Dto\ProjectRecordEntryDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\Dto\RuleDocEntryDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\RuleDocResolver::class)]
#[\PHPUnit\Framework\Attributes\Small]
final class ActiveRulesListerTest extends TestCase
{
    private const string QA_CI_ROOT = __DIR__ . '/../../..';

    private const string FIXTURE_PROJECT = __DIR__ . '/../../assets/ActiveRulesLister/projectFixture';

    public function testTextOutputListsRulesLanesAndProjectRecordExactly(): void
    {
        $lister  = new ActiveRulesLister(self::QA_CI_ROOT);
        $listing = $lister->list(self::FIXTURE_PROJECT);

        self::assertSame(self::FIXTURE_PROJECT . '/qaConfig/phpstan.neon', $listing->configPath);

        self::assertCount(2, $listing->rules);

        $withIdentifier = $listing->rules[0];
        self::assertSame('phpqaci.dangerousFunctions', $withIdentifier->identifier);
        self::assertSame(\LTS\PHPQA\PHPStan\Rules\ForbidDangerousFunctionsRule::class, $withIdentifier->ruleClass);
        self::assertSame('No exec/eval/unserialize and similar', $withIdentifier->summary);
        self::assertSame(
            self::QA_CI_ROOT . '/docs/phpstan-rules/forbid-dangerous-functions.md',
            $withIdentifier->docPath,
        );

        $withoutIdentifier = $listing->rules[1];
        self::assertNull($withoutIdentifier->identifier);
        self::assertSame(
            \LTS\PHPQA\Tests\Assets\ActiveRulesLister\FixtureProjectRule::class,
            $withoutIdentifier->ruleClass,
        );
        self::assertNull($withoutIdentifier->summary);
        self::assertNull($withoutIdentifier->docPath);

        self::assertNotEmpty($listing->pipelineLanes);
        $laneNames = array_map(static fn (\LTS\PHPQA\PHPStan\Dto\PipelineLaneDto $lane): string => $lane->name, $listing->pipelineLanes);
        self::assertContains('branchNamePolicy', $laneNames);
        self::assertContains('sensitiveParameterUsage', $laneNames);
        self::assertContains('packageType', $laneNames);
        self::assertContains('phpArkitect', $laneNames);

        self::assertCount(1, $listing->projectRecord);
        $record = $listing->projectRecord[0];
        self::assertSame('class.notFound', $record->identifier);
        self::assertSame('some/legacy/path.php', $record->path);
        self::assertSame(2, $record->count);
        self::assertSame(
            'Justification: legacy generated code, tracked for removal in TICKET-123.',
            $record->justification,
        );

        $text = $lister->renderText($listing);
        self::assertStringContainsString('phpqaci.dangerousFunctions', $text);
        self::assertStringContainsString('No exec/eval/unserialize and similar', $text);
        self::assertStringContainsString('FixtureProjectRule', $text);
        self::assertStringContainsString('not declared', $text);
        self::assertStringContainsString('Pipeline lanes', $text);
        self::assertStringContainsString('branchNamePolicy', $text);
        self::assertStringContainsString('Project record', $text);
        self::assertStringContainsString('class.notFound', $text);
        self::assertStringContainsString(
            'Justification: legacy generated code, tracked for removal in TICKET-123.',
            $text,
        );
    }

    public function testJsonOutputIsValidAndStructured(): void
    {
        $lister  = new ActiveRulesLister(self::QA_CI_ROOT);
        $listing = $lister->list(self::FIXTURE_PROJECT);

        $json    = $lister->renderJson($listing);
        $decoded = \Safe\json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame(self::FIXTURE_PROJECT . '/qaConfig/phpstan.neon', $decoded['configPath']);

        $rules = $decoded['rules'];
        self::assertIsArray($rules);
        self::assertCount(2, $rules);
        $firstRule = $rules[0];
        self::assertIsArray($firstRule);
        self::assertSame('phpqaci.dangerousFunctions', $firstRule['identifier']);
        $secondRule = $rules[1];
        self::assertIsArray($secondRule);
        self::assertNull($secondRule['identifier']);

        $projectRecord = $decoded['projectRecord'];
        self::assertIsArray($projectRecord);
        self::assertCount(1, $projectRecord);
        $firstRecord = $projectRecord[0];
        self::assertIsArray($firstRecord);
        self::assertSame('class.notFound', $firstRecord['identifier']);

        self::assertNotEmpty($decoded['pipelineLanes']);
    }

    public function testProjectWithNoQaConfigFallsBackToTheShippedDefault(): void
    {
        $lister  = new ActiveRulesLister(self::QA_CI_ROOT);
        $listing = $lister->list(sys_get_temp_dir());

        self::assertSame(
            self::QA_CI_ROOT . '/configDefaults/generic/phpstan.neon',
            $listing->configPath,
        );
        self::assertSame([], $listing->rules);
        self::assertSame([], $listing->projectRecord);
    }

    public function testUnparseableNeonIsAnError(): void
    {
        $lister = new ActiveRulesLister(self::QA_CI_ROOT);

        $this->expectException(RuntimeException::class);
        $lister->list(__DIR__ . '/../../assets/ActiveRulesLister/unparseableProject');
    }

    /**
     * Defence against toolchain-spec clause 7.2 drift (a lane added to
     * includes/generic/toolRegistry.inc.bash's staticAnalysis phase — the
     * same way branchNamePolicy/phpArkitect/sensitiveParameterUsage are
     * registered today — must appear in ActiveRulesLister's output without
     * any source change here). This test parses the registry file
     * INDEPENDENTLY of ActiveRulesLister's own parser (a small, deliberately
     * separate regex, not a shared helper) so it cannot pass merely because
     * both sides share a bug — it re-derives the expected staticAnalysis-phase
     * lane names from the registry text and asserts every one of them (minus
     * phpstan, which is covered by the rule listing, not the lane listing)
     * is present in the listing ActiveRulesLister produces.
     */
    public function testEveryStaticAnalysisPhaseRegistryToolAppearsAsAPipelineLane(): void
    {
        $registryPath = self::QA_CI_ROOT . '/includes/generic/toolRegistry.inc.bash';
        self::assertFileExists($registryPath);
        $registryContents = \Safe\file_get_contents($registryPath);

        $phaseMatchResult = \Safe\preg_match('/declare -A QA_TOOL_PHASE=\((.*?)\n\)/s', $registryContents, $phaseMatches);
        self::assertSame(
            1,
            $phaseMatchResult,
            'Could not locate the QA_TOOL_PHASE associative array in the tool registry — has its shape changed?',
        );
        self::assertIsArray($phaseMatches);
        self::assertArrayHasKey(1, $phaseMatches);

        $expectedLaneNames = [];
        foreach (explode("\n", $phaseMatches[1]) as $phaseLine) {
            if (1 !== \Safe\preg_match('/^\s*\[(\w+)]=staticAnalysis\s*$/', $phaseLine, $entryMatches)) {
                continue;
            }

            if (!isset($entryMatches[1])) {
                continue;
            }

            if ('phpstan' === $entryMatches[1]) {
                continue;
            }

            $expectedLaneNames[] = $entryMatches[1];
        }

        self::assertNotEmpty($expectedLaneNames, 'Expected at least one staticAnalysis-phase lane in the tool registry.');

        $lister    = new ActiveRulesLister(self::QA_CI_ROOT);
        $listing   = $lister->list(self::FIXTURE_PROJECT);
        $laneNames = array_map(
            static fn (\LTS\PHPQA\PHPStan\Dto\PipelineLaneDto $lane): string => $lane->name,
            $listing->pipelineLanes,
        );

        foreach ($expectedLaneNames as $expectedLaneName) {
            self::assertContains(
                $expectedLaneName,
                $laneNames,
                \sprintf(
                    'Tool "%s" is registered in the staticAnalysis phase of toolRegistry.inc.bash but ActiveRulesLister did not list it as a pipeline lane.',
                    $expectedLaneName,
                ),
            );
        }
    }
}
