<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\DeadCode\DetectorUnpacker;
use LTS\PHPQA\Pipeline\Lane\DeadCodeTool;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use LTS\PHPQA\Tests\Support\FixedTmpDirProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DeadCodeTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\Phpstan\PhpstanCrash::class)]
#[UsesClass(\LTS\PHPQA\PackageType\ApiSurfaceEnforcementModeEnum::class)]
#[UsesClass(\LTS\PHPQA\PackageType\ProjectComposerTypeReader::class)]
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
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\Phpstan\DumpParametersTmpDirProbe::class)]
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
#[UsesClass(DetectorUnpacker::class)]
#[UsesClass(\LTS\PHPQA\Filesystem\TemporaryDirectory::class)]
#[Small]
final class DeadCodeToolTest extends TestCase
{
    private const string WRAPPER = 'var/qa/' . DeadCodeTool::LOG_DIR . '/' . DeadCodeTool::WRAPPER_NEON;

    private const string COMPOSER_JSON = 'composer.json';

    private const string API_CLASS = "<?php\n\n/** @api */\nfinal class Thing {}\n";

    private const string PROJECT_TYPE = '{"type": "project"}';

    private const string THING = 'src/Thing.php';

    private const string NO_ERRORS = "[OK] No errors\n";

    private const string LIBRARY_TYPE = '{"type": "library"}';

    private const string TMP_DIR = 'tmpDir';

    private const string IDENTIFIER_LINE = "🪪  phpqaci.deadCode  (vendor/bin/rule-doc phpqaci.deadCode)\n";

    private ContextFactory $factory;

    private FixedTmpDirProbe $tmpDirProbe;

    protected function setUp(): void
    {
        $this->factory     = ContextFactory::create();
        $this->tmpDirProbe = new FixedTmpDirProbe();
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function itIsSkippedUntilAProjectOptsIn(): void
    {
        $result = $this->tool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame('off; withDeadCodeDetection(true) in qaConfig/qa.php enables it', $result->summary);
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function aCleanRunWritesTheWrapperNeonWithThePharTheTestsExcluderAndTheEntryPoints(): void
    {
        $this->factory->processes->willSucceed(self::NO_ERRORS);
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);

        $config = $this->factory->builder(ci: true)
            ->withDeadCodeDetection(true)
            ->withDeadCodeEntryPoints('bin/console', '/abs/bin/tool')
            ->build()
        ;
        $paths = $config->paths;

        $result = $this->tool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        $wrapper  = $this->factory->project->read(self::WRAPPER);
        $detector = new DetectorUnpacker()->unpack($paths->pharDir . '/dead-code-detector.phar', $paths->cacheDir);
        self::assertStringContainsString("includes:\n    - " . \dirname(__DIR__, 4) . "/configDefaults/generic/phpstan.neon\n    - " . $detector . '/' . DetectorUnpacker::RULES_NEON . "\n", $wrapper);
        self::assertStringContainsString("    paths:\n        - " . $paths->srcDir . "\n        - " . $paths->testsDir . "\n        - " . $paths->projectRoot . "/bin/console\n        - /abs/bin/tool\n", $wrapper);
        self::assertStringContainsString("    shipmonkDeadCode:\n        usageExcluders:\n            tests:\n                enabled: true\n", $wrapper);
        self::assertStringContainsString("    parallel:\n        maximumNumberOfProcesses: 2\n", $wrapper);
        self::assertSame(
            ['/usr/bin/php', '-d', 'memory_limit=4G', '-f', $paths->pharDir . '/phpstan.phar', '--', 'analyse', '-c', $paths->projectRoot . '/' . self::WRAPPER, '--autoload-file', $detector . '/' . DetectorUnpacker::AUTOLOAD, \LTS\PHPQA\Pipeline\Lane\Phpstan\PhpstanCrash::TABLE_FORMAT, '--no-progress'],
            $this->factory->processes->lastSpec()->command,
            'the table format is named, so a project errorFormat cannot take away the summary line the verdict is read from',
        );
        // With Turbo, PHPStan forks its workers, and only phpstan.phar's reads are guarded across
        // the fork: a second PHAR is read through one shared descriptor and its source garbles.
        self::assertStringNotContainsString('phar://', $wrapper, 'the detector reaches PHPStan as plain files');
        self::assertStringNotContainsString('phar://', implode(' ', $this->factory->processes->lastSpec()->command), 'the detector reaches PHPStan as plain files');
        self::assertSame($paths->projectRoot, $this->factory->processes->lastSpec()->cwd);
        self::assertStringNotContainsString(DeadCodeTool::IDENTIFIER, $this->factory->output->fetch());
        self::assertStringNotContainsString('excludePaths', $wrapper, 'nothing ignored, nothing excluded');
    }

    /** The lane always analyses the whole project, so its log is archived as a full run. */
    #[Test]
    public function theLogIsWrittenAndArchivedAsAFullRun(): void
    {
        $this->factory->processes->willSucceed(self::NO_ERRORS);
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        $this->tool()->run($this->factory->context($config));

        $logDir = 'var/qa/' . DeadCodeTool::LOG_DIR;
        self::assertSame(self::NO_ERRORS, $this->factory->project->read($logDir . '/' . DeadCodeTool::LOG_FILE));
        self::assertMatchesRegularExpression('/\nFull test suite run\nLog: dead-code\.\d{8}-\d{6}\.log\n\z/', $this->factory->output->fetch());
        $archived = array_filter($this->factory->project->files($logDir), static fn (string $f): bool => 1 === \Safe\preg_match('/^dead-code\.\d{8}-\d{6}\.log$/', $f));
        self::assertCount(1, $archived);
    }

    #[Test]
    public function theIgnoredPathsAreExcludedExactlyAsThePhpstanLaneExcludesThem(): void
    {
        $this->factory->processes->willSucceed(self::NO_ERRORS);
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);

        $root   = $this->factory->project->path;
        $config = $this->factory->builder()
            ->withDeadCodeDetection(true)
            ->withoutDeadCodeEntryPoints()
            ->withIgnoredPaths('tests/assets')
            ->build()
        ;

        $this->tool()->run($this->factory->context($config));

        self::assertStringContainsString(
            "    excludePaths:\n        analyse:\n            - '" . $root . "/tests/assets' (?)\n    shipmonkDeadCode:\n",
            $this->factory->project->read(self::WRAPPER),
        );
    }

    /**
     * The dead-code run keeps its own cache, apart from the PHPStan lane's: PHPStan holds one
     * result cache per tmpDir, so two configurations sharing one would invalidate each other on
     * every alternation (#123).
     */
    #[Test]
    public function theCacheLivesInsideTheProjectApartFromThePhpstanLanes(): void
    {
        $this->factory->processes->willSucceed();
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        $this->tool()->run($this->factory->context($config));

        $cacheDir = $this->factory->project->path . '/var/qa/cache/deadCode';
        self::assertStringContainsString("    tmpDir: '" . $cacheDir . "'\n", $this->factory->project->read(self::WRAPPER));
        self::assertDirectoryExists($cacheDir);
        self::assertStringContainsString('Dead code: cache in var/qa/cache/deadCode', $this->factory->output->fetch());
    }

    #[Test]
    public function aTmpDirTheProjectSetsItselfIsLeftInPlace(): void
    {
        $this->factory->processes->willSucceed();
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);
        $this->factory->project->write('qaConfig/phpstan.neon', "parameters:\n    tmpDir: /ci-cache/phpstan\n");

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        $this->tool()->run($this->factory->context($config));

        self::assertStringNotContainsString(self::TMP_DIR, $this->factory->project->read(self::WRAPPER));
        self::assertStringContainsString('Dead code: cache in the tmpDir qaConfig/phpstan.neon sets', $this->factory->output->fetch());
    }

    /**
     * A tmpDir only PHPStan can resolve (behind a `%parameter%` or PHP include) is the project's
     * too (#140). PHPStan is asked with the detector's autoload file, as the analysis runs.
     */
    #[Test]
    public function aTmpDirOnlyPhpstanCanResolveIsLeftInPlace(): void
    {
        $this->tmpDirProbe = new FixedTmpDirProbe('/ci-cache/phpstan');
        $this->factory->processes->willSucceed();
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);

        $config  = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();
        $context = $this->factory->context($config);

        $this->tool()->run($context);

        $detector = new DetectorUnpacker()->unpack($config->paths->pharDir . '/dead-code-detector.phar', $config->paths->cacheDir);
        self::assertStringNotContainsString(self::TMP_DIR, $this->factory->project->read(self::WRAPPER));
        self::assertStringContainsString("Dead code: cache in the tmpDir the project's PHPStan configuration sets, /ci-cache/phpstan", $this->factory->output->fetch());
        self::assertSame([[$context->configPath('phpstan.neon'), $detector . '/' . DetectorUnpacker::AUTOLOAD]], $this->tmpDirProbe->askedAbout);
    }

    /** The shipped lane (ShippedTools builds it with no arguments) asks the phar through dump-parameters. */
    #[Test]
    public function theShippedLaneAsksThePharThroughDumpParameters(): void
    {
        $this->factory->processes->willSucceed('{"tmpDir":"/ci-cache/phpstan","sysGetTempDir":"/t"}');
        $this->factory->processes->willSucceed(self::NO_ERRORS);
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        new DeadCodeTool()->run($this->factory->context($config));

        self::assertContains('dump-parameters', $this->factory->processes->specs[0]->command);
        self::assertContains('--autoload-file', $this->factory->processes->specs[0]->command);
        self::assertStringNotContainsString(self::TMP_DIR, $this->factory->project->read(self::WRAPPER));
    }

    #[Test]
    public function aLibraryWithNoApiTagFailsFastBeforeAnythingRuns(): void
    {
        $this->factory->project->write(self::COMPOSER_JSON, self::LIBRARY_TYPE);
        $this->factory->project->write(self::THING, "<?php\n\n/** @internal */\nfinal class Thing {}\n");

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        $result  = $this->tool()->run($this->factory->context($config));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('no @api tag in src/; a library needs its public surface declared before dead-code detection means anything', $result->summary);
        self::assertSame(
            "\n"
            . "Dead-code detection needs a declared public surface: the detector treats every\n"
            . "@api class as an entry point, and a library with none has every public member\n"
            . "reported as dead. Classify every public class-like as @api or @internal first\n"
            . "(RequireApiOrInternalTagRule enforces exactly that), then run this lane again.\n"
            . "\n"
            . self::IDENTIFIER_LINE,
            $printed,
        );
        self::assertSame([], $this->factory->processes->specs);
    }

    /** Only a PHP file declares a class: `@api` in a README or a fixture is not a public surface. */
    #[Test]
    public function anApiTagOutsideAPhpFileDoesNotCount(): void
    {
        $this->factory->project->write(self::COMPOSER_JSON, self::LIBRARY_TYPE);
        $this->factory->project->write(self::THING, "<?php\n\n/** @internal */\nfinal class Thing {}\n");
        $this->factory->project->write('src/README.md', "Mark the public classes @api.\n");

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        $result = $this->tool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function aLibraryWithNoSourceDirectoryHasNoApiTag(): void
    {
        $this->factory->project->write(self::COMPOSER_JSON, self::LIBRARY_TYPE);
        \Safe\rmdir($this->factory->project->path . '/src');

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        $result = $this->tool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function aLibraryWithAnApiTagRunsAndAProjectNeverNeedsOne(): void
    {
        $this->factory->processes->willSucceed();
        $this->factory->project->write(self::COMPOSER_JSON, self::LIBRARY_TYPE);
        $this->factory->project->write(self::THING, self::API_CLASS);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        self::assertSame(ToolOutcomeEnum::Passed, $this->tool()->run($this->factory->context($config))->outcome);

        $this->factory->processes->willSucceed();
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);
        $this->factory->project->write(self::THING, "<?php\n\nfinal class Thing {}\n");

        self::assertSame(ToolOutcomeEnum::Passed, $this->tool()->run($this->factory->context($config))->outcome);
    }

    #[Test]
    public function findingsFailTheLaneWithTheIdentifier(): void
    {
        $this->factory->processes->willFail(1, " 12  Unused Foo::bar\n\n [ERROR] Found 1 error\n");
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        $result  = $this->tool()->run($this->factory->context($config));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('dead code found', $result->summary);
        self::assertStringEndsWith(
            ".log\n"
            . "\n"
            . "HOW TO FIX\n"
            . "----------\n"
            . "Each report names a member nothing reaches. Delete it, or wire the caller the\n"
            . "report shows is missing. Two shapes are not dead code and have their own fix:\n"
            . "  ENTRY POINT — a method only a script or an external runtime calls. List the\n"
            . "  script with withDeadCodeEntryPoints() in qaConfig/qa.php; a class an external\n"
            . "  runtime drives (a Composer plugin, a console command) is @api.\n"
            . "  TESTS ONLY — \"all usages excluded by tests excluder\": production never calls\n"
            . "  it. Delete it with the tests that used it, or move it into the tests tree.\n"
            . "Never suppress with an ignore comment or a baseline.\n"
            . "\n"
            . self::IDENTIFIER_LINE,
            $printed,
        );
    }

    /** A config error exits 1 before anything is analysed: the "delete it" guidance would be wrong. */
    #[Test]
    public function aConfigurationErrorIsACrashNotDeadCode(): void
    {
        $this->factory->processes->willFail(1, "Invalid configuration:\nUnexpected item 'parameters › notARealParameter'.\n");
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        $result  = $this->tool()->run($this->factory->context($config));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame(\LTS\PHPQA\Pipeline\Lane\Phpstan\PhpstanCrash::NO_REPORT_REASON, $result->summary);
        self::assertStringNotContainsString('Delete it', $printed);
        self::assertStringNotContainsString(DeadCodeTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function anExitAboveOneIsACrash(): void
    {
        $this->factory->processes->willFail(255, 'Fatal');
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        $result = $this->tool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame('PHPStan crashed (exit 255)', $result->summary);
    }

    /**
     * PHPStan exits 1 both for findings and for an analysis it abandoned on internal errors, which
     * reports none of the dead code there is (#82). The run that showed it printed the "delete it,
     * or wire the caller" guidance over four internal errors and no findings.
     */
    #[Test]
    public function anIncompleteResultIsACrashNotDeadCode(): void
    {
        $this->factory->processes->willFail(
            1,
            " Internal error: Class \"ShipMonk\\PHPStan\\DeadCode\\Graph\\ClassMethodUsage\" not found while analysing file /p/bin/x\n\n"
            . " [ERROR] Found 1 error\n\n⚠️  Result is incomplete because of severe errors. ⚠️\n",
        );
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        $result  = $this->tool()->run($this->factory->context($config));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertStringContainsString('incomplete', $result->summary);
        self::assertStringNotContainsString('withDeadCodeEntryPoints', $printed, 'no dead-code guidance for a run that reported none');
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = $this->tool();

        self::assertSame('deadCode', $tool->name());
        self::assertSame('phpqaci.deadCode', $tool->identifier());
    }

    private function tool(): DeadCodeTool
    {
        return new DeadCodeTool($this->tmpDirProbe);
    }
}
