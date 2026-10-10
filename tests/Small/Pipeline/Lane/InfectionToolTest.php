<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use Closure;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\GitBranches;
use LTS\PHPQA\Pipeline\Lane\Infection\CommentOnlyChange;
use LTS\PHPQA\Pipeline\Lane\Infection\Dto\InfectionDiffBaseDto;
use LTS\PHPQA\Pipeline\Lane\Infection\Dto\InfectionDiffFilterDto;
use LTS\PHPQA\Pipeline\Lane\Infection\InfectionArguments;
use LTS\PHPQA\Pipeline\Lane\Infection\InfectionDiffBaseResolver;
use LTS\PHPQA\Pipeline\Lane\Infection\InfectionDiffFilter;
use LTS\PHPQA\Pipeline\Lane\Infection\InfectionFullRunTriggers;
use LTS\PHPQA\Pipeline\Lane\Infection\TestSourceMirror;
use LTS\PHPQA\Pipeline\Lane\Infection\VacuousKillDetector;
use LTS\PHPQA\Pipeline\Lane\InfectionTool;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InfectionTool::class)]
#[UsesClass(InfectionArguments::class)]
#[UsesClass(InfectionDiffFilter::class)]
#[UsesClass(InfectionDiffFilterDto::class)]
#[UsesClass(InfectionDiffBaseResolver::class)]
#[UsesClass(InfectionDiffBaseDto::class)]
#[UsesClass(TestSourceMirror::class)]
#[UsesClass(CommentOnlyChange::class)]
#[UsesClass(InfectionFullRunTriggers::class)]
#[UsesClass(GitBranches::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\IgnoredPaths::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\Infection\IgnoredPathsInfectionConfig::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(EnvironmentReader::class)]
#[UsesClass(QaConfigBuilder::class)]
#[UsesClass(VacuousKillDetector::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\Infection\Dto\VacuousKillDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\Infection\Dto\KillJudgementDto::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class InfectionToolTest extends TestCase
{
    private const string COVERAGE_XML_INDEX_XML = 'var/qa/phpunit_logs/coverage-xml/index.xml';

    private const string MINIMAL_XML = '<x/>';

    private const string INFECTION = 'infection';

    private const string COVERED_FLOOR_ARG = '--min-covered-msi=76';

    private const string SRC_DIR = '/src';

    private const string PROJECT_CONFIG = 'qaConfig/infection.json';

    private const string CONFIGURATION_ARG = '--configuration=';

    private const string LEGACY = 'src/Legacy';

    // A full run unless a test says otherwise: auto mode's git probes are tested on their own below.
    private const array FLOORS = ['mutationScoreIndicator' => '74', 'coveredCodeMSI' => '76', 'infectionThreads' => '4', 'infectionDiffBase' => 'full'];

    private const array AUTO = [...self::FLOORS, 'infectionDiffBase' => 'auto'];

    private const string GIT_STATUS_CLEAN = '';

    private const string NO_PREFIX = "\n";

    private const string FEATURE_BRANCH = "feature/x\n";

    private const string ORIGIN_DEFAULT = "refs/remotes/origin/main\n";

    private const string MERGE_BASE = "abc1234\n";

    private const string COMMITTED_SRC = 'src/Committed.php';

    private const string LOG_ALL = '--log-verbosity=all';

    private const string TESTS_DIR = '/tests';

    private const string RESOLVED = "abc\n";

    private const string FULL_FLOOR_ARG = '--min-msi=74';

    private const string JSON_LOG = 'var/qa/infection/infection-log.json';

    private const string PHPUNIT_HEADER = "PHPUnit 13.4.1 by Sebastian Bergmann and contributors.\n\nRuntime:       PHP 8.5.11\n\n";

    // What a mutant run printed when the suite could not start under Infection (issue #122).
    private const string NO_TESTS_EXECUTED = self::PHPUNIT_HEADER . "There was 1 PHPUnit test runner warning:\n\n1) Bootstrapping of extension X failed\n\nNo tests executed!";

    private const string REAL_KILL = self::PHPUNIT_HEADER . "F\n\nFAILURES!\nTests: 1, Assertions: 1, Failures: 1.";

    private ContextFactory $factory;

    private string $root;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        $this->root    = $this->factory->project->path;
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function withoutXdebugTheLaneSkips(): void
    {
        $result = $this->tool()->run($this->context($this->factory->builder(xdebug: false)));

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame([], $this->factory->processes->specs);
        self::assertStringContainsString('Xdebug is not enabled', $this->factory->output->fetch());
    }

    #[Test]
    public function aFullRunReusesThisRunsCoverageAndRunsThePharAtLowPriority(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->project->write('var/qa/infection/log.txt', 'stale');
        $this->factory->project->write('var/qa/infection/tmp/deep/file', 'stale');
        $this->factory->processes->willRun($this->infection());

        $result  = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('Infection: full run — every source file is mutated (withInfectionFullRun() / infectionDiffBase=full).', $printed);
        self::assertStringContainsString('reusing the coverage produced by the phpunit step this run', $printed);

        $phar = $this->factory->processes->lastSpec();
        self::assertSame([
            '/usr/bin/php', '-d', 'memory_limit=4G',
            '-f', \dirname(__DIR__, 4) . '/vendor-phar/infection.phar', '--',
            '--coverage=' . $this->root . '/var/qa/phpunit_logs',
            '--skip-initial-tests',
            '--threads=4',
            self::CONFIGURATION_ARG . $this->derivedConfigPath(),
            self::FULL_FLOOR_ARG,
            self::COVERED_FLOOR_ARG,
            self::LOG_ALL,
        ], $phar->command);
        self::assertTrue($phar->lowPriority);
        self::assertTrue($phar->streamOutput);
        self::assertSame($this->root, $phar->cwd);

        self::assertDirectoryExists($this->root . '/var/qa/infection');
        self::assertSame([basename(self::JSON_LOG)], $this->factory->project->files('var/qa/infection'), 'previous infection output is cleared');
        self::assertDirectoryDoesNotExist($this->root . '/var/qa/infection/tmp');
    }

    #[Test]
    public function theConfigInfectionRunsWithAlwaysWritesTheJsonLogTheLaneReads(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willRun($this->infection());

        $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)));

        $generic = \dirname(__DIR__, 4) . '/configDefaults/generic';
        $written = \Safe\file_get_contents($this->derivedConfigPath());
        self::assertStringEndsWith("}\n", $written);
        $derived = \Safe\json_decode($written, true);
        self::assertIsArray($derived);
        // The shipped config reaches the consumer's root from vendor/lts/php-qa-ci/configDefaults/generic;
        // in a checkout fewer than five levels deep that is `/`, so it is joined without its separator.
        $consumer = rtrim(\dirname($generic, 5), '/');
        self::assertSame(
            [
                'text'    => $consumer . '/var/qa/infection/log.txt',
                'summary' => $consumer . '/var/qa/infection/summary-log.txt',
                'debug'   => $consumer . '/var/qa/infection/debug-log.txt',
                'json'    => $this->root . '/' . self::JSON_LOG,
            ],
            $derived['logs'],
            'the shipped logs mean what they meant from the original config, and the JSON log is added',
        );
        self::assertSame(['configDir' => $generic], $derived['phpUnit']);
        self::assertStringNotContainsString('ignored paths', $this->factory->output->fetch(), 'nothing is excluded, so nothing is said about exclusions');
    }

    #[Test]
    public function aProjectsOwnJsonLogIsKeptAndRead(): void
    {
        $this->factory->project->write(self::PROJECT_CONFIG, '{"source": {"directories": ["../src"]}, "logs": {"json": "../build/mutants.json"}}');
        $this->factory->project->write('build/mutants.json', 'left by an earlier run');
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willRun($this->infection(0, self::NO_TESTS_EXECUTED));

        $result = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome, 'the project-declared log is the one read');
        self::assertStringContainsString($this->root . '/build/mutants.json', $this->factory->output->fetch());
    }

    #[Test]
    public function aStaleProjectJsonLogIsRemovedBeforeInfectionRuns(): void
    {
        $this->factory->project->write(self::PROJECT_CONFIG, '{"source": {"directories": ["../src"]}, "logs": {"json": "../build/mutants.json"}}');
        $this->factory->project->write('build/mutants.json', \Safe\json_encode(['killed' => []]));
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willSucceed();

        $result = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome, 'a log left by an earlier run is no evidence about this one');
    }

    #[Test]
    public function whenNoTestRanForAnyKilledMutantTheLaneCrashesAndNamesTheCause(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willRun($this->infection(0, self::NO_TESTS_EXECUTED, self::NO_TESTS_EXECUTED));

        $result  = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome, 'a vacuous MSI is not a score to report');
        self::assertSame('No test ran for any of the 2 mutant(s) Infection counted as killed', $result->summary);
        self::assertStringContainsString('Infection: no test ran for any of the 2 mutant(s) Infection counted as killed, so the scores above are not a measurement.', $printed);
        self::assertStringContainsString("\n           The test suite does not start under Infection", $printed);
        self::assertStringContainsString('every mutant in this run is in code the test bootstrap itself runs', $printed, 'the rarer cause is named too');
        self::assertStringNotContainsString('not the mutants', $printed);
        self::assertStringContainsString(\sprintf("\n           %s/src/Foo.php:7 Plus,%1\$s/src/Foo.php:8 Plus\n", $this->root), $printed);
        self::assertStringContainsString("\n           The test output of the first:\n             PHPUnit 13.4.1", $printed);
        self::assertStringContainsString("\n             No tests executed!\n", $printed);
        self::assertStringContainsString($this->everyMutantLine(), $printed);
        self::assertStringContainsString(InfectionTool::IDENTIFIER, $printed);
    }

    /**
     * One kill made by a test proves the suite starts under Infection, so a
     * mutant that stopped it before any test ran most likely broke the code
     * the bootstrap runs. When the floors hold without it, the verdict does
     * not rest on it, and it is named so it can be looked at.
     */
    #[Test]
    public function aRunMixingRealKillsWithAVacuousOnePassesWithANoticeWhenTheFloorsHoldWithoutIt(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willRun($this->infection(0, self::REAL_KILL, self::NO_TESTS_EXECUTED, ...array_fill(0, 8, self::REAL_KILL)));

        $result  = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome, 'without the vacuous kill both scores are 90%, above 74% and 76%');
        self::assertStringContainsString(\sprintf('Infection: 1 of 10 killed mutant(s) stopped the test suite before any test ran, the first %s/src/Foo.php:8 Plus.', $this->root), $printed);
        self::assertStringContainsString('Without them the MSI is 90% and the covered-code MSI 90%, still at or above the floors of 74% and 76%.', $printed);
        self::assertStringContainsString('The other kills ran tests, so the suite starts under Infection: these mutants most likely broke code the test bootstrap runs.', $printed);
        self::assertStringContainsString($this->everyMutantLine(), $printed);
        self::assertStringNotContainsString(InfectionTool::IDENTIFIER, $printed);
    }

    /**
     * A kill no test made is not evidence. When a passing run meets a floor only
     * by counting such kills, the pass is not a measurement either: the suite may
     * start only some of the time, and nothing in the score says so (issue #125).
     */
    #[Test]
    public function aPassThatRestsOnVacuousKillsIsACrashThatNamesThem(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willRun($this->infection(0, self::REAL_KILL, self::NO_TESTS_EXECUTED, self::REAL_KILL, self::REAL_KILL));

        $result  = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome, 'without the vacuous kill the covered-code MSI is 75%, below 76%');
        self::assertSame('The MSI floors hold only by counting 1 killed mutant(s) no test ran for', $result->summary);
        self::assertStringContainsString(\sprintf('Infection: 1 of 4 killed mutant(s) stopped the test suite before any test ran, the first %s/src/Foo.php:8 Plus.', $this->root), $printed);
        self::assertStringContainsString('Without them the MSI is 75% and the covered-code MSI 75%, below the floors of 74% and 76%, so the pass rests on kills no test made.', $printed);
        self::assertStringContainsString('the suite starts only some of the time', $printed, 'the cause the score cannot show is named');
        self::assertStringContainsString('code the test bootstrap runs', $printed, 'and so is the other');
        self::assertStringContainsString($this->everyMutantLine(), $printed);
        self::assertStringContainsString(InfectionTool::IDENTIFIER, $printed);
    }

    /** A diff run holds both scores to the diff floor, so that is the floor the kills are judged against. */
    #[Test]
    public function aDiffRunJudgesVacuousKillsAgainstTheDiffFloor(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willSucceed(self::GIT_STATUS_CLEAN)->willSucceed($this->nameStatus(['M', 'src/Changed.php']))
            ->willRun($this->infection(0, self::NO_TESTS_EXECUTED, ...array_fill(0, 4, self::REAL_KILL)))
        ;

        $result  = $this->tool()->run($this->context($this->diffBuilder(['infectionDiffCoveredMsi' => '81'])));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome, '80% without the vacuous kill is above the full floors, but below the diff floor of 81%');
        self::assertStringContainsString('below the floors of 81% and 81%', $printed);
    }

    /** The stats are what the scores without the vacuous kills are worked out from; a log without them cannot be judged. */
    #[Test]
    public function aMixedRunWhoseLogHasNoStatsCannotBeJudged(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willRun(function (ProcessSpecDto $spec): ProcessResultDto {
            $this->factory->project->write(self::JSON_LOG, \Safe\json_encode([
                'stats'  => ['killedCount' => 2],
                'killed' => [
                    $this->killed(7, self::REAL_KILL),
                    $this->killed(8, self::NO_TESTS_EXECUTED),
                ],
            ]));

            return new ProcessResultDto(0, '', '');
        });

        $result  = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame('the Infection JSON log could not be read', $result->summary);
        self::assertStringContainsString('the Infection JSON log has no "stats" to work out the scores without the kills no test ran for', $printed);
    }

    #[Test]
    public function aMixedRunBelowTheFloorIsAFailureNotACrash(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willRun($this->infection(1, self::NO_TESTS_EXECUTED, self::REAL_KILL, self::NO_TESTS_EXECUTED));

        $result  = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome, 'the score is a measurement, so it is reported');
        self::assertSame('Infection failed (exit 1)', $result->summary);
        self::assertStringContainsString(\sprintf('Infection: 2 of 3 killed mutant(s) stopped the test suite before any test ran, the first %s/src/Foo.php:7 Plus.', $this->root), $printed);
        self::assertStringContainsString($this->everyMutantLine(), $printed, 'the failure still points at the test output of the kills no test made');
    }

    #[Test]
    public function aRunWithNoKilledMutantHasNothingToJudge(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willRun(function (ProcessSpecDto $spec): ProcessResultDto {
            $this->factory->project->write(self::JSON_LOG, \Safe\json_encode(['stats' => ['killedCount' => 0], 'escaped' => [], 'killed' => []]));

            return new ProcessResultDto(0, '', '');
        });

        $result  = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringNotContainsString('no test ran', $printed);
        self::assertStringNotContainsString('before any test ran', $printed);
    }

    #[Test]
    public function manyVacuousKillsAreSummarisedAfterTheFirstTen(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willRun($this->infection(0, ...array_fill(0, 12, self::NO_TESTS_EXECUTED)));

        $result  = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)));
        $printed = $this->factory->output->fetch();

        self::assertSame('No test ran for any of the 12 mutant(s) Infection counted as killed', $result->summary);
        self::assertSame(10, substr_count($printed, 'src/Foo.php:'));
        self::assertStringContainsString('src/Foo.php:16 Plus and 2 more', $printed);
        self::assertSame(1, substr_count($printed, 'Bootstrapping of extension X failed'), 'one output is shown, not twelve');
    }

    #[Test]
    public function tenVacuousKillsAreAllNamedWithNothingMore(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willRun($this->infection(0, ...array_fill(0, 10, self::NO_TESTS_EXECUTED)));

        $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)));
        $printed = $this->factory->output->fetch();

        self::assertStringContainsString("src/Foo.php:16 Plus\n", $printed);
        self::assertStringNotContainsString(' more', $printed);
    }

    #[Test]
    public function anEmptyLogsObjectGetsTheJsonLog(): void
    {
        $this->factory->project->write(self::PROJECT_CONFIG, '{"source": {"directories": ["../src"]}, "logs": {}}');
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willRun($this->infection());

        $result = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('"logs": {' . "\n" . '        "json": ', \Safe\file_get_contents($this->derivedConfigPath()));
    }

    #[Test]
    public function aFloorBreachWithVacuousKillsCrashesRatherThanReportingTheScore(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willRun($this->infection(1, self::NO_TESTS_EXECUTED, self::NO_TESTS_EXECUTED, self::NO_TESTS_EXECUTED));

        $result = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame('No test ran for any of the 3 mutant(s) Infection counted as killed', $result->summary);
    }

    /** Infection writes a log to a php:// stream rather than a file, so the copy keeps it as written. */
    #[Test]
    public function aPhpStreamLogTargetReachesInfectionUnchanged(): void
    {
        $this->factory->project->write(self::PROJECT_CONFIG, '{"source": {"directories": ["../src"]}, "logs": {"text": "php://stderr", "summary": "php://stdout"}}');
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willRun($this->infection());

        $result = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        $derived = \Safe\json_decode(\Safe\file_get_contents($this->derivedConfigPath()), true);
        self::assertIsArray($derived);
        self::assertSame(['text' => 'php://stderr', 'summary' => 'php://stdout', 'json' => $this->root . '/' . self::JSON_LOG], $derived['logs'] ?? null);
    }

    /**
     * The lane cannot read a JSON log back from a stream, and Infection's
     * logs.json takes one target, so the lane's own file takes its place and
     * the run says so.
     */
    #[Test]
    public function aJsonLogSentToAPhpStreamIsReplacedByTheFileTheLaneReads(): void
    {
        $this->factory->project->write(self::PROJECT_CONFIG, '{"source": {"directories": ["../src"]}, "logs": {"json": "php://stdout"}}');
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willRun($this->infection(0, self::NO_TESTS_EXECUTED));

        $result  = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)));
        $printed = $this->factory->output->fetch();

        $derived = \Safe\json_decode(\Safe\file_get_contents($this->derivedConfigPath()), true);
        self::assertIsArray($derived);
        self::assertSame(['json' => $this->root . '/' . self::JSON_LOG], $derived['logs'] ?? null);
        self::assertStringContainsString(\sprintf('Infection: logs.json in %s/%s is php://stdout, which the lane cannot read back, so this run writes the JSON log to %s/%s instead.', $this->root, self::PROJECT_CONFIG, $this->root, self::JSON_LOG), $printed);
        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome, 'the kills are still judged, from the file');
    }

    #[Test]
    public function aPassingRunThatWroteNoJsonLogCrashes(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willSucceed();

        $result  = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome, 'an unverifiable pass is not a pass');
        self::assertStringContainsString('wrote no JSON log at ' . $this->root . '/' . self::JSON_LOG, $printed);
        self::assertStringContainsString(InfectionTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function aJsonLogThatCannotBeReadCrashes(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willRun(function (ProcessSpecDto $spec): ProcessResultDto {
            $this->factory->project->write(self::JSON_LOG, '{"killed": ');

            return new ProcessResultDto(0, '', '');
        });

        $result  = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertStringContainsString('could not be read', $printed);
        self::assertStringContainsString(InfectionTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function aSingleToolRunGeneratesFreshCoverageWithXdebug(): void
    {
        $this->factory->project->write('var/qa/phpunit_logs/coverage-xml/stale.xml', self::MINIMAL_XML);
        $this->factory->processes->willSucceed('OK (3 tests)')->willRun($this->infection());

        $result  = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS, singleTool: self::INFECTION)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('generating fresh coverage (xdebug)', $printed);
        self::assertFileDoesNotExist($this->root . '/var/qa/phpunit_logs/coverage-xml/stale.xml', 'stale coverage is removed before regeneration');

        $coverage = $this->factory->processes->specs[0];
        self::assertSame([
            '/usr/bin/php', '-d', 'memory_limit=4G', '-f', $this->root . '/vendor/bin/phpunit', '--',
            '-c', \dirname(__DIR__, 4) . '/configDefaults/generic/phpunit.xml',
            '--coverage-xml', $this->root . '/var/qa/phpunit_logs/coverage-xml',
            '--log-junit', $this->root . '/var/qa/phpunit_logs/phpunit.junit.xml',
        ], $coverage->command);
        self::assertSame(['XDEBUG_MODE' => 'coverage'], $coverage->env);
        self::assertCount(2, $this->factory->processes->specs);
    }

    #[Test]
    public function aFullRunWithNoCoverageOnDiskGeneratesIt(): void
    {
        $this->factory->processes->willSucceed()->willRun($this->infection());

        $result = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('generating fresh coverage', $this->factory->output->fetch());
    }

    #[Test]
    public function aFailingCoverageRunFailsTheLaneBeforeInfectionRuns(): void
    {
        $this->factory->processes->willFail(1, 'FAILURES!');

        $result  = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS, singleTool: self::INFECTION)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertCount(1, $this->factory->processes->specs);
        self::assertStringContainsString('coverage generation FAILED (phpunit exit 1) — the test suite is not green', $printed);
        self::assertStringContainsString(InfectionTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function anEscapedMutantFloorBreachIsFailedWithTheIdentifier(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willFail(1, 'MSI 50% is less than min MSI 74%');

        $result  = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('Infection failed (exit 1)', $result->summary);
        self::assertStringContainsString(InfectionTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function diffModeRefusesADirtyWorkingTree(): void
    {
        $this->factory->processes->willSucceed("?? src/Dirty.php\0")->willSucceed(self::NO_PREFIX);

        $result  = $this->tool()->run($this->context($this->diffBuilder()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome, 'a dirty src/tests tree must REFUSE the diff lane');
        self::assertCount(2, $this->factory->processes->specs, 'only the status and its path prefix run before the refusal');
        self::assertSame(['git', 'status', '--porcelain=v1', '-z', '--untracked-files=all', '--', ...$this->pathspecs()], $this->factory->processes->specs[0]->command);
        self::assertFalse($this->factory->processes->specs[0]->streamOutput);
        self::assertStringContainsString('REFUSED', $printed);
        self::assertStringContainsString('src/Dirty.php', $printed);
        self::assertStringContainsString('commit', strtolower($printed));
        self::assertStringContainsString(InfectionTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function diffModeFailsWhenGitStatusItselfFails(): void
    {
        $this->factory->processes->willFail(128, 'fatal: not a git repository');

        $result = $this->tool()->run($this->context($this->diffBuilder()));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString("'git status' failed; cannot verify the working tree is clean", $this->factory->output->fetch());
    }

    #[Test]
    public function diffModeAcceptsACleanTreeAndScopesToTheCommittedChange(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes
            ->willSucceed(self::GIT_STATUS_CLEAN)
            ->willSucceed($this->nameStatus(['M', self::COMMITTED_SRC]))
            ->willRun($this->infection())
        ;

        $result  = $this->tool()->run($this->context($this->diffBuilder()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            ['git', '--no-pager', 'diff', 'origin/main...HEAD', '-z', '-M', '--name-status', '--diff-filter=AMRCD', '--relative', '--', ...$this->pathspecs()],
            $this->factory->processes->specs[1]->command,
            'the filter is computed from committed history (three-dot diff), never the working tree',
        );
        self::assertSame($this->root, $this->factory->processes->specs[1]->cwd);
        self::assertStringContainsString("Infection: diff mode against the configured base 'origin/main' (withInfectionDiffBase / infectionDiffBase), committed history only.", $printed);
        self::assertStringContainsString('mutating 1 file(s): src/Committed.php', $printed);

        $phar = $this->factory->processes->lastSpec();
        self::assertSame([
            '--coverage=' . $this->root . '/var/qa/phpunit_logs',
            '--skip-initial-tests',
            '--threads=4',
            self::CONFIGURATION_ARG . $this->derivedConfigPath(),
            '--with-uncovered',
            '--min-msi=76',
            self::COVERED_FLOOR_ARG,
            '--ignore-msi-with-no-mutations',
            self::LOG_ALL,
            $this->root . '/' . self::COMMITTED_SRC,
        ], \array_slice($phar->command, 6), 'a diff run mutates uncovered code too and holds the MSI to the diff floor');
        self::assertTrue($phar->lowPriority);
    }

    #[Test]
    public function autoModeOnABranchDiffsAgainstTheMergeBaseAndSaysWhichScopeRan(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes
            ->willSucceed(self::FEATURE_BRANCH)
            ->willSucceed(self::ORIGIN_DEFAULT)
            ->willSucceed(self::RESOLVED)
            ->willSucceed(self::MERGE_BASE)
            ->willSucceed(self::GIT_STATUS_CLEAN)
            ->willSucceed($this->nameStatus(['M', self::COMMITTED_SRC], ['A', 'src/Added.php']))
            ->willRun($this->infection())
        ;

        $result  = $this->tool()->run($this->context($this->factory->builder(env: self::AUTO)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString("Infection: auto diff mode — branch 'feature/x' against origin/main (merge base abc1234).", $printed);
        self::assertStringContainsString('mutating 2 file(s): src/Committed.php,src/Added.php', $printed);
        self::assertStringStartsWith('git --no-pager diff abc1234...HEAD ', $this->factory->processes->commandLines()[5]);
        self::assertSame([$this->root . '/' . self::COMMITTED_SRC, $this->root . '/src/Added.php'], \array_slice($this->factory->processes->lastSpec()->command, -2));
    }

    #[Test]
    public function autoModeOnTheDefaultBranchRunsInFullAndSaysSo(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willSucceed("main\n")->willSucceed(self::ORIGIN_DEFAULT)->willRun($this->infection());

        $result  = $this->tool()->run($this->context($this->factory->builder(env: self::AUTO)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString("Infection: full run — auto diff mode does not apply: on the default branch 'main'.", $printed);
        self::assertContains('--min-msi=74', $this->factory->processes->lastSpec()->command, 'the full run keeps the whole-codebase floors');
        self::assertCount(3, $this->factory->processes->specs, 'two git probes, then the phar: no status or diff');
    }

    #[Test]
    public function autoModeWithNoMergeBaseRunsInFullAndSaysWhy(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willSucceed(self::FEATURE_BRANCH)->willSucceed(self::ORIGIN_DEFAULT)->willSucceed(self::RESOLVED)->willFail(1)->willRun($this->infection());

        $result  = $this->tool()->run($this->context($this->factory->builder(env: self::AUTO)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('Infection: full run — auto diff mode does not apply: HEAD and origin/main share no merge base', $printed);
        self::assertContains(self::LOG_ALL, $this->factory->processes->lastSpec()->command);
    }

    #[Test]
    public function autoModeMutatesUncommittedWorkAndNamesItInsteadOfRefusing(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes
            ->willSucceed(self::FEATURE_BRANCH)
            ->willSucceed(self::ORIGIN_DEFAULT)
            ->willSucceed(self::RESOLVED)
            ->willSucceed(self::MERGE_BASE)
            ->willSucceed(" M src/Wip.php\0?? src/Fresh.php\0 M src/Committed.php\0")
            ->willSucceed(self::NO_PREFIX)
            ->willSucceed($this->nameStatus(['M', self::COMMITTED_SRC]))
            ->willRun($this->infection())
        ;

        $result  = $this->tool()->run($this->context($this->factory->builder(env: self::AUTO)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome, 'local work in progress must not fail the default mode');
        self::assertStringContainsString('Infection: auto diff mode — WARNING: uncommitted edits are in scope and mutated as they are on disk, so this verdict is not reproducible from committed history: src/Wip.php,src/Fresh.php,src/Committed.php', $printed);
        self::assertStringNotContainsString('REFUSED', $printed);
        self::assertSame(
            [$this->root . '/' . self::COMMITTED_SRC, $this->root . '/src/Wip.php', $this->root . '/src/Fresh.php'],
            \array_slice($this->factory->processes->lastSpec()->command, -3),
            'nothing changed locally is left out of the scope',
        );
    }

    #[Test]
    public function aChangedConfigurationFileRunsInFullAndSaysWhy(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes
            ->willSucceed(self::GIT_STATUS_CLEAN)
            ->willSucceed($this->nameStatus(['M', 'qaConfig/phpunit.xml'], ['M', self::COMMITTED_SRC]))
            ->willRun($this->infection())
        ;

        $result  = $this->tool()->run($this->context($this->diffBuilder()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('Infection: full run — the change touches configuration every mutant depends on (qaConfig/phpunit.xml), which a diff run cannot judge.', $printed);
        self::assertContains(self::FULL_FLOOR_ARG, $this->factory->processes->lastSpec()->command, 'the full run keeps the whole-codebase floors');
        self::assertNotContains($this->root . '/' . self::COMMITTED_SRC, $this->factory->processes->lastSpec()->command);
    }

    #[Test]
    public function anUncommittedInfectionConfigChangeInAutoModeRunsInFull(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes
            ->willSucceed(self::FEATURE_BRANCH)
            ->willSucceed(self::ORIGIN_DEFAULT)
            ->willSucceed(self::RESOLVED)
            ->willSucceed(self::MERGE_BASE)
            ->willSucceed(' M ' . self::PROJECT_CONFIG . "\0")
            ->willSucceed(self::NO_PREFIX)
            ->willSucceed('')
            ->willRun($this->infection())
        ;

        $this->tool()->run($this->context($this->factory->builder(env: self::AUTO)));

        self::assertStringContainsString('configuration every mutant depends on (qaConfig/infection.json)', $this->factory->output->fetch());
        self::assertContains(self::FULL_FLOOR_ARG, $this->factory->processes->lastSpec()->command);
    }

    #[Test]
    public function composerFilesAndOtherQaConfigAreNeitherWatchedNorAFullRun(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes
            ->willSucceed(self::GIT_STATUS_CLEAN)
            ->willSucceed($this->nameStatus(['M', self::COMMITTED_SRC]))
            ->willRun($this->infection())
        ;

        $result  = $this->tool()->run($this->context($this->diffBuilder()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringNotContainsString('configuration every mutant depends on', $printed);
        foreach ([0, 1] as $probe) {
            $command = $this->factory->processes->specs[$probe]->command;
            foreach (['/composer.json', '/composer.lock', '/qaConfig', '/qaConfig/qa.php', '/qaConfig/phpstan.neon'] as $unwatched) {
                self::assertNotContains($this->root . $unwatched, $command, 'a dependency bump or unrelated QA config must not cost a full mutation run');
            }
        }
    }

    #[Test]
    public function aComposerLockOrQaPhpChangeStaysInDiffModeAndIsNotMentioned(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes
            ->willSucceed(self::GIT_STATUS_CLEAN)
            ->willSucceed($this->nameStatus(['M', 'composer.lock'], ['M', 'qaConfig/qa.php'], ['M', self::COMMITTED_SRC]))
            ->willRun($this->infection())
        ;

        $result  = $this->tool()->run($this->context($this->diffBuilder()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame([$this->root . '/' . self::COMMITTED_SRC], \array_slice($this->factory->processes->lastSpec()->command, -1), 'a diff run, scoped to the source change alone');
        self::assertStringNotContainsString('composer.lock', $printed);
        self::assertStringNotContainsString('qa.php', $printed);
    }

    #[Test]
    public function theBootstrapThePhpunitXmlNamesForcesAFullRun(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->project->write('qaConfig/phpunit.xml', '<phpunit bootstrap="../tests/support/boot.php"/>');
        $this->factory->processes
            ->willSucceed(self::GIT_STATUS_CLEAN)
            ->willSucceed($this->nameStatus(['M', 'tests/support/boot.php']))
            ->willRun($this->infection())
        ;

        $this->tool()->run($this->context($this->diffBuilder()));

        self::assertContains($this->root . '/tests/support/boot.php', $this->factory->processes->specs[0]->command, 'the bootstrap is read from the XML');
        self::assertStringContainsString('configuration every mutant depends on (tests/support/boot.php)', $this->factory->output->fetch());
        self::assertContains(self::FULL_FLOOR_ARG, $this->factory->processes->lastSpec()->command);
    }

    #[Test]
    public function aCommentOnlyChangeIsNamedAndNotMutated(): void
    {
        $this->factory->project->write(self::COMMITTED_SRC, "<?php\n\n/** Documented. */\nfinal class Committed\n{\n}\n");
        $this->factory->processes
            ->willSucceed(self::GIT_STATUS_CLEAN)
            ->willSucceed($this->nameStatus(['M', self::COMMITTED_SRC]))
            ->willSucceed(self::MERGE_BASE)
            ->willSucceed("<?php\n\nfinal class Committed\n{\n}\n")
        ;

        $result  = $this->tool()->run($this->context($this->diffBuilder()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome, 'every change is comment-only, so there is nothing to mutate');
        self::assertSame(
            ['git merge-base origin/main HEAD', 'git show abc1234:./src/Committed.php'],
            \array_slice($this->factory->processes->commandLines(), 2),
            'the base is the merge base the three-dot diff compares against',
        );
        self::assertStringContainsString('Infection: diff mode — comment-only change, not mutated: src/Committed.php', $printed);
        self::assertStringContainsString('there are no new mutants to check. SKIPPING.', $printed);
    }

    #[Test]
    public function aFileWhoseBaseCannotBeReadIsMutated(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->project->write(self::COMMITTED_SRC, "<?php\n\nfinal class Committed\n{\n}\n");
        $this->factory->processes
            ->willSucceed(self::GIT_STATUS_CLEAN)
            ->willSucceed($this->nameStatus(['M', self::COMMITTED_SRC]))
            ->willSucceed(self::MERGE_BASE)
            ->willFail(128, 'fatal: path does not exist')
            ->willRun($this->infection())
        ;

        $result  = $this->tool()->run($this->context($this->diffBuilder()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame([$this->root . '/' . self::COMMITTED_SRC], \array_slice($this->factory->processes->lastSpec()->command, -1), 'doubt about the base means the file is mutated');
        self::assertStringNotContainsString('comment-only', $printed);
    }

    #[Test]
    public function autoModeLeavesAFileDeletedSinceItsCommitOutOfTheScope(): void
    {
        $this->factory->processes
            ->willSucceed(self::FEATURE_BRANCH)
            ->willSucceed(self::ORIGIN_DEFAULT)
            ->willSucceed(self::RESOLVED)
            ->willSucceed(self::MERGE_BASE)
            ->willSucceed(' D ' . self::COMMITTED_SRC . "\0")
            ->willSucceed(self::NO_PREFIX)
            ->willSucceed($this->nameStatus(['M', self::COMMITTED_SRC]))
        ;

        $result = $this->tool()->run($this->context($this->factory->builder(env: self::AUTO)));

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome, 'Infection aborts on a positional path that no longer exists, so it must never be passed one');
        self::assertCount(7, $this->factory->processes->specs, 'no coverage run and no phar');
    }

    #[Test]
    public function autoModeLeavesAStagedFileSinceDeletedFromDiskOutOfTheScope(): void
    {
        $this->factory->processes
            ->willSucceed(self::FEATURE_BRANCH)
            ->willSucceed(self::ORIGIN_DEFAULT)
            ->willSucceed(self::RESOLVED)
            ->willSucceed(self::MERGE_BASE)
            ->willSucceed('MD ' . self::COMMITTED_SRC . "\0AD src/Added.php\0")
            ->willSucceed(self::NO_PREFIX)
            ->willSucceed($this->nameStatus(['M', self::COMMITTED_SRC]))
        ;

        $result = $this->tool()->run($this->context($this->factory->builder(env: self::AUTO)));

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome, 'the staged column is not what is on disk: a file removed since it was staged must not reach Infection');
        self::assertCount(7, $this->factory->processes->specs);
    }

    #[Test]
    public function diffModeFailsWhenTheRepositoryPrefixCannotBeRead(): void
    {
        $this->factory->processes->willSucceed(" M src/Wip.php\0")->willFail(128);

        $result = $this->tool()->run($this->context($this->diffBuilder()));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString("'git rev-parse --show-prefix' failed", $this->factory->output->fetch());
        self::assertCount(2, $this->factory->processes->specs);
    }

    #[Test]
    public function inAProjectBelowTheRepositoryRootUncommittedSourceIsStillSource(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes
            ->willSucceed(self::FEATURE_BRANCH)
            ->willSucceed(self::ORIGIN_DEFAULT)
            ->willSucceed(self::RESOLVED)
            ->willSucceed(self::MERGE_BASE)
            ->willSucceed(" M app/src/Wip.php\0")
            ->willSucceed("app/\n")
            ->willSucceed('')
            ->willRun($this->infection())
        ;

        $result  = $this->tool()->run($this->context($this->factory->builder(env: self::AUTO)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame('git rev-parse --show-prefix', $this->factory->processes->commandLines()[5]);
        self::assertStringContainsString('not reproducible from committed history: src/Wip.php', $printed);
        self::assertStringNotContainsString('full run', $printed, 'git status paths are relative to the repository root, not the project');
        self::assertSame($this->root . '/src/Wip.php', $this->factory->processes->lastSpec()->command[\count($this->factory->processes->lastSpec()->command) - 1]);
    }

    #[Test]
    public function aChangedTestBringsTheSourceItIsNamedAfterIntoScope(): void
    {
        $this->factory->project->write('src/Lane/Tool.php', '<?php');
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willSucceed(self::GIT_STATUS_CLEAN)->willSucceed($this->nameStatus(['M', 'tests/Small/Lane/ToolTest.php']))->willRun($this->infection());

        $result  = $this->tool()->run($this->context($this->diffBuilder()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('Infection: diff mode — src/Lane/Tool.php is in scope because its test tests/Small/Lane/ToolTest.php changed.', $printed);
        self::assertSame([$this->root . '/src/Lane/Tool.php'], \array_slice($this->factory->processes->lastSpec()->command, -1));
    }

    #[Test]
    public function aChangedTestFileNamedAfterNoSourceIsReportedNotPassedQuietly(): void
    {
        $this->factory->processes->willSucceed(self::GIT_STATUS_CLEAN)->willSucceed($this->nameStatus(['M', 'tests/Support/Helper.php']));

        $result  = $this->tool()->run($this->context($this->diffBuilder()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString('1 changed test-directory file(s) are named after no source file, so the source they cover is NOT mutated by this run: tests/Support/Helper.php.', $printed);
        self::assertStringContainsString('infectionDiffBase=full', $printed);
    }

    #[Test]
    public function manyUnmappedTestFilesAreSummarisedAfterTheFirstTen(): void
    {
        $records = array_map(static fn (int $n): array => ['M', \sprintf('tests/fixtures/f%02d.json', $n)], range(1, 12));
        $this->factory->processes->willSucceed(self::GIT_STATUS_CLEAN)->willSucceed($this->nameStatus(...$records));

        $this->tool()->run($this->context($this->diffBuilder()));
        $printed = $this->factory->output->fetch();

        self::assertStringContainsString('12 changed test-directory file(s)', $printed);
        self::assertStringContainsString('tests/fixtures/f10.json and 2 more.', $printed);
        self::assertStringNotContainsString('f11.json', $printed);
    }

    #[Test]
    public function anIgnoredSourcePathReachesInfectionThroughADerivedConfig(): void
    {
        $this->factory->project->write(self::PROJECT_CONFIG, '{"source": {"directories": ["../src"]}, "tmpDir": "../var/qa/infection/tmp"}');
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willRun($this->infection());

        $result = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)->withIgnoredPaths(self::LEGACY, 'tests/assets')));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertContains(self::CONFIGURATION_ARG . $this->derivedConfigPath(), $this->factory->processes->lastSpec()->command);
        $qaConfig = ['configDir' => $this->root . '/qaConfig'];
        self::assertSame(
            [
                'source'  => ['directories' => [$this->root . self::SRC_DIR], 'excludes' => ['#^Legacy(?:/|$)#']],
                'tmpDir'  => $this->root . '/var/qa/infection/tmp',
                'phpUnit' => $qaConfig,
                'phpStan' => $qaConfig,
                'mago'    => $qaConfig,
                'logs'    => ['json' => $this->root . '/' . self::JSON_LOG],
            ],
            \Safe\json_decode(\Safe\file_get_contents($this->derivedConfigPath()), true),
            'the configDirs Infection would default to the project config directory are stated, not left to default to var/qa/',
        );
        self::assertStringContainsString('Infection: the ignored paths under its source directories are excluded through ' . $this->derivedConfigPath(), $this->factory->output->fetch());
    }

    #[Test]
    public function anIgnoredPathOutsideTheSourceDirectoriesExcludesNothing(): void
    {
        $this->factory->project->write(self::PROJECT_CONFIG, '{"source": {"directories": ["../src"]}}');
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willRun($this->infection());

        $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)->withIgnoredPaths('tests/assets')));

        $derived = \Safe\json_decode(\Safe\file_get_contents($this->derivedConfigPath()), true);
        self::assertIsArray($derived);
        self::assertSame(['directories' => [$this->root . self::SRC_DIR]], $derived['source']);
        self::assertStringNotContainsString('ignored paths', $this->factory->output->fetch());
    }

    #[Test]
    public function whenEverySourceDirectoryIsIgnoredTheLaneSkipsBeforeAnyCoverageIsGenerated(): void
    {
        $this->factory->project->write(self::PROJECT_CONFIG, '{"source": {"directories": ["../src"]}}');

        $result = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS, singleTool: self::INFECTION)->withIgnoredPaths('src')));

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame([], $this->factory->processes->specs, 'nothing to mutate, so no coverage run either');
        self::assertStringContainsString('every source directory in', $this->factory->output->fetch());
    }

    #[Test]
    public function anInfectionConfigThatIsNotJsonCrashesTheLane(): void
    {
        $this->factory->project->write(self::PROJECT_CONFIG, '{"source": ');

        $result = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)->withIgnoredPaths(self::LEGACY)));

        $printed = $this->factory->output->fetch();
        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame([], $this->factory->processes->specs);
        self::assertStringContainsString($this->root . '/' . self::PROJECT_CONFIG, $printed);
        self::assertStringContainsString(InfectionTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function diffModeLeavesOutAChangedFileUnderAnIgnoredPath(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes
            ->willSucceed(self::GIT_STATUS_CLEAN)
            ->willSucceed($this->nameStatus(['M', self::COMMITTED_SRC], ['M', 'src/Legacy/Old.php']))
            ->willRun($this->infection())
        ;

        $result  = $this->tool()->run($this->context($this->diffBuilder()->withIgnoredPaths(self::LEGACY)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('mutating 1 file(s): src/Committed.php', $printed);
        $command = $this->factory->processes->lastSpec()->command;
        self::assertSame([$this->root . '/' . self::COMMITTED_SRC], \array_slice($command, -1));
        self::assertNotContains($this->root . '/src/Legacy/Old.php', $command);
    }

    #[Test]
    public function diffModeSkipsWhenEveryChangedFileIsIgnored(): void
    {
        $this->factory->processes
            ->willSucceed(self::GIT_STATUS_CLEAN)
            ->willSucceed($this->nameStatus(['M', 'src/Legacy/Old.php']))
        ;

        $result = $this->tool()->run($this->context($this->diffBuilder()->withIgnoredPaths(self::LEGACY)));

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertCount(2, $this->factory->processes->specs, 'git status and git diff only: no coverage run');
    }

    #[Test]
    public function anOverriddenDiffFloorReachesInfection(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willSucceed(self::GIT_STATUS_CLEAN)->willSucceed($this->nameStatus(['M', 'src/Changed.php']))->willRun($this->infection());

        $this->tool()->run($this->context($this->diffBuilder(['infectionDiffCoveredMsi' => '95'])));

        self::assertContains('--min-covered-msi=95', $this->factory->processes->lastSpec()->command);
        self::assertNotContains(self::COVERED_FLOOR_ARG, $this->factory->processes->lastSpec()->command);
    }

    #[Test]
    public function anEmptyDiffSkips(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willSucceed(self::GIT_STATUS_CLEAN)->willSucceed($this->nameStatus(['M', 'src/README.md'], ['D', 'src/Gone.php']));

        $result  = $this->tool()->run($this->context($this->diffBuilder()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertCount(2, $this->factory->processes->specs);
        self::assertStringContainsString("no PHP source change, and no changed test named after a source file, against 'origin/main'; there are no new mutants to check. SKIPPING.", $printed);
        self::assertStringNotContainsString('full run', $printed, 'only a change with nothing mutable skips; a configuration change runs in full');
    }

    #[Test]
    public function anEmptyDiffSkipsBeforeAnyCoverageIsGenerated(): void
    {
        $this->factory->processes->willSucceed('')->willSucceed('')->willSucceed('');

        $result  = $this->tool()->run($this->context($this->diffBuilder(singleTool: self::INFECTION)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame(
            [
                'git status --porcelain=v1 -z --untracked-files=all -- ' . implode(' ', $this->pathspecs()),
                'git --no-pager diff origin/main...HEAD -z -M --name-status --diff-filter=AMRCD --relative -- ' . implode(' ', $this->pathspecs()),
            ],
            $this->factory->processes->commandLines(),
            'with nothing to mutate, the lane must not spend a coverage run first',
        );
        self::assertStringNotContainsString('generating fresh coverage', $printed);
        self::assertStringContainsString('there are no new mutants to check. SKIPPING.', $printed);
    }

    #[Test]
    public function aFailingGitDiffFailsTheLane(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willSucceed('')->willFail(128, 'fatal: bad revision');

        $result  = $this->tool()->run($this->context($this->diffBuilder()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString("'git diff' against base 'origin/main' failed (exit 128)", $printed);
        self::assertStringContainsString("'git fetch origin' first", $printed);
        self::assertStringContainsString(InfectionTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new InfectionTool();

        self::assertSame(self::INFECTION, $tool->name());
        self::assertSame('phpqaci.infection', $tool->identifier());
    }

    /**
     * Infection exiting $exitCode after writing the JSON log the config it was
     * handed asks for, one killed mutant per output (one real kill by default).
     * A config with no JSON log gets none written.
     *
     * @return Closure(ProcessSpecDto): ProcessResultDto
     */
    private function infection(int $exitCode = 0, string ...$killedOutputs): Closure
    {
        return $this->infectionWithEscaped($exitCode, 0, ...$killedOutputs);
    }

    /**
     * infection(), with $escaped mutants besides the killed ones, and the stats
     * Infection writes for that run: every mutant covered and tested, so both
     * scores are the killed share of them.
     *
     * @return Closure(ProcessSpecDto): ProcessResultDto
     */
    private function infectionWithEscaped(int $exitCode, int $escaped, string ...$killedOutputs): Closure
    {
        $killedOutputs = [] === $killedOutputs ? [self::REAL_KILL] : array_values($killedOutputs);
        $total         = \count($killedOutputs) + $escaped;
        $score         = round(100 * \count($killedOutputs) / $total, 2);
        $stats         = [
            'totalMutantsCount'            => $total,
            'killedCount'                  => \count($killedOutputs),
            'killedByStaticAnalysisCount'  => 0,
            'notCoveredCount'              => 0,
            'escapedCount'                 => $escaped,
            'errorCount'                   => 0,
            'syntaxErrorCount'             => 0,
            'skippedCount'                 => 0,
            'ignoredCount'                 => 0,
            'timeOutCount'                 => 0,
            'msi'                          => $score,
            'mutationCodeCoverage'         => 100,
            'coveredCodeMsi'               => $score,
        ];

        return function (ProcessSpecDto $spec) use ($exitCode, $killedOutputs, $stats): ProcessResultDto {
            $configArgs = array_values(array_filter($spec->command, static fn (string $arg): bool => str_starts_with($arg, self::CONFIGURATION_ARG)));
            self::assertCount(1, $configArgs);
            $config = \Safe\json_decode(\Safe\file_get_contents(substr($configArgs[0], \strlen(self::CONFIGURATION_ARG))), true);
            $log    = \is_array($config) && \is_array($config['logs'] ?? null) ? ($config['logs']['json'] ?? null) : null;
            if (\is_string($log)) {
                if (!is_dir(\dirname($log))) {
                    \Safe\mkdir(\dirname($log), 0o777, true);
                }

                \Safe\file_put_contents($log, \Safe\json_encode([
                    'stats'  => $stats,
                    'killed' => array_map(fn (int $index, string $output): array => $this->killed(7 + $index, $output), array_keys($killedOutputs), $killedOutputs),
                ]));
            }

            return new ProcessResultDto($exitCode, 'Infection ran', 'Infection ran');
        };
    }

    /** @return array<string, mixed> a killed mutant at src/Foo.php:$line as Infection's JSON log holds it */
    private function killed(int $line, string $output): array
    {
        return [
            'mutator'       => ['mutatorName' => 'Plus', 'originalFilePath' => $this->root . '/src/Foo.php', 'originalStartLine' => $line],
            'diff'          => '',
            'processOutput' => $output,
        ];
    }

    /** The line that points at the JSON log, which holds every judged mutant's output. */
    private function everyMutantLine(): string
    {
        return "\n           Every mutant, with its test output: " . $this->root . '/' . self::JSON_LOG . "\n";
    }

    /** The infection.json the lane hands Infection, always the copy under var/qa/. */
    private function derivedConfigPath(): string
    {
        return $this->root . '/var/qa/' . InfectionTool::DERIVED_CONFIG;
    }

    /** An environment-free lane, so a GITHUB_BASE_REF in the test runner's own environment cannot steer it. */
    private function tool(): InfectionTool
    {
        return new InfectionTool(environment: new EnvironmentReader([]));
    }

    /**
     * What the lane's git status and git diff are restricted to: the source and tests, then the
     * full-run triggers. The resolved configs are the shipped defaults, outside this project.
     *
     * @return list<string>
     */
    private function pathspecs(): array
    {
        return [
            $this->root . self::SRC_DIR,
            $this->root . self::TESTS_DIR,
            ...array_map(fn (string $name): string => $this->root . '/qaConfig/' . $name, [...InfectionFullRunTriggers::INFECTION_CONFIG_NAMES, ...InfectionFullRunTriggers::PHPUNIT_CONFIG_NAMES]),
        ];
    }

    /** @param list<string> ...$records `git diff -z --name-status` output for the given records */
    private function nameStatus(array ...$records): string
    {
        return implode('', array_map(static fn (array $record): string => implode("\0", $record) . "\0", $records));
    }

    /** @param array<string, string> $extraEnv */
    private function diffBuilder(array $extraEnv = [], ?string $singleTool = null): QaConfigBuilder
    {
        return $this->factory->builder(env: [...self::FLOORS, 'infectionDiffBase' => 'origin/main', ...$extraEnv], singleTool: $singleTool);
    }

    private function context(QaConfigBuilder $builder): ToolContext
    {
        return $this->factory->context($builder->build());
    }
}
