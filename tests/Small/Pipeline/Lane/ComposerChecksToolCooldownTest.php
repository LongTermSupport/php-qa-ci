<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\ComposerChecks\CooldownPolicyChecker;
use LTS\PHPQA\Pipeline\Lane\ComposerChecks\CooldownReport;
use LTS\PHPQA\Pipeline\Lane\ComposerChecks\RedundantSuggestDetector;
use LTS\PHPQA\Pipeline\Lane\ComposerChecksTool;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Tests\Support\ContextFactory;
use LTS\PHPQA\Tests\Support\FixedProjectFiles;
use LTS\PHPQA\Tests\Support\MutableClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ComposerChecksTool::class)]
#[UsesClass(CooldownPolicyChecker::class)]
#[UsesClass(CooldownReport::class)]
#[UsesClass(RedundantSuggestDetector::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ReadOnlyGuidance::class)]
#[UsesClass(\LTS\PHPQA\Helper::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[UsesClass(\LTS\PHPQA\ComposerPlugin\ChildExit::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lock\SystemClock::class)]
#[Small]
final class ComposerChecksToolCooldownTest extends TestCase
{
    private const string COMPOSER = '/opt/fixture/bin/composer';

    private const string MODERN = 'Composer version 2.11.1 2026-10-30 14:52:10';

    private const string REASON = 'our own packages are released by us, not by a stranger';

    private const string OVERRIDE = 'SECURITY CVE-2026-1234: fixed in 1.2.3; PR https://example.com/acme/app/pull/7; remove after 2026-11-20';

    private const string UNREADABLE = 'composer.json could not be read';

    private const string REPORT_ONLY = 'Report-only';

    private const string COMPOSER_JSON = '/composer.json';

    private const string NO_COOLDOWN = 'no cooldown configured';

    private const string TOOL_MANIFEST = 'tools/a/composer.json';

    private const string NESTED_HEADING = 'Nested composer.json files';

    private const string ENFORCED_ONE = '1 Composer cooldown finding(s)';

    private const int NOVEMBER_SECOND = 1_793_620_800;

    private const int NOVEMBER_TWENTY_FIRST = 1_795_262_400;

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function aCorrectCooldownPrintsThatItIsInPlaceAndPasses(): void
    {
        $this->writeComposerJson($this->goodPolicy());
        $this->queue(self::MODERN);

        $result  = $this->runLane();
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString("\n\nChecking Composer Cooldown Policy\n---------------------\n\n", $printed);
        self::assertStringContainsString('Composer cooldown policy is in place.', $printed);
        self::assertStringNotContainsString(self::REPORT_ONLY, $printed);
    }

    #[Test]
    public function theInstalledComposerIsAskedItsVersionWithoutStreamingTheAnswer(): void
    {
        $this->writeComposerJson($this->goodPolicy());
        $this->queue(self::MODERN);

        $this->runLane();

        $probe = $this->factory->processes->specs[1];
        self::assertSame(['--version', '--no-ansi'], \array_slice($probe->command, 8));
        self::assertFalse($probe->streamOutput);
        self::assertSame($this->factory->project->path, $probe->cwd);
    }

    #[Test]
    public function aProjectWithNoPolicyIsReportedButPassesWhenReportOnly(): void
    {
        $this->queue(self::MODERN);

        $result  = $this->runLane();
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('Composer cooldown policy has 1 finding(s):', $printed);
        self::assertStringContainsString(self::NO_COOLDOWN, $printed);
        self::assertStringContainsString('Report-only: these findings do not fail the lane.', $printed);
        self::assertStringContainsString('withComposerCooldownEnforced(true)', $printed);
        self::assertStringContainsString('enforceComposerCooldown=1', $printed);
        self::assertStringNotContainsString(ComposerChecksTool::IDENTIFIER, $printed);
        self::assertCount(5, $this->factory->processes->specs, 'every later step still runs');
    }

    #[Test]
    public function enforcementTurnsTheSameFindingsIntoAFailureAfterTheLaterStepsRan(): void
    {
        $this->queue(self::MODERN);

        $result  = $this->runLane($this->factory->builder()->withComposerCooldownEnforced(true)->build());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('1 Composer cooldown finding(s)', $result->summary);
        self::assertStringContainsString(self::NO_COOLDOWN, $printed);
        self::assertStringNotContainsString(self::REPORT_ONLY, $printed);
        self::assertStringContainsString(ComposerChecksTool::IDENTIFIER, $printed);
        self::assertCount(5, $this->factory->processes->specs, 'the later steps ran and could report too');
    }

    #[Test]
    public function theEnvironmentEnforcesToo(): void
    {
        $this->queue(self::MODERN);

        $result = $this->runLane($this->factory->builder(['enforceComposerCooldown' => '1'])->build());

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
    }

    #[Test]
    public function enforcementPassesACorrectCooldown(): void
    {
        $this->writeComposerJson($this->goodPolicy());
        $this->queue(self::MODERN);

        $result = $this->runLane($this->factory->builder()->withComposerCooldownEnforced(true)->build());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
    }

    #[Test]
    public function anOlderComposerIsAFindingAndFailsWhenEnforced(): void
    {
        $this->writeComposerJson($this->goodPolicy());
        $this->queue('Composer version 2.8.0 2026-01-01 00:00:00');

        $result = $this->runLane($this->factory->builder()->withComposerCooldownEnforced(true)->build());

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('Composer 2.8.0 is older than 2.11', $this->factory->output->fetch());
    }

    #[Test]
    public function aVersionProbeThatFailsIsAFindingByName(): void
    {
        $this->writeComposerJson($this->goodPolicy());
        $this->factory->processes->willSucceed()->willFail(1, 'boom')->willSucceed()->willSucceed()->willSucceed();

        $result = $this->runLane();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('could not read the installed Composer version', $this->factory->output->fetch());
    }

    #[Test]
    public function anUnreadableComposerJsonIsNamedAndDoesNotFailAReportOnlyLane(): void
    {
        \Safe\unlink($this->factory->project->path . self::COMPOSER_JSON);
        $this->queueWithoutVersionProbe();

        $result  = $this->runLane();
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString(self::UNREADABLE . ': ', $printed);
        self::assertStringContainsString('Report-only: these findings do not fail the lane.', $printed);
        self::assertCount(4, $this->factory->processes->specs, 'diagnose, audit, normalize and dump-autoload all ran');
    }

    #[Test]
    public function aComposerJsonThatIsNotJsonIsNamedAndDoesNotFailAReportOnlyLane(): void
    {
        $this->factory->project->write('composer.json', '{ not json');
        $this->queueWithoutVersionProbe();

        $result = $this->runLane();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString(self::UNREADABLE . ': ', $this->factory->output->fetch());
    }

    #[Test]
    public function anUnreadableComposerJsonFailsAnEnforcedLaneButStillLetsTheAuditRun(): void
    {
        \Safe\unlink($this->factory->project->path . self::COMPOSER_JSON);
        $this->queueWithoutVersionProbe();

        $result  = $this->runLane($this->factory->builder()->withComposerCooldownEnforced(true)->build());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString(self::UNREADABLE, $result->summary);
        self::assertStringContainsString(ComposerChecksTool::IDENTIFIER, $printed);
        self::assertCount(4, $this->factory->processes->specs, 'the audit and later steps ran after the finding');
    }

    #[Test]
    public function anAuditFailureAndAnEnforcedCooldownFindingAreBothReported(): void
    {
        \Safe\unlink($this->factory->project->path . self::COMPOSER_JSON);
        $this->factory->processes->willSucceed()->willFail(1, 'advisory');

        $result = $this->runLane($this->factory->builder()->withComposerCooldownEnforced(true)->build());

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('composer audit found advisories', $result->summary);
        self::assertStringContainsString(self::UNREADABLE, $result->summary);
    }

    #[Test]
    public function optingOutNeverReadsComposerJsonForTheCooldown(): void
    {
        \Safe\unlink($this->factory->project->path . self::COMPOSER_JSON);
        $this->queueWithoutVersionProbe();
        $config = $this->factory->builder()
            ->withComposerCooldownEnforced(true)
            ->withoutComposerCooldownCheck('dependencies are resolved by a mirror that enforces its own delay')
            ->build()
        ;

        $result  = $this->runLane($config);
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringNotContainsString(self::REPORT_ONLY, $printed);
        self::assertStringNotContainsString(self::NO_COOLDOWN, $printed);
        self::assertCount(4, $this->factory->processes->specs);
    }

    #[Test]
    public function optingOutPrintsTheReasonOnEveryRunAndSkipsTheCheck(): void
    {
        $this->factory->processes->willSucceed()->willSucceed()->willSucceed()->willSucceed();
        $config = $this->factory->builder()
            ->withComposerCooldownEnforced(true)
            ->withoutComposerCooldownCheck('dependencies are resolved by a mirror that enforces its own delay')
            ->build()
        ;

        $result  = $this->runLane($config);
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('Composer cooldown check skipped for this project (withoutComposerCooldownCheck): dependencies are resolved by a mirror that enforces its own delay', $printed);
        self::assertStringNotContainsString(self::NO_COOLDOWN, $printed);
        self::assertCount(4, $this->factory->processes->specs, 'no version probe runs');
    }

    #[Test]
    public function everySecurityOverrideIsListedOnEveryRunEvenWhenAllIsWell(): void
    {
        $this->writeComposerJson($this->goodPolicy(['thirdparty/lib' => self::OVERRIDE]));
        $this->queue(self::MODERN);

        $result  = $this->runLane();
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString("Security overrides in force (config.policy.cooldown.ignore):\n  - thirdparty/lib: CVE-2026-1234, fixed in 1.2.3, remove after 2026-11-20\n", $printed);
        self::assertStringContainsString('Composer cooldown policy is in place.', $printed);
    }

    #[Test]
    public function anExpiredOverrideIsAFindingJudgedAgainstTheClock(): void
    {
        $this->writeComposerJson($this->goodPolicy(['thirdparty/lib' => self::OVERRIDE]));
        $this->queue(self::MODERN);

        $result = $this->runLane($this->factory->builder()->withComposerCooldownEnforced(true)->build(), self::NOVEMBER_TWENTY_FIRST);

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('2026-11-20 has passed', $this->factory->output->fetch());
    }

    #[Test]
    public function anInternalVendorTheProjectNamedMustBeExemptedToo(): void
    {
        $this->writeComposerJson($this->goodPolicy());
        $this->queue(self::MODERN);
        $config = $this->factory->builder()
            ->withComposerCooldownEnforced(true)
            ->withComposerCooldownInternalVendors('acme-tools')
            ->build()
        ;

        $result = $this->runLane($config);

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('"acme-tools/*"', $this->factory->output->fetch());
    }

    /** A repository can hold more than one Composer project; each gets the check the root one does (#394). */
    #[Test]
    public function aNestedManifestWithNoCooldownIsNamedAndDoesNotFailAReportOnlyLane(): void
    {
        $this->writeComposerJson($this->goodPolicy());
        $this->writeNested(['name' => 'acme/tool']);
        $this->queue(self::MODERN);

        $result  = $this->runWithFiles(['composer.json', self::TOOL_MANIFEST]);
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString(self::NESTED_HEADING . ' (1 checked)', $printed);
        self::assertStringContainsString(self::TOOL_MANIFEST . ': ' . self::NO_COOLDOWN, $printed);
        self::assertStringContainsString('Report-only: these findings do not fail the lane.', $printed);
    }

    #[Test]
    public function enforcementFailsOnANestedFindingAndCountsItWithTheRootOnes(): void
    {
        $this->writeComposerJson($this->goodPolicy());
        $this->writeNested(['name' => 'acme/tool']);
        $this->queue(self::MODERN);

        $result = $this->runWithFiles(['composer.json', self::TOOL_MANIFEST], $this->factory->builder()->withComposerCooldownEnforced(true)->build());

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame(self::ENFORCED_ONE, $result->summary);
    }

    #[Test]
    public function aRootFindingAndANestedOneAreBothCounted(): void
    {
        $this->queue(self::MODERN);
        $this->writeNested(['name' => 'acme/tool']);

        $result = $this->runWithFiles(['composer.json', self::TOOL_MANIFEST], $this->factory->builder()->withComposerCooldownEnforced(true)->build());

        self::assertSame('2 Composer cooldown finding(s)', $result->summary);
    }

    #[Test]
    public function aNestedManifestThatSwitchesTheCooldownOffIsNamed(): void
    {
        $this->writeComposerJson($this->goodPolicy());
        $this->writeNested(['name' => 'acme/tool', 'config' => ['policy' => ['cooldown' => false]]]);
        $this->queue(self::MODERN);

        $this->runWithFiles(['composer.json', self::TOOL_MANIFEST]);

        self::assertStringContainsString(self::TOOL_MANIFEST . ': config.policy.cooldown is false', $this->factory->output->fetch());
    }

    #[Test]
    public function aNestedManifestWithACorrectCooldownIsInPlaceAndNamesNoFinding(): void
    {
        $this->writeComposerJson($this->goodPolicy());
        $this->writeNested([...$this->goodPolicy(), 'name' => 'acme/tool']);
        $this->queue(self::MODERN);

        $result  = $this->runWithFiles(['composer.json', self::TOOL_MANIFEST], $this->factory->builder()->withComposerCooldownEnforced(true)->build());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString(self::NESTED_HEADING . ' (1 checked): the cooldown policy is in place in each.', $printed);
        self::assertStringNotContainsString(self::TOOL_MANIFEST . ': ', $printed);
    }

    #[Test]
    public function anUnreadableNestedManifestIsNamedAndCountsWhenEnforced(): void
    {
        $this->writeComposerJson($this->goodPolicy());
        $this->factory->project->write(self::TOOL_MANIFEST, '{');
        $this->queue(self::MODERN);

        $result = $this->runWithFiles(['composer.json', self::TOOL_MANIFEST], $this->factory->builder()->withComposerCooldownEnforced(true)->build());

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame(self::ENFORCED_ONE, $result->summary);
        self::assertStringContainsString(self::TOOL_MANIFEST . ': ' . self::UNREADABLE, $this->factory->output->fetch());
    }

    #[Test]
    public function aProjectThatIsNotAGitWorkTreeSaysTheNestedManifestsWereNotChecked(): void
    {
        $this->writeComposerJson($this->goodPolicy());
        $this->queue(self::MODERN);

        $result  = $this->runWithFiles(null, $this->factory->builder()->withComposerCooldownEnforced(true)->build());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString(self::NESTED_HEADING . ' not checked: the project is not a git work tree, so there is no file list to read them from.', $printed);
    }

    /** The version of Composer is one fact about the host, not one per manifest. */
    #[Test]
    public function theComposerVersionFindingIsNotRepeatedForANestedManifest(): void
    {
        $this->writeComposerJson($this->goodPolicy());
        $this->writeNested([...$this->goodPolicy(), 'name' => 'acme/tool']);
        $this->queue('Composer version 2.8.0 2026-01-01 00:00:00');

        $result  = $this->runWithFiles(['composer.json', self::TOOL_MANIFEST], $this->factory->builder()->withComposerCooldownEnforced(true)->build());
        $printed = $this->factory->output->fetch();

        self::assertSame(self::ENFORCED_ONE, $result->summary);
        self::assertSame(1, substr_count($printed, 'is older than 2.11'));
    }

    #[Test]
    public function optingOutNeverAsksForTheProjectsFiles(): void
    {
        $files = new FixedProjectFiles(['composer.json', self::TOOL_MANIFEST]);
        $this->queueWithoutVersionProbe();

        $this->runWithProjectFiles($files, $this->factory->builder()->withoutComposerCooldownCheck('built from a fixed lock file only')->build());

        self::assertSame(0, $files->asked);
        self::assertStringNotContainsString(self::NESTED_HEADING, $this->factory->output->fetch());
    }

    #[Test]
    public function aNestedManifestIsJudgedForItsOwnVendor(): void
    {
        $this->writeComposerJson($this->goodPolicy());
        $this->writeNested([...$this->goodPolicy(), 'name' => 'other-vendor/tool']);
        $this->queue(self::MODERN);

        $this->runWithFiles(['composer.json', self::TOOL_MANIFEST]);

        self::assertStringContainsString(self::TOOL_MANIFEST . ': config.policy.cooldown.ignore has no "other-vendor/*" entry', $this->factory->output->fetch());
    }

    #[Test]
    public function aProjectWithOnlyItsRootManifestSaysNothingAboutNestedOnes(): void
    {
        $this->writeComposerJson($this->goodPolicy());
        $this->queue(self::MODERN);

        $this->runWithFiles(['composer.json', 'src/Foo.php']);

        self::assertStringNotContainsString(self::NESTED_HEADING, $this->factory->output->fetch());
    }

    #[Test]
    public function aNestedManifestUnderAnIgnoredPathIsNotChecked(): void
    {
        $this->writeComposerJson($this->goodPolicy());
        $this->factory->project->write('tests/assets/p/composer.json', '{"name": "fixture/p"}');
        $this->queue(self::MODERN);

        $this->runWithFiles(['composer.json', 'tests/assets/p/composer.json'], $this->factory->builder()->withIgnoredPaths('tests/assets')->build());
        $printed = $this->factory->output->fetch();

        self::assertStringNotContainsString('tests/assets/p/composer.json', $printed);
        self::assertStringNotContainsString(self::NESTED_HEADING, $printed);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function writeNested(array $json): void
    {
        $this->factory->project->write(self::TOOL_MANIFEST, \Safe\json_encode($json, JSON_THROW_ON_ERROR));
    }

    /** @param list<string>|null $files */
    private function runWithFiles(?array $files, ?QaConfigDto $config = null): ToolResultDto
    {
        return $this->runWithProjectFiles(new FixedProjectFiles($files), $config);
    }

    private function runWithProjectFiles(FixedProjectFiles $files, ?QaConfigDto $config = null): ToolResultDto
    {
        $tool = new ComposerChecksTool(self::COMPOSER, new RedundantSuggestDetector(), new CooldownPolicyChecker(), new MutableClock(self::NOVEMBER_SECOND), $files);

        return $tool->run($this->factory->context($config ?? $this->factory->builder()->build()));
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, mixed>
     */
    private function goodPolicy(array $overrides = []): array
    {
        return [
            'name'   => 'acme/app',
            'type'   => 'project',
            'config' => ['policy' => ['cooldown' => [
                'period' => '7 days',
                'ignore' => ['acme/*' => self::REASON, ...$overrides],
            ]]],
        ];
    }

    /**
     * @param array<string, mixed> $json
     */
    private function writeComposerJson(array $json): void
    {
        $this->factory->project->write('composer.json', \Safe\json_encode($json, JSON_THROW_ON_ERROR));
    }

    private function queue(string $versionOutput): void
    {
        $this->factory->processes->willSucceed()->willSucceed($versionOutput)->willSucceed()->willSucceed()->willSucceed();
    }

    private function queueWithoutVersionProbe(): void
    {
        $this->factory->processes->willSucceed()->willSucceed()->willSucceed()->willSucceed();
    }

    private function runLane(?\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto $config = null, int $now = self::NOVEMBER_SECOND): \LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto
    {
        $tool = new ComposerChecksTool(self::COMPOSER, new RedundantSuggestDetector(), new CooldownPolicyChecker(), new MutableClock($now));

        return $tool->run($this->factory->context($config ?? $this->factory->builder()->build()));
    }
}
