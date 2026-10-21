<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\DeadCode\DetectorUnpacker;
use LTS\PHPQA\Pipeline\Lane\DeadCode\GitIgnoredFiles;
use LTS\PHPQA\Pipeline\Lane\DeadCodeTool;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use LTS\PHPQA\Tests\Support\FixedTmpDirProbe;
use LTS\PHPQA\Tests\Support\TempDir;
use LTS\PHPQA\Tests\Support\UnremovableTree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

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
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\Phpstan\PhpstanSetupCache::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\Phpstan\PhpstanSetupKey::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\InstalledPhpstanExtensions::class)]
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
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\DeadCode\BinEntryPointScripts::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\DeadCode\AnalysedFiles::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\DeadCode\Dto\BinScanDto::class)]
#[UsesClass(GitIgnoredFiles::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\Phpstan\Dto\PhpstanParametersDto::class)]
#[UsesClass(\LTS\PHPQA\Filesystem\TemporaryDirectory::class)]
#[UsesClass(\LTS\PHPQA\Filesystem\DirectoryTree::class)]
#[Small]
#[UsesClass(\LTS\PHPQA\ComposerPlugin\ChildExit::class)]
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

    private const string CONSOLE = 'bin/console';

    private const string PHP_SCRIPT = "#!/usr/bin/env php\n<?php\n\necho 'hi';\n";

    private const string BIN_APP = 'bin/app';

    private const string LEGACY_APP = 'src/legacy/app.php';

    private const string ANALYSE = 'analyse';

    private const string ANALYSE_AND_SCAN = 'analyseAndScan';

    private ContextFactory $factory;

    private FixedTmpDirProbe $tmpDirProbe;

    private ?string $unremovable = null;

    protected function setUp(): void
    {
        $this->factory     = ContextFactory::create();
        $this->tmpDirProbe = new FixedTmpDirProbe();
    }

    protected function tearDown(): void
    {
        if (null !== $this->unremovable) {
            UnremovableTree::remove($this->unremovable);
        }

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
        $detector = new DetectorUnpacker(new NullOutput())->unpack($paths->pharDir . '/dead-code-detector.phar', $paths->cacheDir);
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

        $detector = new DetectorUnpacker(new NullOutput())->unpack($config->paths->pharDir . '/dead-code-detector.phar', $config->paths->cacheDir);
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

    /**
     * A PHP script under bin/ the project did not declare is analysed by nothing, so every
     * member only it reaches would be reported dead and its own code never read (#277).
     */
    #[Test]
    public function anUndeclaredPhpScriptUnderBinFailsBeforeAnythingRuns(): void
    {
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);
        $this->factory->project->write(self::CONSOLE, self::PHP_SCRIPT);
        $this->factory->project->write('bin/worker', self::PHP_SCRIPT);
        $this->factory->project->write('bin/cron', self::PHP_SCRIPT);
        $this->factory->project->write('bin/deploy', "#!/usr/bin/env bash\necho deploying\n");
        $this->factory->processes->willSucceed();

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withDeadCodeEntryPoints(self::CONSOLE)->build();

        $result  = $this->tool()->run($this->factory->context($config));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('2 PHP scripts under bin/ are not declared entry points: bin/cron, bin/worker', $result->summary);
        self::assertSame(
            "\n"
            . "PHP scripts under bin/ not declared as dead-code entry points:\n"
            . "  bin/cron\n"
            . "  bin/worker\n"
            . "\n"
            . "The detector reads a script under bin/ only when it is declared: one left off the\n"
            . "list is analysed by nothing, and every member only it reaches is reported dead.\n"
            . "List each with withDeadCodeEntryPoints() in qaConfig/qa.php. A declared script\n"
            . "the project's phpstan.neon excludePaths removes is not read either.\n"
            . "\n"
            . self::IDENTIFIER_LINE,
            $printed,
        );
        $this->assertOnlyGitRan();
    }

    /** Both findings are reported together, each with its own guidance. */
    #[Test]
    public function anUndeclaredScriptAndAnUnreadablePathAreBothReported(): void
    {
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);
        $this->factory->project->write(self::CONSOLE, self::PHP_SCRIPT);
        $this->factory->project->write('bin/worker', self::PHP_SCRIPT);

        $this->unremovable = $this->factory->project->path . '/bin/deep';
        UnremovableTree::plant($this->unremovable);
        UnremovableTree::plant($this->factory->project->path . '/bin/deeper');
        $this->factory->processes->willSucceed();

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        try {
            $result  = $this->tool()->run($this->factory->context($config));
            $printed = $this->factory->output->fetch();
        } finally {
            UnremovableTree::remove($this->factory->project->path . '/bin/deeper');
        }

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertMatchesRegularExpression(
            '~\A2 PHP scripts under bin/ are not declared entry points: bin/console, bin/worker; 2 paths under bin/ could not be read, so whether they hold an undeclared PHP script is unknown: bin/deep/d+/.+, bin/deeper/d+/.+\z~',
            $result->summary,
        );
        self::assertStringContainsString(
            "\nA path the lane cannot read may hold a PHP script analysed by nothing. Make it\nreadable, or remove it if it is not the project's.\n\n" . self::IDENTIFIER_LINE,
            $printed,
        );
        self::assertStringContainsString("\nPaths under bin/ the lane could not read:\n  bin/deep/", $printed);
        $this->assertOnlyGitRan();
    }

    /** Saying the project has no entry points is contradicted by a PHP script under bin/. */
    #[Test]
    public function declaringNoEntryPointsFailsWhenBinHoldsAPhpScript(): void
    {
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);
        $this->factory->project->write(self::CONSOLE, self::PHP_SCRIPT);
        $this->factory->processes->willSucceed();

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        $result = $this->tool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('1 PHP script under bin/ is not a declared entry point: bin/console', $result->summary);
        $this->assertOnlyGitRan();
    }

    /** A link in bin/ to the project's own script hides that script exactly as a regular file would. */
    #[Test]
    public function anUndeclaredLinkToAFirstPartyPhpScriptFailsTheLane(): void
    {
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);
        $this->factory->project->write('scripts/worker', self::PHP_SCRIPT);
        $this->linkInBin('../scripts/worker', 'worker');
        $this->factory->processes->willSucceed();

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        $result = $this->tool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('1 PHP script under bin/ is not a declared entry point: bin/worker', $result->summary);
        $this->assertOnlyGitRan();
    }

    /** The lane's run already reads src/: a link to a `.php` file there hides nothing. */
    #[Test]
    public function aLinkToAFileUnderTheSourceDirectoryRunsTheDetector(): void
    {
        $this->factory->processes->willSucceed();
        $this->factory->processes->willSucceed(self::NO_ERRORS);
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);
        $this->factory->project->write('src/app.php', self::PHP_SCRIPT);
        $this->linkInBin('../src/app.php');

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        self::assertSame(ToolOutcomeEnum::Passed, $this->tool()->run($this->factory->context($config))->outcome);
    }

    /** A first-party script in a subdirectory of bin/ is analysed by nothing until declared. */
    #[Test]
    public function anUndeclaredScriptInASubdirectoryOfBinFailsTheLane(): void
    {
        $this->factory->processes->willSucceed();
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);
        $this->factory->project->write('bin/cron/job', self::PHP_SCRIPT);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        $result = $this->tool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('1 PHP script under bin/ is not a declared entry point: bin/cron/job', $result->summary);
        $this->assertOnlyGitRan();
    }

    /** A tree the project ignores is one it has said is not analysed. */
    #[Test]
    public function aScriptUnderAnIgnoredPathRunsTheDetector(): void
    {
        $this->factory->processes->willSucceed();
        $this->factory->processes->willSucceed(self::NO_ERRORS);
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);
        $this->factory->project->write('bin/.phpunit/phpunit', self::PHP_SCRIPT);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->withIgnoredPaths('bin/.phpunit')->build();

        self::assertSame(ToolOutcomeEnum::Passed, $this->tool()->run($this->factory->context($config))->outcome);
    }

    #[Test]
    public function everyPhpScriptUnderBinDeclaredRunsTheDetector(): void
    {
        $this->factory->processes->willSucceed();
        $this->factory->processes->willSucceed(self::NO_ERRORS);
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);
        $this->factory->project->write(self::CONSOLE, self::PHP_SCRIPT);
        $this->factory->project->write('bin/deploy', "#!/usr/bin/env bash\necho deploying\n");

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withDeadCodeEntryPoints(self::CONSOLE)->build();

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

    /** A stale detector copy the lane cannot remove is reported on the lane's output, not a failure. */
    #[Test]
    public function aStaleDetectorCopyThatCannotBeRemovedIsAWarningNotAFailure(): void
    {
        $this->factory->processes->willSucceed(self::NO_ERRORS);
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);

        $config            = $this->factory->builder(ci: true)->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();
        $this->unremovable = $config->paths->cacheDir . '/' . DetectorUnpacker::DIR . '/0123456789abcdef';
        UnremovableTree::plant($this->unremovable);

        $result = $this->tool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('WARNING: could not remove ' . $this->unremovable . ',', $this->factory->output->fetch());
    }

    /**
     * PHPStan merges the project's own phpstan.neon `paths` into the lane's: a script listed
     * there is read by the run, declared or not.
     */
    #[Test]
    public function aScriptTheProjectsPhpstanConfigurationAnalysesIsNotRequired(): void
    {
        $root = $this->factory->project->path;
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);
        $this->factory->project->write(self::CONSOLE, self::PHP_SCRIPT);
        $this->factory->processes->willSucceed($this->dump(paths: [$root . '/' . self::CONSOLE]));
        $this->factory->processes->willSucceed();
        $this->factory->processes->willSucceed(self::NO_ERRORS);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        self::assertSame(ToolOutcomeEnum::Passed, new DeadCodeTool()->run($this->factory->context($config))->outcome);
    }

    /** A suffix the project adds to `fileExtensions` is read from every analysed directory. */
    #[Test]
    public function aFileWithASuffixTheProjectAnalysesIsNotRequired(): void
    {
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);
        $this->factory->project->write('src/app.inc', self::PHP_SCRIPT);
        $this->linkInBin('../src/app.inc');
        $this->factory->processes->willSucceed($this->dump(extensions: ['php', 'inc']));
        $this->factory->processes->willSucceed();
        $this->factory->processes->willSucceed(self::NO_ERRORS);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        self::assertSame(ToolOutcomeEnum::Passed, new DeadCodeTool()->run($this->factory->context($config))->outcome);
    }

    /**
     * The project's `excludePaths` takes a file out of the run, whether it was found by scanning
     * a directory or listed by name, so a script reaching only such a file is analysed by nothing.
     *
     * @param list<string> $declared project-relative
     * @param list<string> $excluded project-relative; a pattern is matched as PHPStan's fnmatch() does
     */
    #[Test]
    #[DataProvider('excludedScripts')]
    public function aScriptTheProjectExcludesIsRequired(string $target, array $declared, array $excluded, string $analysedKey): void
    {
        $root = $this->factory->project->path;
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);
        $this->factory->project->write($target, self::PHP_SCRIPT);
        if (self::BIN_APP !== $target) {
            $this->linkInBin('../' . $target);
        }

        $this->factory->processes->willSucceed($this->dump(excluded: [$analysedKey => array_map(static fn (string $path): string => $root . '/' . $path, $excluded)]));
        $this->factory->processes->willSucceed();
        $this->factory->processes->willSucceed(self::NO_ERRORS);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withDeadCodeEntryPoints(...$declared)->build();

        $result = new DeadCodeTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('1 PHP script under bin/ is not a declared entry point: bin/app', $result->summary);
    }

    /** @return iterable<string, array{string, list<string>, list<string>, string}> */
    public static function excludedScripts(): iterable
    {
        yield 'a .php file in an excluded directory under src/' => [self::LEGACY_APP, [], ['src/legacy'], self::ANALYSE_AND_SCAN];
        yield 'a .php file excluded by a pattern' => [self::LEGACY_APP, [], ['src/*/app.php'], self::ANALYSE];
        yield 'a .php file excluded by name' => [self::LEGACY_APP, [], [self::LEGACY_APP], self::ANALYSE];
        yield 'a declared script the project excludes by a pattern' => [self::BIN_APP, [self::BIN_APP], ['bin/*'], self::ANALYSE_AND_SCAN];
    }

    /** PHPStan's directory walk leaves out dot files and dot directories, so it never reads a script there. */
    #[Test]
    public function aLinkIntoADotDirectoryOfAnAnalysedDirectoryIsRequired(): void
    {
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);
        $this->factory->project->write('src/.hidden/app.php', self::PHP_SCRIPT);
        $this->linkInBin('../src/.hidden/app.php');
        $this->factory->processes->willSucceed($this->dump());
        $this->factory->processes->willSucceed();
        $this->factory->processes->willSucceed(self::NO_ERRORS);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        self::assertSame(ToolOutcomeEnum::Failed, new DeadCodeTool()->run($this->factory->context($config))->outcome);
    }

    /**
     * The bin/ check and the cache decision read one dump-parameters answer: PHPStan is started
     * once for both, even when the project's own config names its tmpDir.
     */
    #[Test]
    public function phpstanIsAskedForItsParametersOnceAndOnlyOnce(): void
    {
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);
        $this->factory->project->write('qaConfig/phpstan.neon', "parameters:\n    tmpDir: /ci-cache/phpstan\n");
        $this->factory->project->write(self::CONSOLE, self::PHP_SCRIPT);
        $this->factory->processes->willSucceed($this->dump(paths: [$this->factory->project->path . '/' . self::CONSOLE]));
        $this->factory->processes->willSucceed();
        $this->factory->processes->willSucceed(self::NO_ERRORS);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        $result = new DeadCodeTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertCount(1, array_filter($this->factory->processes->specs, static fn (ProcessSpecDto $spec): bool => \in_array('dump-parameters', $spec->command, true)));
        self::assertStringNotContainsString(self::TMP_DIR, $this->factory->project->read(self::WRAPPER));
    }

    /**
     * A file git ignores is never committed, so CI never has it: requiring it would make the
     * verdict depend on one checkout's local state. Symfony's simple-phpunit installs PHPUnit
     * under bin/.phpunit, which the project git-ignores.
     */
    #[Test]
    public function aTreeGitIgnoresUnderBinIsNotRequired(): void
    {
        $root = $this->factory->project->path;
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);
        $this->factory->project->write('bin/.phpunit/phpunit-9.6/phpunit', self::PHP_SCRIPT);
        $this->factory->project->write('bin/.phpunit/phpunit-9.6/src/TextUI/Command.php', "<?php\n\nfinal class Command {}\n");
        $this->factory->processes->willSucceed("bin/.phpunit/\0");
        $this->factory->processes->willSucceed(self::NO_ERRORS);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        self::assertSame(ToolOutcomeEnum::Passed, $this->tool()->run($this->factory->context($config))->outcome);
        self::assertSame(['git', '-c', 'core.quotePath=false', 'ls-files', '-z', '--others', '--ignored', '--exclude-standard', '--directory'], $this->factory->processes->specs[0]->command);
        self::assertSame($root, $this->factory->processes->specs[0]->cwd);
    }

    /** A link is judged by its target too: one into a tree git ignores reaches no committed script. */
    #[Test]
    public function aLinkToAFileGitIgnoresIsNotRequired(): void
    {
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);
        $this->factory->project->write('build/tool', self::PHP_SCRIPT);
        $this->linkInBin('../build/tool', 'tool');
        $this->factory->processes->willSucceed("build/\0");
        $this->factory->processes->willSucceed(self::NO_ERRORS);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        self::assertSame(ToolOutcomeEnum::Passed, $this->tool()->run($this->factory->context($config))->outcome);
    }

    /** With no answer from git nothing is skipped as ignored, and the run says so. */
    #[Test]
    public function withNoAnswerFromGitNothingIsSkippedAsIgnored(): void
    {
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);
        $this->factory->project->write('bin/.phpunit/phpunit', self::PHP_SCRIPT);
        $this->factory->processes->willFail(128, "fatal: not a git repository (or any of the parent directories): .git\n");
        $this->factory->processes->willSucceed(self::NO_ERRORS);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        $result = $this->tool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('1 PHP script under bin/ is not a declared entry point: bin/.phpunit/phpunit', $result->summary);
        self::assertStringContainsString("Dead code: git could not list the ignored files (exit 128), so no file under bin/ is skipped as git-ignored\n", $this->factory->output->fetch());
    }

    /**
     * A directory that cannot be read may hold an undeclared script, so the lane cannot pass it.
     * A path past PATH_MAX cannot be read even by root, as a mode bit can.
     */
    #[Test]
    public function aPathUnderBinThatCannotBeReadFailsTheLane(): void
    {
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);
        $this->unremovable = $this->factory->project->path . '/bin/deep';
        UnremovableTree::plant($this->unremovable);
        $this->factory->processes->willSucceed();
        $this->factory->processes->willSucceed(self::NO_ERRORS);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        $result = $this->tool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringStartsWith('1 path under bin/ could not be read, so whether it holds an undeclared PHP script is unknown: bin/deep/', $result->summary);
    }

    /**
     * A linked directory outside the project, or inside the vendor dir, is not walked: its code
     * is not the project's, and walking it could read the whole host. A tree there that cannot
     * be read is therefore never reached.
     */
    #[Test]
    #[DataProvider('linkedDirectoriesNotWalked')]
    public function aLinkedDirectoryOutsideTheProjectOrInTheVendorDirIsNotWalked(bool $outside): void
    {
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);
        $elsewhere = TempDir::create('phpqa-dead-code-elsewhere');
        $target    = $outside ? $elsewhere->path . '/tree' : $this->factory->project->path . '/vendor/acme/tree';

        try {
            UnremovableTree::plant($target . '/deep');
            $this->linkInBin($target, 'linked');
            $this->factory->processes->willSucceed();
            $this->factory->processes->willSucceed(self::NO_ERRORS);

            $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

            self::assertSame(ToolOutcomeEnum::Passed, $this->tool()->run($this->factory->context($config))->outcome);
        } finally {
            UnremovableTree::remove($target . '/deep');
            $elsewhere->remove();
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function linkedDirectoriesNotWalked(): iterable
    {
        yield 'outside the project' => [true];
        yield 'inside the vendor dir' => [false];
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

    /** Creates bin/<name> as a link to $target, which may be relative to bin/. */
    private function linkInBin(string $target, string $name = 'app'): void
    {
        $this->factory->project->mkdir('bin');
        \Safe\symlink($target, $this->factory->project->path . '/bin/' . $name);
    }

    /** The bin/ check asked git what it ignores, and nothing was analysed. */
    private function assertOnlyGitRan(): void
    {
        self::assertSame([GitIgnoredFiles::COMMAND], array_map(static fn (ProcessSpecDto $spec): array => $spec->command, $this->factory->processes->specs));
    }

    /**
     * What `phpstan.phar dump-parameters --json` prints, with PHPStan's default tmpDir.
     *
     * @param list<string>                $paths      absolute
     * @param array<string, list<string>> $excluded   `analyse` and `analyseAndScan`, absolute
     * @param list<string>                $extensions
     */
    private function dump(array $paths = [], array $excluded = [], array $extensions = ['php']): string
    {
        return \Safe\json_encode([
            'tmpDir'         => '/t/phpstan',
            'sysGetTempDir'  => '/t',
            'paths'          => $paths,
            'excludePaths'   => [...[self::ANALYSE_AND_SCAN => [], self::ANALYSE => []], ...$excluded],
            'fileExtensions' => $extensions,
        ]);
    }
}
