<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Pipeline\Lane\Infection\Dto\InfectionDiffFilterDto;
use LTS\PHPQA\Pipeline\Lane\Infection\InfectionArguments;
use LTS\PHPQA\Pipeline\Lane\Infection\InfectionDiffFilter;
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
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(ToolContext::class)]
#[Small]
final class InfectionToolTest extends TestCase
{
    private const string COVERAGE_XML_INDEX_XML = 'var/qa/phpunit_logs/coverage-xml/index.xml';

    private const string MINIMAL_XML = '<x/>';

    private const string INFECTION = 'infection';

    private const string PHP_VERSION = '8.5.10';

    private const array FLOORS = ['mutationScoreIndicator' => '74', 'coveredCodeMSI' => '76', 'infectionThreads' => '4'];

    private ContextFactory $factory;

    private string $root;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        $this->root    = $this->factory->project->path;
        $this->factory->project->write('var/qa/phpqa-no-xdebug.' . self::PHP_VERSION . '.ini', '');
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function withoutXdebugTheLaneSkips(): void
    {
        $result = new InfectionTool()->run($this->context($this->factory->builder(xdebug: false)));

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
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willSucceed('Mutation Score Indicator (MSI): 90%');

        $result  = new InfectionTool()->run($this->context($this->factory->builder(env: self::FLOORS)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('reusing the coverage produced by the phpunit step this run', $printed);
        self::assertStringNotContainsString('100% MSI floor', $printed);

        $phar = $this->factory->processes->lastSpec();
        self::assertSame([
            '/usr/bin/php', '-n', '-c', $this->root . '/var/qa/phpqa-no-xdebug.' . self::PHP_VERSION . '.ini', '-d', 'memory_limit=4G',
            '-f', \dirname(__DIR__, 4) . '/vendor-phar/infection.phar', '--',
            '--coverage=' . $this->root . '/var/qa/phpunit_logs',
            '--skip-initial-tests',
            '--threads=4',
            '--configuration=' . \dirname(__DIR__, 4) . '/configDefaults/generic/infection.json',
            '--min-msi=74',
            '--min-covered-msi=76',
            '--log-verbosity=all',
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
        $this->factory->processes->willSucceed('OK (3 tests)')->willSucceed(self::PHP_VERSION)->willSucceed();

        $result  = new InfectionTool()->run($this->context($this->factory->builder(env: self::FLOORS, singleTool: self::INFECTION)));
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
        self::assertCount(3, $this->factory->processes->specs);
    }

    #[Test]
    public function aFullRunWithNoCoverageOnDiskGeneratesIt(): void
    {
        $this->factory->processes->willSucceed()->willSucceed(self::PHP_VERSION)->willSucceed();

        $result = new InfectionTool()->run($this->context($this->factory->builder(env: self::FLOORS)));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('generating fresh coverage', $this->factory->output->fetch());
    }

    #[Test]
    public function aFailingCoverageRunFailsTheLaneBeforeInfectionRuns(): void
    {
        $this->factory->processes->willFail(1, 'FAILURES!');

        $result  = new InfectionTool()->run($this->context($this->factory->builder(env: self::FLOORS, singleTool: self::INFECTION)));
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
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willFail(1, 'MSI 50% is less than min MSI 74%');

        $result  = new InfectionTool()->run($this->context($this->factory->builder(env: self::FLOORS)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('Infection failed (exit 1)', $result->summary);
        self::assertStringContainsString(InfectionTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function aHundredPercentFullFloorPrintsTheAdvisory(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willSucceed();

        new InfectionTool()->run($this->context($this->factory->builder(env: ['mutationScoreIndicator' => '90', 'coveredCodeMSI' => '100'])));
        $printed = $this->factory->output->fetch();

        self::assertStringContainsString('Infection: a 100% MSI floor is in force for this run.', $printed);
        self::assertStringContainsString('An honest 90+% MSI is a healthy gate; a forced 100% is diminishing-returns busywork.', $printed);
        self::assertStringContainsString('->withInfectionFloors(msi, coveredMsi)    (full lane, qaConfig/qa.php)', $printed);
    }

    #[Test]
    public function diffModeRefusesADirtyWorkingTree(): void
    {
        $this->factory->processes->willSucceed("?? src/Dirty.php\n");

        $result  = new InfectionTool()->run($this->context($this->diffBuilder()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome, 'a dirty src/tests tree must REFUSE the diff lane');
        self::assertCount(1, $this->factory->processes->specs, 'nothing expensive runs after the refusal');
        self::assertSame(['git', 'status', '--porcelain', '--', $this->root . '/src', $this->root . '/tests'], $this->factory->processes->specs[0]->command);
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

        $result = new InfectionTool()->run($this->context($this->diffBuilder()));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString("'git status' failed; cannot verify the working tree is clean", $this->factory->output->fetch());
    }

    #[Test]
    public function diffModeAcceptsACleanTreeAndScopesToTheCommittedChange(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes
            ->willSucceed('')
            ->willSucceed("src/Committed.php\n")
            ->willSucceed(self::PHP_VERSION)->willSucceed()
        ;

        $result  = new InfectionTool()->run($this->context($this->diffBuilder()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            ['git', '--no-pager', 'diff', 'origin/main...HEAD', '--diff-filter=AM', '--name-only', '--relative', '--', $this->root . '/src'],
            $this->factory->processes->specs[1]->command,
            'the filter is computed from committed history (three-dot diff), never the working tree',
        );
        self::assertSame($this->root, $this->factory->processes->specs[1]->cwd);
        self::assertStringContainsString("scoping mutation to source files changed against 'origin/main' (committed history only)", $printed);
        self::assertStringContainsString('mutating only the changed files: src/Committed.php', $printed);
        self::assertStringContainsString('Infection: a 100% MSI floor is in force for this run.', $printed, 'the default diff floor of 100 triggers the advisory');

        $phar = $this->factory->processes->lastSpec();
        self::assertSame([
            '--coverage=' . $this->root . '/var/qa/phpunit_logs',
            '--skip-initial-tests',
            '--threads=4',
            '--configuration=' . \dirname(__DIR__, 4) . '/configDefaults/generic/infection.json',
            '--min-covered-msi=100',
            $this->root . '/src/Committed.php',
        ], \array_slice($phar->command, 9));
        self::assertTrue($phar->lowPriority);
    }

    #[Test]
    public function anOverriddenDiffFloorReachesInfectionAndSilencesTheAdvisory(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willSucceed('')->willSucceed("src/Changed.php\n")->willSucceed(self::PHP_VERSION)->willSucceed();

        new InfectionTool()->run($this->context($this->diffBuilder(['infectionDiffCoveredMsi' => '95'])));
        $printed = $this->factory->output->fetch();

        self::assertContains('--min-covered-msi=95', $this->factory->processes->lastSpec()->command);
        self::assertNotContains('--min-covered-msi=100', $this->factory->processes->lastSpec()->command);
        self::assertStringNotContainsString('100% MSI floor', $printed);
    }

    #[Test]
    public function anEmptyDiffSkips(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willSucceed('')->willSucceed("docs/README.md\n");

        $result = new InfectionTool()->run($this->context($this->diffBuilder()));

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertCount(2, $this->factory->processes->specs);
        self::assertStringContainsString("no committed PHP source changes against 'origin/main'; there are no new mutants to check. SKIPPING.", $this->factory->output->fetch());
    }

    #[Test]
    public function aFailingGitDiffFailsTheLane(): void
    {
        $this->factory->project->write(self::COVERAGE_XML_INDEX_XML, self::MINIMAL_XML);
        $this->factory->processes->willSucceed('')->willFail(128, 'fatal: bad revision');

        $result  = new InfectionTool()->run($this->context($this->diffBuilder()));
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

    /** @param array<string, string> $extraEnv */
    private function diffBuilder(array $extraEnv = []): QaConfigBuilder
    {
        return $this->factory->builder(env: [...self::FLOORS, 'infectionDiffBase' => 'origin/main', ...$extraEnv]);
    }

    private function context(QaConfigBuilder $builder): ToolContext
    {
        return $this->factory->context($builder->build());
    }
}
