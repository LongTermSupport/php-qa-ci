<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Agent\FileReportWriter;
use LTS\PHPQA\Pipeline\Lane\Phpstan\PhpstanCrash;
use LTS\PHPQA\Pipeline\Lane\PhpstanTool;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use LTS\PHPQA\Tests\Support\FixedTmpDirProbe;
use LTS\PHPQA\Tests\Support\FixedTurboProbe;
use LTS\PHPQA\Turbo\TurboStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * @internal
 */
#[CoversClass(PhpstanTool::class)]
#[UsesClass(PhpstanCrash::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\AgentStatusEnum::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\Dto\FileErrorDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\Dto\FileReportDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\Dto\ParsedReportDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\Exception\UnreadableReportException::class)]
#[UsesClass(FileReportWriter::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\PhpstanJsonParser::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\TerseReporter::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\IgnoredPaths::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\Phpstan\ExcludePathsNeon::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\Phpstan\TmpDirNeon::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\NeonIncludeChain::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonIncludeChainDto::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonRecordFileDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[UsesClass(TurboStatus::class)]
#[UsesClass(\LTS\PHPQA\Turbo\TurboStateEnum::class)]
#[UsesClass(\LTS\PHPQA\Turbo\TurboPlatform::class)]
#[UsesClass(\LTS\PHPQA\Turbo\TurboManifest::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\Phpstan\DiagnoseTurboProbe::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\Phpstan\DumpParametersTmpDirProbe::class)]
#[Small]
final class PhpstanToolTest extends TestCase
{
    private const string NO_PROGRESS = '--no-progress';

    private const string VAR_QA_PREFIX = 'var/qa/';

    private const string ANALYSE = 'analyse';

    private const string SRC = 'src';

    private const string KERNEL = 'src/Kernel.php';

    private const string CLEAN_REPORT = '{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}';

    private const string PHP_OPEN = "<?php\n";

    private const string CRASHED = 'crashed';

    private const string NO_ERRORS = "[OK] No errors\n";

    private const string PHAR_SCHEME = 'phar://';

    private const string NEON_LIST_ITEM = '        - ';

    private const string ASSETS = 'tests/assets';

    private const string INCOMPLETE_LINE = "⚠️  Result is incomplete because of severe errors. ⚠️\n";

    private const string FOUND_ONE = "\n [ERROR] Found 1 error\n";

    private const string CONFIG_ERROR = "Invalid configuration:\nUnexpected item 'parameters › notARealParameter'.\n";

    private const string INTERNAL_ERROR_OUTPUT =" Internal error: Unclosed '{' on line 49 while analysing file /p/tests/A.php\n\n [ERROR] Found 1 error\n\n" . self::INCOMPLETE_LINE;

    private const string TURBO_ENABLED = "Turbo extension: enabled (version 6351afb)\n";

    private const string PHPSTAN_PHAR = '/vendor-phar/phpstan.phar';

    private const string PHARS = 'phars';

    private const string ARKITECT_PHAR = '/phparkitect.phar';

    private const string CDA_PHAR = '/composer-dependency-analyser.phar';

    private const string JSON_FORMAT = '--error-format=json';

    private ContextFactory $factory;

    private FixedTurboProbe $turbo;

    private FixedTmpDirProbe $tmpDirProbe;

    protected function setUp(): void
    {
        $this->factory     = ContextFactory::create();
        $this->turbo       = new FixedTurboProbe(TurboStatus::fromDiagnose(self::TURBO_ENABLED, true));
        $this->tmpDirProbe = new FixedTmpDirProbe();
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    /** Text mode says whether PHPStan is running with Turbo before it analyses, asked about the lane's own wrapper. */
    #[Test]
    public function textModeReportsWhetherTurboIsRunning(): void
    {
        $this->factory->processes->willSucceed(self::NO_ERRORS);

        $this->tool()->run($this->factory->context($this->factory->builder(ci: true)->build()));

        self::assertStringContainsString('PHPStan Turbo: enabled (version 6351afb)', $this->factory->output->fetch());
        self::assertSame([$this->factory->project->path . '/' . self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON], $this->turbo->askedAbout);
    }

    /**
     * A host php-qa-ci ships a build for, where Turbo is not running, is reported loudly. Whether
     * it also fails the lane is Plan 00020's decision D2; until it is taken the outcome is the
     * analysis's own.
     */
    #[Test]
    public function aMissingTurboIsReportedAndTheAnalysisDecidesTheOutcome(): void
    {
        $this->turbo = new FixedTurboProbe(TurboStatus::fromDiagnose("Turbo extension: not loaded\n", true));
        $this->factory->processes->willSucceed(self::NO_ERRORS);

        $result = $this->tool()->run($this->factory->context($this->factory->builder(ci: true)->build()));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('PHPStan Turbo: NOT RUNNING', $this->factory->output->fetch());
    }

    /** JSON and agent mode are machine output for single-path loops; the extra PHPStan start is not paid there. */
    #[Test]
    public function jsonAndAgentModesDoNotAskAboutTurbo(): void
    {
        $this->factory->processes->willSucceed(self::CLEAN_REPORT);
        $this->tool()->run($this->factory->context($this->factory->builder(jsonOutput: true)->build()));

        $this->factory->processes->willSucceed(self::CLEAN_REPORT);
        $this->tool()->run($this->factory->context($this->factory->builder(agentMode: true, specifiedPath: self::KERNEL)->build()));

        self::assertSame([], $this->turbo->askedAbout);
    }

    /**
     * The shipped lane (ShippedTools builds it with no arguments) asks the phar itself: for the
     * merged tmpDir through dump-parameters, then for Turbo through diagnose.
     */
    #[Test]
    public function theShippedLaneAsksThePharThroughDumpParametersAndDiagnose(): void
    {
        $this->factory->processes->willSucceed('{"tmpDir":"/t/phpstan","sysGetTempDir":"/t"}');
        $this->factory->processes->willSucceed(self::TURBO_ENABLED);
        $this->factory->processes->willSucceed(self::NO_ERRORS);

        new PhpstanTool()->run($this->factory->context($this->factory->builder(ci: true)->build()));

        self::assertSame('dump-parameters', $this->toolArgs($this->factory->processes->specs[0])[0]);
        self::assertSame('diagnose', $this->toolArgs($this->factory->processes->specs[1])[0]);
        self::assertSame(self::ANALYSE, $this->toolArgs($this->factory->processes->lastSpec())[0]);
        self::assertStringContainsString('PHPStan Turbo: enabled (version 6351afb)', $this->factory->output->fetch());
    }

    #[Test]
    public function aCleanRunWritesTheWrapperNeonRunsThePharAndArchivesTheLog(): void
    {
        $this->factory->processes->willSucceed(self::NO_ERRORS);
        $config = $this->factory->builder(ci: true)->build();

        $result  = $this->tool()->run($this->factory->context($config));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('PHPStan: limiting to 2 parallel processes (50% of cores)', $printed);

        $logDir  = $this->factory->project->path . '/' . self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR;
        $wrapper = $logDir . '/' . PhpstanTool::WRAPPER_NEON;
        self::assertStringStartsWith(
            "includes:\n    - " . \dirname(__DIR__, 4) . "/configDefaults/generic/phpstan.neon\n\nparameters:\n    parallel:\n        maximumNumberOfProcesses: 2\n",
            \Safe\file_get_contents($wrapper),
        );

        $spec = $this->factory->processes->lastSpec();
        self::assertSame(
            [self::ANALYSE, ...$config->pathsToCheck, '-c', $wrapper, PhpstanCrash::TABLE_FORMAT, self::NO_PROGRESS],
            $this->toolArgs($spec),
            'the table format is named, so a project errorFormat cannot take away the summary line the verdict is read from',
        );
        self::assertSame(\dirname(__DIR__, 4) . self::PHPSTAN_PHAR, $this->script($spec));
        self::assertSame($this->factory->project->path, $spec->cwd);
        self::assertTrue($spec->streamOutput);

        self::assertSame(self::NO_ERRORS, \Safe\file_get_contents($logDir . '/' . PhpstanTool::LOG_FILE));
        $archived = array_filter($this->factory->project->files(self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR), static fn (string $f): bool => 1 === \Safe\preg_match('/^phpstan\.\d{8}-\d{6}\.log$/', $f));
        self::assertCount(1, $archived, 'the text log is archived with a timestamp');
        self::assertStringContainsString('Full test suite run', $printed);
        self::assertSame('', $this->factory->stdout->fetch(), 'text mode never touches the real stdout');
    }

    #[Test]
    public function theWrapperLetsPhpstanDiscoverThePharToolsConfigApis(): void
    {
        // qaConfig/phparkitect.php and qaConfig/composer-dependency-analyser.php
        // name classes that live only inside the matching phar, so without this
        // every project's per-file run on those files is red for ever with
        // class.notFound errors nobody can act on.
        $this->factory->processes->willSucceed(self::NO_ERRORS);

        $this->tool()->run($this->factory->context($this->factory->builder(ci: true)->build()));

        $wrapper = $this->factory->project->read(self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON);
        self::assertStringContainsString("    scanDirectories:\n", $wrapper);
        self::assertStringContainsString(self::PHAR_SCHEME . \dirname(__DIR__, 4) . "/vendor-phar/phparkitect.phar/src\n", $wrapper);
        self::assertStringContainsString(self::PHAR_SCHEME . \dirname(__DIR__, 4) . '/vendor-phar/composer-dependency-analyser.phar/', $wrapper);
    }

    #[Test]
    public function theScannedDirectoriesComeFromEachPharsOwnAutoloadMap(): void
    {
        // Reading the phar's map rather than hardcoding its layout is what keeps
        // this correct when a phar is rebuilt with its sources somewhere else.
        $this->factory->processes->willSucceed(self::NO_ERRORS);

        $this->tool()->run($this->factory->context($this->factory->builder(ci: true)->build()));

        $wrapper = $this->factory->project->read(self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON);
        foreach (['phparkitect.phar', 'composer-dependency-analyser.phar'] as $phar) {
            $map = self::PHAR_SCHEME . \dirname(__DIR__, 4) . '/vendor-phar/' . $phar . '/vendor/composer/autoload_psr4.php';
            self::assertFileExists($map, 'the map this lane reads must exist in the shipped phar');
        }

        foreach ($this->scannedDirectories($wrapper) as $directory) {
            self::assertDirectoryExists($directory, 'a scanned directory that does not exist would make PHPStan crash');
        }
    }

    /**
     * A file named like a config-API phar that is not a phar has no autoload map to read, and
     * the phar after it is still read.
     */
    #[Test]
    public function aPharWithNoReadableAutoloadMapIsPassedOver(): void
    {
        $pharDir = $this->factory->project->mkdir(self::PHARS);
        $this->factory->project->write('phars/phparkitect.phar', 'not a phar');
        $this->buildMapPhar($pharDir . self::CDA_PHAR, ['ShipMonk\ComposerDependencyAnalyser\\' => [$pharDir]]);

        self::assertSame([$pharDir], $this->scannedDirectoriesWith($pharDir));
    }

    /** A map that does not declare the config API's prefix contributes nothing, and the next phar is still read. */
    #[Test]
    public function aMapWithoutTheConfigApiPrefixIsPassedOver(): void
    {
        $pharDir = $this->factory->project->mkdir(self::PHARS);
        $this->buildMapPhar($pharDir . self::ARKITECT_PHAR, ['Other\\' => [$pharDir]]);
        $this->buildMapPhar($pharDir . self::CDA_PHAR, ['ShipMonk\ComposerDependencyAnalyser\\' => [$pharDir]]);

        self::assertSame([$pharDir], $this->scannedDirectoriesWith($pharDir));
    }

    #[Test]
    public function aPrefixMappedToSomethingOtherThanAListIsPassedOver(): void
    {
        $pharDir = $this->factory->project->mkdir(self::PHARS);
        $this->buildMapPhar($pharDir . self::ARKITECT_PHAR, ['Arkitect\\' => $pharDir]);
        $this->buildMapPhar($pharDir . self::CDA_PHAR, ['ShipMonk\ComposerDependencyAnalyser\\' => [$pharDir]]);

        self::assertSame([$pharDir], $this->scannedDirectoriesWith($pharDir));
    }

    /** Only a directory that exists is scanned: PHPStan crashes on a scanDirectories entry that does not. */
    #[Test]
    public function onlyExistingDirectoriesFromTheMapAreScanned(): void
    {
        $pharDir = $this->factory->project->mkdir(self::PHARS);
        $this->buildMapPhar($pharDir . self::ARKITECT_PHAR, ['Arkitect\\' => [$pharDir . '/gone', 42, $pharDir]]);

        self::assertSame([$pharDir], $this->scannedDirectoriesWith($pharDir));
    }

    #[Test]
    public function theProjectOverrideNeonIsIncludedWhenPresent(): void
    {
        $override = $this->factory->project->write('qaConfig/phpstan.neon', "parameters:\n    level: max\n");
        $this->factory->processes->willSucceed();

        $this->tool()->run($this->factory->context());

        self::assertStringContainsString('    - ' . $override . "\n", $this->factory->project->read(self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON));
    }

    /**
     * PHPStan's default tmpDir is sys_get_temp_dir()/phpstan, which every checkout on the host
     * shares; a stale entry there once failed a clean tree in one worktree only (#123).
     */
    #[Test]
    public function theCacheLivesInsideTheProjectRatherThanTheHostsSharedTempDir(): void
    {
        $this->factory->processes->willSucceed(self::NO_ERRORS);

        $this->tool()->run($this->factory->context($this->factory->builder(ci: true)->build()));

        $cacheDir = $this->factory->project->path . '/' . self::VAR_QA_PREFIX . 'cache/phpstan';
        self::assertStringContainsString(
            "    tmpDir: '" . $cacheDir . "'\n",
            $this->factory->project->read(self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON),
        );
        self::assertDirectoryExists($cacheDir);
        self::assertStringContainsString('PHPStan: cache in var/qa/cache/phpstan', $this->factory->output->fetch());
    }

    /** A tmpDir the project's own config sets is its decision; the wrapper must not override it. */
    #[Test]
    public function aTmpDirTheProjectSetsItselfIsLeftInPlace(): void
    {
        $this->factory->project->write('qaConfig/phpstan.neon', "parameters:\n    tmpDir: /ci-cache/phpstan\n");
        $this->factory->processes->willSucceed(self::NO_ERRORS);

        $this->tool()->run($this->factory->context($this->factory->builder(ci: true)->build()));

        self::assertStringNotContainsString(
            'tmpDir',
            $this->factory->project->read(self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON),
        );
        self::assertDirectoryDoesNotExist($this->factory->project->path . '/' . self::VAR_QA_PREFIX . 'cache/phpstan');
        self::assertStringContainsString('PHPStan: cache in the tmpDir qaConfig/phpstan.neon sets', $this->factory->output->fetch());
        self::assertSame([], $this->tmpDirProbe->askedAbout, 'the walk named the file, so PHPStan is not asked');
    }

    /**
     * A tmpDir the walk cannot reach (behind a `%parameter%` or PHP include) is still the
     * project's when PHPStan resolves one other than its default (#140).
     */
    #[Test]
    public function aTmpDirOnlyPhpstanCanResolveIsLeftInPlace(): void
    {
        $this->tmpDirProbe = new FixedTmpDirProbe('/ci-cache/phpstan');
        $this->factory->processes->willSucceed(self::NO_ERRORS);
        $context = $this->factory->context($this->factory->builder(ci: true)->build());

        $this->tool()->run($context);

        self::assertStringNotContainsString(
            'tmpDir',
            $this->factory->project->read(self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON),
        );
        self::assertStringContainsString("PHPStan: cache in the tmpDir the project's PHPStan configuration sets, /ci-cache/phpstan", $this->factory->output->fetch());
        self::assertSame([[$context->configPath('phpstan.neon'), null]], $this->tmpDirProbe->askedAbout, 'PHPStan is asked about the resolved config, with no autoload file');
    }

    #[Test]
    public function theIgnoredPathsAreExcludedFromTheReportInTheWrapperNeon(): void
    {
        $this->factory->processes->willSucceed();
        $root   = $this->factory->project->path;
        $config = $this->factory->builder()->withIgnoredPaths(self::ASSETS, 'src/Generated')->build();

        $this->tool()->run($this->factory->context($config));

        self::assertStringEndsWith(
            "    excludePaths:\n        analyse:\n            - '" . $root . "/tests/assets' (?)\n            - '" . $root . "/src/Generated' (?)\n",
            $this->factory->project->read(self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON),
        );
    }

    #[Test]
    public function aSinglePathRunInsideAnIgnoredPathIsSkippedWithoutRunningPhpstan(): void
    {
        $config = $this->factory->builder(specifiedPath: 'tests/assets/Fixture.php')->withIgnoredPaths(self::ASSETS)->build();

        $result = $this->tool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame('tests/assets/Fixture.php is under an ignored path (withIgnoredPaths in qaConfig/qa.php), so there is nothing to analyse', $result->summary);
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function aSinglePathRunAboveAnIgnoredPathStillRuns(): void
    {
        $this->factory->processes->willSucceed();
        $config = $this->factory->builder(specifiedPath: 'tests')->withIgnoredPaths(self::ASSETS)->build();

        $result = $this->tool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertCount(1, $this->factory->processes->specs);
    }

    #[Test]
    public function noIgnoredPathWritesNoExcludePaths(): void
    {
        $this->factory->processes->willSucceed();

        $this->tool()->run($this->factory->context());

        self::assertStringNotContainsString('excludePaths', $this->factory->project->read(self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON));
    }

    #[Test]
    public function noTypeCoverageBlockIsWrittenWhenNoFloorIsSet(): void
    {
        $this->factory->processes->willSucceed();

        $this->tool()->run($this->factory->context());

        self::assertStringNotContainsString(
            'type_coverage',
            $this->factory->project->read(self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON),
            'the extension must do nothing until a project opts in',
        );
    }

    #[Test]
    public function onlyTheTypeCoverageFloorsActuallySetAreWrittenToTheWrapperNeon(): void
    {
        $this->factory->processes->willSucceed();
        $config = $this->factory->builder()->withTypeCoverageFloors(returnType: 50, constantType: 100)->build();

        $this->tool()->run($this->factory->context($config));

        $wrapper = $this->factory->project->read(self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON);
        self::assertStringStartsWith("includes:\n", $wrapper, 'the floors are added to the wrapper, not written over it');
        self::assertStringContainsString("    type_coverage:\n        return_type: 50\n        constant_type: 100\n", $wrapper);
        self::assertStringNotContainsString('param_type', $wrapper, 'an unset floor must not be written as zero');
        self::assertStringNotContainsString('property_type', $wrapper);
    }

    #[Test]
    public function withTypeCoverageOnTheWholeProjectThePathsMoveIntoTheConfigAndOffTheCommandLine(): void
    {
        $this->factory->processes->willSucceed();
        $config = $this->factory->builder()->withTypeCoverageFloors(returnType: 50)->build();

        $this->tool()->run($this->factory->context($config));

        $wrapper = $this->factory->project->path . '/' . self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON;
        self::assertSame(
            [self::ANALYSE, '-c', $wrapper, PhpstanCrash::TABLE_FORMAT, self::NO_PROGRESS],
            $this->toolArgs($this->factory->processes->lastSpec()),
            'type-coverage reports nothing when the analysed paths differ from the configured ones',
        );
        foreach ($config->pathsToCheck as $path) {
            self::assertStringContainsString(self::NEON_LIST_ITEM . $path . "\n", \Safe\file_get_contents($wrapper));
        }
    }

    #[Test]
    public function aSinglePathRunKeepsTheCommandLineFormEvenWithTypeCoverageOn(): void
    {
        $this->factory->processes->willSucceed();
        $config = $this->factory->builder(specifiedPath: self::SRC)->withTypeCoverageFloors(returnType: 50)->build();

        $this->tool()->run($this->factory->context($config));

        $wrapper = $this->factory->project->path . '/' . self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON;
        self::assertSame(
            [self::ANALYSE, ...$config->pathsToCheck, '-c', $wrapper, PhpstanCrash::TABLE_FORMAT, self::NO_PROGRESS],
            $this->toolArgs($this->factory->processes->lastSpec()),
        );
        self::assertStringNotContainsString('    paths:', \Safe\file_get_contents($wrapper), 'a subset must not masquerade as the whole project');
    }

    #[Test]
    public function anInteractiveRunKeepsTheProgressBar(): void
    {
        $this->factory->processes->willSucceed();

        $this->tool()->run($this->factory->context($this->factory->builder(ci: false)->build()));

        self::assertNotContains(self::NO_PROGRESS, $this->toolArgs($this->factory->processes->lastSpec()));
    }

    #[Test]
    public function errorsFoundFailWithTheIdentifierAndNoTautologyNote(): void
    {
        $this->factory->processes->willFail(1, " 12  Method foo() has no return type specified.\n" . self::FOUND_ONE);

        $result  = $this->tool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString(PhpstanTool::IDENTIFIER, $printed);
        self::assertStringNotContainsString('possible tautology', $printed);
        self::assertCount(1, $this->factory->processes->specs, 'no debug re-run for a plain failure');
    }

    #[Test]
    public function aTautologyIdentifierInTheOutputPrintsTheNote(): void
    {
        $this->factory->processes->willFail(1, "Call to method assertTrue() with true will always evaluate to true.\n  🪪 method.alreadyNarrowedType\n" . self::FOUND_ONE);

        $result  = $this->tool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertMatchesRegularExpression('/\.log\n\nNOTE — possible tautology from stronger types\n/', $printed, 'a blank line separates the note from the log line above');
        self::assertStringContainsString('DELETING the now-redundant assertion', $printed);
        self::assertStringContainsString('Do NOT silence it with treatPhpDocTypesAsCertain:false', $printed);
        self::assertStringContainsString(PhpstanTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function aCrashIsReRunWithDebugAndReportedAsCrashed(): void
    {
        $this->factory->processes->willFail(255, "PHP Fatal error: boom\n");
        $this->factory->processes->willFail(255, "debug output\n");

        $config = $this->factory->builder(ci: true)->build();

        $result  = $this->tool()->run($this->factory->context($config));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertStringEndsWith(
            "\n\n\nPHPStan Crashed!!....\n\nrunning again with debug mode:\nWhere ever it stops is probably a fatal PHP error\n\n",
            $printed,
            'the banner is set off by blank lines from the log line above and the debug output below',
        );
        self::assertStringNotContainsString(PhpstanTool::IDENTIFIER, $printed);

        $wrapper = $this->factory->project->path . '/' . self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON;
        self::assertCount(2, $this->factory->processes->specs);
        self::assertSame(
            [self::ANALYSE, ...$config->pathsToCheck, '-c', $wrapper, '--debug', '-v'],
            $this->toolArgs($this->factory->processes->lastSpec()),
            'the debug re-run drops --no-progress and adds --debug -v',
        );
    }

    /**
     * PHPStan exits 1 both for findings and for an analysis it abandoned on internal errors, and
     * an abandoned analysis drops every real finding (#82). Its stderr line is the difference.
     */
    #[Test]
    public function anIncompleteResultIsACrashAndIsReRunWithDebug(): void
    {
        $this->factory->processes->willFail(1, self::INTERNAL_ERROR_OUTPUT);
        $this->factory->processes->willFail(1, "debug output\n");

        $result  = $this->tool()->run($this->factory->context($this->factory->builder(ci: true)->build()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertStringContainsString('PHPStan Crashed!!....', $printed);
        self::assertStringNotContainsString(PhpstanTool::IDENTIFIER, $printed);
        self::assertCount(2, $this->factory->processes->specs, 'an abandoned analysis gets the same debug re-run as any crash');
    }

    /** A config error also exits 1, before anything is analysed: there are no findings to fix. */
    #[Test]
    public function aConfigurationErrorIsACrashNotFindings(): void
    {
        $this->factory->processes->willFail(1, self::CONFIG_ERROR);
        $this->factory->processes->willFail(1, self::CONFIG_ERROR);

        $result  = $this->tool()->run($this->factory->context($this->factory->builder(ci: true)->build()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame(PhpstanCrash::NO_REPORT_REASON, $result->summary);
        self::assertStringNotContainsString(PhpstanTool::IDENTIFIER, $printed);
        self::assertCount(2, $this->factory->processes->specs, 'the debug re-run, as for any crash');
    }

    #[Test]
    public function jsonModeAConfigurationErrorIsACrash(): void
    {
        $this->factory->processes->willFail(1, self::CONFIG_ERROR);
        $config = $this->factory->builder(jsonOutput: true, specifiedPath: self::SRC)->build();

        $result = $this->tool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame(PhpstanCrash::NO_REPORT_REASON, $result->summary);
        $printed = $this->factory->output->fetch();
        self::assertStringNotContainsString(PhpstanTool::IDENTIFIER, $printed);
        self::assertStringContainsString(PhpstanCrash::noVerdictLine(PhpstanCrash::NO_REPORT_REASON), $printed, 'bin/phpstan-rule reads this line to refuse an answer');
    }

    #[Test]
    public function jsonModeWritesOnlyTheProcessStdoutSoStderrNoiseNeverCorruptsTheReport(): void
    {
        $json  = self::CLEAN_REPORT;
        $noise = "PHP Warning:  Module \"xml\" is already loaded in Unknown on line 0\n";
        $this->factory->processes->willReturn(new ProcessResultDto(0, $noise . $json, $json));
        $config = $this->factory->builder(jsonOutput: true, specifiedPath: self::SRC)->build();

        $result = $this->tool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame($json, $this->factory->stdout->fetch());
    }

    #[Test]
    public function jsonModeWritesTheReportToStdoutOnlyAndNeverRetries(): void
    {
        $json = '{"totals":{"errors":0,"file_errors":1},"files":{},"errors":[]}';
        $this->factory->processes->willFail(1, $json);
        $config = $this->factory->builder(ci: false, jsonOutput: true, specifiedPath: self::SRC)->build();

        $result = $this->tool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame($json, $this->factory->stdout->fetch());
        $printed = $this->factory->output->fetch();
        self::assertStringNotContainsString('"totals"', $printed, 'the JSON never reaches the decoration output');
        self::assertStringContainsString(PhpstanTool::IDENTIFIER, $printed);
        self::assertStringContainsString('Path-specific run', $printed);

        $spec = $this->factory->processes->lastSpec();
        self::assertFalse($spec->streamOutput);
        self::assertSame(\dirname(__DIR__, 4) . self::PHPSTAN_PHAR, $this->script($spec));
        self::assertSame(
            [self::ANALYSE, ...$config->pathsToCheck, '-c', $this->factory->project->path . '/' . self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON, self::NO_PROGRESS, self::JSON_FORMAT],
            $this->toolArgs($spec),
            'json mode always suppresses progress, even interactively',
        );
        self::assertCount(1, $this->factory->processes->specs, 'no re-run of any kind in json mode');

        $logDir = self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR;
        self::assertSame($json, $this->factory->project->read($logDir . '/' . PhpstanTool::JSON_FILE));
        $archived = array_filter($this->factory->project->files($logDir), static fn (string $f): bool => 1 === \Safe\preg_match('/^phpstan\..*_src\.\d{8}-\d{6}\.json$/', $f));
        self::assertCount(1, $archived, 'the json report is archived under the path-specific pattern');
    }

    #[Test]
    public function jsonModeCleanRunPasses(): void
    {
        $this->factory->processes->willSucceed('{"totals":{"errors":0,"file_errors":0}}');

        $result = $this->tool()->run($this->factory->context($this->factory->builder(jsonOutput: true)->build()));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame('{"totals":{"errors":0,"file_errors":0}}', $this->factory->stdout->fetch());
    }

    #[Test]
    public function jsonModeCrashIsReportedWithoutADebugReRun(): void
    {
        $this->factory->processes->willFail(255, 'Fatal');

        $result = $this->tool()->run($this->factory->context($this->factory->builder(jsonOutput: true)->build()));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertStringContainsString(PhpstanCrash::noVerdictLine('PHPStan crashed (exit 255)'), $this->factory->output->fetch());
        self::assertCount(1, $this->factory->processes->specs);
    }

    #[Test]
    public function jsonModeIncompleteResultIsACrash(): void
    {
        $json = '{"totals":{"errors":1,"file_errors":0},"files":{},"errors":["Internal error: boom while analysing file /p/src/A.php"]}';
        $this->factory->processes->willReturn(new ProcessResultDto(1, $json . "\n" . self::INCOMPLETE_LINE, $json));

        $result = $this->tool()->run($this->factory->context($this->factory->builder(jsonOutput: true)->build()));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame($json, $this->factory->stdout->fetch(), 'the report still reaches stdout for the caller to read');
        self::assertStringContainsString(PhpstanCrash::noVerdictLine(PhpstanCrash::INCOMPLETE_REASON), $this->factory->output->fetch(), 'so a caller reading the report also learns it is not a verdict');
        self::assertCount(1, $this->factory->processes->specs);
    }

    #[Test]
    public function agentModeAsksPhpstanForJsonAndKeepsStdoutToThreeLines(): void
    {
        $file = $this->factory->project->write(self::KERNEL, self::PHP_OPEN);
        $this->factory->processes->willFail(1, $this->reportFor($file, 2));
        $config = $this->factory->builder(agentMode: true, specifiedPath: self::KERNEL)->build();

        $result = $this->tool()->run($this->factory->context($config));
        $lines  = $this->stdoutLines();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertCount(3, $lines);
        self::assertSame('PHPSTAN AGENT MODE: 2 errors in 1 file', $lines[0]);
        self::assertSame('REPORT: ' . $this->reportPath(self::KERNEL), $lines[1]);
        self::assertStringContainsString('ACTION REQUIRED', $lines[2]);
        self::assertContains(self::JSON_FORMAT, $this->toolArgs($this->factory->processes->lastSpec()));
    }

    /** The analysis is the text-mode one with JSON asked for; the raw report is kept beside the per-file ones. */
    #[Test]
    public function agentModeRunsThePharOverTheSameAnalysisAndKeepsTheRawReport(): void
    {
        $file = $this->factory->project->write(self::KERNEL, self::PHP_OPEN);
        $json = $this->reportFor($file, 1);
        $this->factory->processes->willFail(1, $json);
        $config = $this->factory->builder(agentMode: true, specifiedPath: self::KERNEL)->build();

        $this->tool()->run($this->factory->context($config));

        $logDir = $this->factory->project->path . '/' . self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR;
        $spec   = $this->factory->processes->lastSpec();
        self::assertSame(\dirname(__DIR__, 4) . self::PHPSTAN_PHAR, $this->script($spec));
        self::assertSame(
            [self::ANALYSE, ...$config->pathsToCheck, '-c', $logDir . '/' . PhpstanTool::WRAPPER_NEON, self::NO_PROGRESS, self::JSON_FORMAT],
            $this->toolArgs($spec),
        );
        self::assertSame($this->factory->project->path, $spec->cwd);
        self::assertFalse($spec->streamOutput, 'agent mode keeps the JSON off the terminal');
        self::assertSame($json, \Safe\file_get_contents($logDir . '/' . PhpstanTool::JSON_FILE));
        self::assertSame($logDir . '/' . PhpstanTool::JSON_FILE, $this->decode($this->reportPath(self::KERNEL))['log_path']);
    }

    #[Test]
    public function agentModeWritesThePerFileReportAtThePathThatMirrorsTheSourceFile(): void
    {
        $file = $this->factory->project->write(self::KERNEL, self::PHP_OPEN);
        $this->factory->processes->willFail(1, $this->reportFor($file, 1));

        $this->tool()->run($this->factory->context($this->factory->builder(agentMode: true, specifiedPath: self::KERNEL)->build()));

        $report = $this->decode($this->reportPath(self::KERNEL));
        self::assertSame('phpstan', $report['tool']);
        self::assertSame(self::KERNEL, $report['path']);
        self::assertSame('errors', $report['status']);
        self::assertSame(1, $report['error_count']);
        self::assertSame(1, $report['exit_code']);
        $errors = $report['errors'];
        self::assertIsArray($errors);
        $first = $errors[0];
        self::assertIsArray($first);
        self::assertSame(11, $first['line']);
        self::assertSame('missingType.return', $first['identifier']);
        $logPath = $report['log_path'];
        self::assertIsString($logPath);
        self::assertStringEndsWith(PhpstanTool::JSON_FILE, $logPath);
    }

    #[Test]
    public function aCleanAgentRunWritesZeroForTheAnalysedFileSoAFixedErrorCannotStayRed(): void
    {
        $file = $this->factory->project->write(self::KERNEL, self::PHP_OPEN);

        $this->factory->processes->willFail(1, $this->reportFor($file, 3));
        $this->tool()->run($this->factory->context($this->factory->builder(agentMode: true, specifiedPath: self::KERNEL)->build()));
        self::assertSame(3, $this->decode($this->reportPath(self::KERNEL))['error_count']);
        $this->factory->stdout->fetch();

        $this->factory->processes->willSucceed(self::CLEAN_REPORT);

        $result = $this->tool()->run($this->factory->context($this->factory->builder(agentMode: true, specifiedPath: self::KERNEL)->build()));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        $report = $this->decode($this->reportPath(self::KERNEL));
        self::assertSame(0, $report['error_count']);
        self::assertSame('clean', $report['status']);
        self::assertSame([], $report['errors']);
        self::assertSame('PHPSTAN AGENT MODE: 0 errors in 1 file', $this->stdoutLines()[0]);
    }

    #[Test]
    public function aWholeProjectAgentRunWritesOneReportPerFailingFileAndPointsAtTheIndex(): void
    {
        $kernel = $this->factory->project->write(self::KERNEL, self::PHP_OPEN);
        $other  = $this->factory->project->write('src/Other.php', self::PHP_OPEN);
        $json   = '{"totals":{"errors":0,"file_errors":3},"files":{'
            . '"' . $kernel . '":{"errors":2,"messages":[{"message":"a","line":1,"identifier":"i.a"},{"message":"b","line":2,"identifier":"i.b"}]},'
            . '"' . $other . '":{"errors":1,"messages":[{"message":"c","line":3,"identifier":"i.c"}]}'
            . '},"errors":[]}';
        $this->factory->processes->willFail(1, $json);

        $this->tool()->run($this->factory->context($this->factory->builder(agentMode: true)->build()));

        self::assertSame(2, $this->decode($this->reportPath(self::KERNEL))['error_count']);
        self::assertSame(1, $this->decode($this->reportPath('src/Other.php'))['error_count']);

        $lines = $this->stdoutLines();
        self::assertSame('PHPSTAN AGENT MODE: 3 errors in 2 files', $lines[0]);
        self::assertStringEndsWith(FileReportWriter::INDEX_FILE, $lines[1], 'one index path serves however many files had findings');

        $index = $this->decode($this->reportsDir() . '/' . FileReportWriter::INDEX_FILE);
        self::assertSame(2, $index['file_count']);
        self::assertSame(3, $index['error_count']);
    }

    #[Test]
    public function aWholeProjectAgentRunClearsEveryStaleReportBeforeWritingItsOwn(): void
    {
        $kernel = $this->factory->project->write(self::KERNEL, self::PHP_OPEN);
        $this->factory->processes->willFail(1, $this->reportFor($kernel, 1));
        $this->tool()->run($this->factory->context($this->factory->builder(agentMode: true)->build()));
        self::assertFileExists($this->reportPath(self::KERNEL));
        $this->factory->stdout->fetch();

        $this->factory->processes->willSucceed(self::CLEAN_REPORT);
        $this->tool()->run($this->factory->context($this->factory->builder(agentMode: true)->build()));

        self::assertFileDoesNotExist($this->reportPath(self::KERNEL), 'an absent report must mean clean, so the stale one has to go');
        self::assertSame('PHPSTAN AGENT MODE: 0 errors in 0 files', $this->stdoutLines()[0]);
    }

    #[Test]
    public function agentModeNeverPrintsTheTautologyNoteOrTheIdentifierTrailerToStdout(): void
    {
        $file = $this->factory->project->write(self::KERNEL, self::PHP_OPEN);
        $json = '{"totals":{"errors":0,"file_errors":1},"files":{"' . $file . '":{"errors":1,"messages":[{"message":"always true","line":4,"identifier":"method.alreadyNarrowedType"}]}},"errors":[]}';
        $this->factory->processes->willFail(1, $json);

        $this->tool()->run($this->factory->context($this->factory->builder(agentMode: true, specifiedPath: self::KERNEL)->build()));

        $printed = $this->factory->stdout->fetch();
        self::assertStringNotContainsString('possible tautology', $printed);
        self::assertStringNotContainsString(PhpstanTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function anAgentModeCrashIsReportedAsCrashedWithNoDebugReRun(): void
    {
        $this->factory->project->write(self::KERNEL, self::PHP_OPEN);
        $this->factory->processes->willFail(255, "PHP Fatal error: boom\n");

        $result = $this->tool()->run($this->factory->context($this->factory->builder(agentMode: true, specifiedPath: self::KERNEL)->build()));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertCount(1, $this->factory->processes->specs, 'a crash in agent mode is reported, not re-run for a human to watch');

        $report = $this->decode($this->reportPath(self::KERNEL));
        self::assertSame(self::CRASHED, $report['status']);
        self::assertSame(3, $report['exit_code']);
        self::assertStringContainsString(self::CRASHED, $this->stdoutLines()[0]);

        $index = $this->decode($this->reportsDir() . '/' . FileReportWriter::INDEX_FILE);
        self::assertSame(self::CRASHED, $index['status'], 'the index says the run crashed, not that it is clean');
    }

    #[Test]
    public function anAgentModeIncompleteResultIsACrashNotAFindingsReport(): void
    {
        $this->factory->project->write(self::KERNEL, self::PHP_OPEN);
        $json = $this->reportFor($this->factory->project->path . '/' . self::KERNEL, 1);
        $this->factory->processes->willReturn(new ProcessResultDto(1, $json . "\n" . self::INCOMPLETE_LINE, $json));

        $result = $this->tool()->run($this->factory->context($this->factory->builder(agentMode: true, specifiedPath: self::KERNEL)->build()));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame(self::CRASHED, $this->decode($this->reportPath(self::KERNEL))['status']);
    }

    #[Test]
    public function unreadableOutputInAgentModeIsACrashRatherThanAGreen(): void
    {
        $this->factory->project->write(self::KERNEL, self::PHP_OPEN);
        $this->factory->processes->willSucceed("not json at all\n");

        $result = $this->tool()->run($this->factory->context($this->factory->builder(agentMode: true, specifiedPath: self::KERNEL)->build()));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame(self::CRASHED, $this->decode($this->reportPath(self::KERNEL))['status']);
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = $this->tool();

        self::assertSame('phpstan', $tool->name());
        self::assertSame('phpqaci.phpstan', $tool->identifier());
        self::assertSame(PhpstanTool::IDENTIFIER, $tool->identifier());
    }

    private function tool(): PhpstanTool
    {
        return new PhpstanTool($this->turbo, $this->tmpDirProbe);
    }

    /** A PHPStan JSON report placing $count findings against one absolute file path. */
    private function reportFor(string $absolutePath, int $count): string
    {
        $messages = [];
        for ($i = 0; $i < $count; ++$i) {
            $messages[] = \sprintf('{"message":"Method foo%d() has no return type specified.","line":%d,"ignorable":true,"identifier":"missingType.return"}', $i, 11 + $i);
        }

        return \sprintf(
            '{"totals":{"errors":0,"file_errors":%d},"files":{"%s":{"errors":%d,"messages":[%s]}},"errors":[]}',
            $count,
            $absolutePath,
            $count,
            implode(',', $messages),
        );
    }

    private function reportsDir(): string
    {
        return $this->factory->project->path . '/' . self::VAR_QA_PREFIX . PhpstanTool::REPORT_DIR;
    }

    private function reportPath(string $projectRelative): string
    {
        return $this->reportsDir() . '/' . $projectRelative . '.json';
    }

    /** @return array<array-key, mixed> */
    private function decode(string $path): array
    {
        self::assertFileExists($path);
        $decoded = \Safe\json_decode(\Safe\file_get_contents($path), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return list<string> the non-empty lines agent mode put on the real stdout */
    private function stdoutLines(): array
    {
        return array_values(array_filter(
            explode("\n", trim($this->factory->stdout->fetch())),
            static fn (string $line): bool => '' !== trim($line),
        ));
    }

    /**
     * Build a phar holding only a Composer PSR-4 map that returns $map. Creating a phar needs
     * phar.readonly off, which only a fresh PHP process can have.
     *
     * @param array<string, mixed> $map
     */
    private function buildMapPhar(string $path, array $map): void
    {
        $process = new Process([
            \PHP_BINARY,
            '-d',
            'phar.readonly=0',
            '-r',
            '(new Phar($argv[1]))->addFromString("vendor/composer/autoload_psr4.php", "<?php return " . var_export(json_decode($argv[2], true), true) . ";");',
            $path,
            \Safe\json_encode($map),
        ]);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    /** @return list<string> the scanDirectories a clean run writes when the phars live in $pharDir */
    private function scannedDirectoriesWith(string $pharDir): array
    {
        $this->factory->processes->willSucceed(self::NO_ERRORS);
        $config = $this->factory->builder(ci: true, paths: $this->factory->paths(pharDir: $pharDir))->build();

        $this->tool()->run($this->factory->context($config));

        return $this->scannedDirectories($this->factory->project->read(self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON));
    }

    /** @return list<string> */
    private function scannedDirectories(string $wrapper): array
    {
        $directories = [];
        $inside      = false;
        foreach (explode("\n", $wrapper) as $line) {
            if ('    scanDirectories:' === $line) {
                $inside = true;

                continue;
            }

            if ($inside) {
                if (!str_starts_with($line, self::NEON_LIST_ITEM)) {
                    break;
                }

                $directories[] = substr($line, \strlen(self::NEON_LIST_ITEM));
            }
        }

        return $directories;
    }

    /** @return list<string> everything after the "--" separator */
    private function toolArgs(ProcessSpecDto $spec): array
    {
        $separator = array_search('--', $spec->command, true);
        self::assertIsInt($separator);

        return \array_slice($spec->command, $separator + 1);
    }

    private function script(ProcessSpecDto $spec): string
    {
        $flag = array_search('-f', $spec->command, true);
        self::assertIsInt($flag);

        return $spec->command[$flag + 1];
    }
}
