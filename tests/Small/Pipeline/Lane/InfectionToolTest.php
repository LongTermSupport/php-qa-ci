<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

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
use LTS\PHPQA\Pipeline\Lane\InfectionTool;
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
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto::class)]
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
        $this->factory->processes->willSucceed('Mutation Score Indicator (MSI): 90%');

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
            self::CONFIGURATION_ARG . \dirname(__DIR__, 4) . '/configDefaults/generic/infection.json',
            self::FULL_FLOOR_ARG,
            self::COVERED_FLOOR_ARG,
            self::LOG_ALL,
        ], $phar->command);
        self::assertTrue($phar->lowPriority);
        self::assertTrue($phar->streamOutput);
        self::assertSame($this->root, $phar->cwd);

        self::assertDirectoryExists($this->root . '/var/qa/infection');
        self::assertSame([], $this->factory->project->files('var/qa/infection'), 'previous infection output is cleared');
        self::assertDirectoryDoesNotExist($this->root . '/var/qa/infection/tmp');
    }

    #[Test]
    public function aSingleToolRunGeneratesFreshCoverageWithXdebug(): void
    {
        $this->factory->project->write('var/qa/phpunit_logs/coverage-xml/stale.xml', self::MINIMAL_XML);
        $this->factory->processes->willSucceed('OK (3 tests)')->willSucceed();

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
        $this->factory->processes->willSucceed()->willSucceed();

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
            ->willSucceed()
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
            self::CONFIGURATION_ARG . \dirname(__DIR__, 4) . '/configDefaults/generic/infection.json',
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
            ->willSucceed()
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
        $this->factory->processes->willSucceed("main\n")->willSucceed(self::ORIGIN_DEFAULT)->willSucceed();

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
        $this->factory->processes->willSucceed(self::FEATURE_BRANCH)->willSucceed(self::ORIGIN_DEFAULT)->willSucceed(self::RESOLVED)->willFail(1)->willSucceed();

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
            ->willSucceed()
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
            ->willSucceed()
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
            ->willSucceed()
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
            ->willSucceed()
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
            ->willSucceed()
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
            ->willSucceed()
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
            ->willSucceed()
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
            ->willSucceed()
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
        $this->factory->processes->willSucceed(self::GIT_STATUS_CLEAN)->willSucceed($this->nameStatus(['M', 'tests/Small/Lane/ToolTest.php']))->willSucceed();

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
        $this->factory->processes->willSucceed();

        $result = $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)->withIgnoredPaths(self::LEGACY, 'tests/assets')));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertContains(self::CONFIGURATION_ARG . $this->root . '/var/qa/' . InfectionTool::DERIVED_CONFIG, $this->factory->processes->lastSpec()->command);
        $qaConfig = ['configDir' => $this->root . '/qaConfig'];
        self::assertSame(
            [
                'source'  => ['directories' => [$this->root . self::SRC_DIR], 'excludes' => ['#^Legacy(?:/|$)#']],
                'tmpDir'  => $this->root . '/var/qa/infection/tmp',
                'phpUnit' => $qaConfig,
                'phpStan' => $qaConfig,
                'mago'    => $qaConfig,
            ],
            \Safe\json_decode($this->factory->project->read('var/qa/' . InfectionTool::DERIVED_CONFIG), true),
            'the configDirs Infection would default to the project config directory are stated, not left to default to var/qa/',
        );
        self::assertStringContainsString('Infection: the ignored paths under its source directories are excluded through', $this->factory->output->fetch());
    }

    #[Test]
    public function anIgnoredPathOutsideTheSourceDirectoriesLeavesTheConfigAsResolved(): void
    {
        $config = $this->factory->project->write(self::PROJECT_CONFIG, '{"source": {"directories": ["../src"]}}');
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willSucceed();

        $this->tool()->run($this->context($this->factory->builder(env: self::FLOORS)->withIgnoredPaths('tests/assets')));

        self::assertContains(self::CONFIGURATION_ARG . $config, $this->factory->processes->lastSpec()->command);
        self::assertFileDoesNotExist($this->root . '/var/qa/' . InfectionTool::DERIVED_CONFIG);
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

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame([], $this->factory->processes->specs);
        self::assertStringContainsString($this->root . '/' . self::PROJECT_CONFIG, $this->factory->output->fetch());
    }

    #[Test]
    public function diffModeLeavesOutAChangedFileUnderAnIgnoredPath(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes
            ->willSucceed(self::GIT_STATUS_CLEAN)
            ->willSucceed($this->nameStatus(['M', self::COMMITTED_SRC], ['M', 'src/Legacy/Old.php']))
            ->willSucceed()
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
        $this->factory->processes->willSucceed(self::GIT_STATUS_CLEAN)->willSucceed($this->nameStatus(['M', 'src/Changed.php']))->willSucceed();

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
