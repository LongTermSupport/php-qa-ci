<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Pipeline\Lane\ReadOnlyGuidance;
use LTS\PHPQA\Pipeline\Lane\RectorTool;
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
#[CoversClass(RectorTool::class)]
#[UsesClass(ReadOnlyGuidance::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(EnvironmentReader::class)]
#[UsesClass(QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(ToolContext::class)]
#[UsesClass(ToolOutcomeEnum::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class RectorToolTest extends TestCase
{
    private const string ADVISORY = 'Rector ran in WRITABLE mode and may have rewritten files. DO NOT FIGHT IT.';

    private const string SAFE_CONFIG = 'rector-safe.php';

    private const string PHPUNIT_CONFIG = 'rector-phpunit.php';

    private const string PHP85_CONFIG = 'rector-php85.php';

    private const string PHPUNIT_PASS_LINE = 'Running PHPUnit Rector on ';

    private const string RECTOR_TMP = '/rector';

    /** What the bundled Rector 2.7 prints for cacheClass(MemoryCacheStorage::class), as the terminal wraps it. */
    private const string MEMORY_CACHE_REJECTED = "\n [ERROR] Expected an instance of this class or to this class among its parents  \n"
        . "         \"Rector\\Caching\\Contract\\ValueObject\\Storage\\CacheStorageInterface\".   \n"
        . "         Got: \"Rector\\Caching\\ValueObject\\Storage\\MemoryCacheStorage\"           \n\n";

    private const string EMPTY_CONFIG = "<?php\n";

    private const string IDENTIFIER_FOOTER ="\n🪪  phpqaci.rector  (vendor/bin/rule-doc phpqaci.rector)\n";

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
    public function aReadOnlyRunMakesThreeDryRunPassesWithTheExpectedArgv(): void
    {
        $this->queuePasses(0, 0, 0);
        $context = $this->context(readOnly: true);

        $result = new RectorTool()->run($context);

        self::assertTrue($result->isSuccess());
        self::assertCount(3, $this->rectorSpecs());
        self::assertSame($this->expectedArgs($context, self::SAFE_CONFIG, true, ...$context->config->pathsToCheck), $this->toolArgs(0));
        self::assertSame($this->expectedArgs($context, self::PHPUNIT_CONFIG, true, $context->config->paths->testsDir), $this->toolArgs(1));
        self::assertSame($this->expectedArgs($context, self::PHP85_CONFIG, true, ...$context->config->pathsToCheck), $this->toolArgs(2));

        $printed = $this->factory->output->fetch();
        self::assertStringContainsString("Running Rector ('Safe') in read-only check mode", $printed);
        self::assertStringContainsString(self::PHPUNIT_PASS_LINE . $context->config->paths->testsDir, $printed);
        self::assertStringContainsString("Running Rector ('PHP 8.5') in read-only check mode", $printed);
        self::assertStringNotContainsString(self::ADVISORY, $printed);
    }

    #[Test]
    public function aPathOutsideTheTestsDirectorySkipsThePhpunitPass(): void
    {
        $this->queuePasses(0, 0);
        $context = $this->factory->context($this->factory->builder(readOnly: true, specifiedPath: 'src/One.php')->build());

        $result = new RectorTool()->run($context);

        self::assertTrue($result->isSuccess());
        self::assertCount(2, $this->rectorSpecs(), 'only the Safe and PHP 8.5 passes run');
        self::assertSame($this->expectedArgs($context, self::SAFE_CONFIG, true, ...$context->config->pathsToCheck), $this->toolArgs(0));
        self::assertSame($this->expectedArgs($context, self::PHP85_CONFIG, true, ...$context->config->pathsToCheck), $this->toolArgs(1));
        self::assertStringNotContainsString(self::PHPUNIT_CONFIG, implode("\n", $this->factory->processes->commandLines()));
        self::assertStringContainsString('Skipping PHPUnit Rector: no checked path is inside ' . $context->config->paths->testsDir, $this->factory->output->fetch());
    }

    #[Test]
    public function aPathInsideTheTestsDirectoryIsThePhpunitPassesOnlyPath(): void
    {
        $this->queuePasses(0, 0, 0);
        $context = $this->factory->context($this->factory->builder(readOnly: true, specifiedPath: 'tests/Unit/OneTest.php')->build());

        new RectorTool()->run($context);

        $testFile = $context->config->paths->testsDir . '/Unit/OneTest.php';
        self::assertSame($this->expectedArgs($context, self::PHPUNIT_CONFIG, true, $testFile), $this->toolArgs(1));
        self::assertStringContainsString(self::PHPUNIT_PASS_LINE . $testFile, $this->factory->output->fetch());
    }

    /** `./` and `..` segments name the same file, so they must not move it outside the tests directory. */
    #[Test]
    public function aPathWithDotSegmentsInsideTheTestsDirectoryIsStillThePhpunitPassesPath(): void
    {
        $this->queuePasses(0, 0, 0);
        $context = $this->factory->context($this->factory->builder(readOnly: true, specifiedPath: './src/../tests/Unit/OneTest.php')->build());

        new RectorTool()->run($context);

        $testFile = $context->config->paths->testsDir . '/Unit/OneTest.php';
        self::assertSame($this->expectedArgs($context, self::PHPUNIT_CONFIG, true, $testFile), $this->toolArgs(1));
    }

    #[Test]
    public function aPathContainingTheTestsDirectoryGivesThePhpunitPassTheWholeTestsDirectory(): void
    {
        $this->queuePasses(0, 0, 0);
        $context = $this->factory->context($this->factory->builder(readOnly: true, specifiedPath: '.')->build());

        new RectorTool()->run($context);

        self::assertSame($this->expectedArgs($context, self::PHPUNIT_CONFIG, true, $context->config->paths->testsDir), $this->toolArgs(1));
    }

    #[Test]
    public function aSiblingWhoseNameStartsLikeTheTestsDirectoryIsNotInsideIt(): void
    {
        $this->queuePasses(0, 0);
        $context = $this->factory->context($this->factory->builder(readOnly: true, specifiedPath: 'tests-legacy/OneTest.php')->build());

        new RectorTool()->run($context);

        self::assertCount(2, $this->rectorSpecs(), 'tests-legacy/ is not tests/, so the PHPUnit pass is skipped');
    }

    #[Test]
    public function aWritableRunOmitsDryRunAndPrintsTheAdvisory(): void
    {
        $this->queuePasses(0, 0, 0);
        $context = $this->context(readOnly: false);

        $result = new RectorTool()->run($context);

        self::assertTrue($result->isSuccess());
        self::assertSame($this->expectedArgs($context, self::SAFE_CONFIG, false, ...$context->config->pathsToCheck), $this->toolArgs(0));
        foreach ($this->rectorSpecs() as $spec) {
            self::assertNotContains('--dry-run', $spec->command);
        }

        $rule = str_repeat('-', 78) . "\n";
        self::assertSame(
            "Running Rector ('Safe')\n"
            . self::PHPUNIT_PASS_LINE . $context->config->paths->testsDir . "\n"
            . "Running Rector ('PHPUnit')\n"
            . "Running Rector ('PHP 8.5')\n"
            . "\n"
            . $rule
            . self::ADVISORY . "\n"
            . $rule
            . "Rector is a DETERMINISTIC, CONFIG-DRIVEN autofixer. Every edit it just made is\n"
            . "intentional and part of the standard — treat it as authoritative, not as a\n"
            . "suggestion to negotiate:\n"
            . "\n"
            . "  - Do NOT revert, undo, partially back out, or hand-\"tidy\" a Rector edit to\n"
            . "    keep a smaller or \"cleaner\" diff. The change is reproducible from the config,\n"
            . "    so a local revert is simply re-applied on the next run — reverting Rector is\n"
            . "    wasted effort and is forbidden.\n"
            . "  - KEEP every change. When a Rector edit surfaces a DOWNSTREAM error (most\n"
            . "    commonly a PHPStan type error from a newly-precise \\Safe\\* return type, or\n"
            . "    mutation debt dragged in when a touched file enters the diff-MSI gate), fix\n"
            . "    the CAUSE the honest way — narrow the type, write the missing test — NEVER by\n"
            . "    reverting the Rector edit or suppressing the follow-on check.\n"
            . "  - COMMIT what Rector produced. Prefer an ATOMIC commit of just the autofix\n"
            . "    output (e.g. \"style: apply Rector autofixes\"), kept separate from your\n"
            . "    feature/bugfix change so the mechanical rewrite is trivial to review.\n"
            . "\n"
            . "Roll with Rector. It is part of the gate, not an obstacle to it.\n"
            . $rule
            . "\n",
            $this->factory->output->fetch(),
        );
    }

    #[Test]
    public function everyPassRunsThePharFromTheProjectRootWithTheIgnorePathsEnv(): void
    {
        $this->queuePasses(0, 0, 0);
        $config  = $this->factory->builder(readOnly: true)->withIgnoredPaths('legacy', 'generated')->build();
        $context = $this->factory->context($config);

        new RectorTool()->run($context);

        foreach ($this->rectorSpecs() as $spec) {
            self::assertSame($config->paths->projectRoot, $spec->cwd);
            self::assertSame(
                [
                    'rectorIgnorePaths' => "legacy\ngenerated",
                    'rectorCacheDir'    => $config->paths->cacheDir . self::RECTOR_TMP,
                    'TMPDIR'            => $config->paths->cacheDir . self::RECTOR_TMP,
                    'XDEBUG_MODE'       => 'off',
                ],
                $spec->env,
            );
            self::assertContains($config->paths->pharDir . '/rector.phar', $spec->command);
        }
    }

    #[Test]
    public function aProjectConfigCallingCacheClassWithARemovedStorageStopsTheLaneNamingTheLineAndTheFix(): void
    {
        $this->factory->project->write('qaConfig/rector.php', $this->memoryCacheConfig());
        $this->factory->processes->willSucceed()->willSucceed()->willFail(1, self::MEMORY_CACHE_REJECTED);
        $context = $this->context(readOnly: false);

        $result = new RectorTool()->run($context);
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome, 'a retry cannot help, so the lane crashes even in a writable run');
        self::assertCount(3, $this->rectorSpecs());
        self::assertStringContainsString('qaConfig/rector.php:9', $printed);
        self::assertStringContainsString('$rectorConfig->cacheClass(MemoryCacheStorage::class);', $printed);
        self::assertStringContainsString('Remove the cacheClass(...) call', $printed);
        self::assertStringContainsString('var/qa/cache/rector', $printed);
        self::assertStringContainsString(RectorTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function aRejectedCacheClassIsRecognisedWhateverWidthRectorWrappedItsMessageTo(): void
    {
        $this->factory->project->write('qaConfig/rector.php', $this->memoryCacheConfig());
        $this->factory->processes->willSucceed()->willSucceed()->willFail(1, str_replace(' among its', "\n         among its", self::MEMORY_CACHE_REJECTED));

        $result = new RectorTool()->run($this->context(readOnly: true));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertStringContainsString('qaConfig/rector.php:9', $this->factory->output->fetch());
    }

    #[Test]
    public function anOrdinaryRectorErrorIsStillAGenuineError(): void
    {
        $this->factory->project->write('qaConfig/rector.php', $this->memoryCacheConfig());
        $this->factory->processes->willSucceed()->willSucceed()->willFail(1, " [ERROR] Undefined constant Rector\\Set\\ValueObject\\SetList::GONE\n");

        $result  = new RectorTool()->run($this->context(readOnly: true));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertStringContainsString('(a genuine error, not a pending-change diff)', $printed);
        self::assertStringNotContainsString('Remove the cacheClass(...) call', $printed);
    }

    #[Test]
    public function eachPassGetsAProjectOwnedTempDirectorySoConcurrentProjectsDoNotShareRectorsCache(): void
    {
        $this->queuePasses(0, 0, 0);
        $config = $this->factory->builder(readOnly: true)->build();
        $tmpDir = $config->paths->cacheDir . self::RECTOR_TMP;
        self::assertDirectoryDoesNotExist($tmpDir);

        new RectorTool()->run($this->factory->context($config));

        self::assertDirectoryExists($tmpDir);
        self::assertSame($tmpDir, array_last($this->rectorSpecs())?->env['TMPDIR'] ?? null);
    }

    #[Test]
    public function withNoIgnoredPathsTheEnvIsAnEmptyString(): void
    {
        $this->queuePasses(0, 0, 0);

        new RectorTool()->run($this->context(readOnly: true));

        self::assertSame('', array_last($this->rectorSpecs())?->env['rectorIgnorePaths'] ?? null);
    }

    #[Test]
    public function aProjectRectorReplacesThePhp85Pass(): void
    {
        $this->queuePasses(0, 0, 0, 0);
        $rootConfig     = $this->factory->project->write('rector.php', self::EMPTY_CONFIG);
        $qaConfigConfig = $this->factory->project->write('qaConfig/rector.php', self::EMPTY_CONFIG);
        $context        = $this->context(readOnly: true);

        $result = new RectorTool()->run($context);

        self::assertTrue($result->isSuccess());
        self::assertCount(4, $this->rectorSpecs());
        self::assertSame($this->expectedArgsForConfig($context, $rootConfig, true, ...$context->config->pathsToCheck), $this->toolArgs(2));
        self::assertSame($this->expectedArgsForConfig($context, $qaConfigConfig, true, ...$context->config->pathsToCheck), $this->toolArgs(3));

        $printed = $this->factory->output->fetch();
        self::assertStringContainsString('Running Project Specific Rector as configured in ' . $rootConfig, $printed);
        self::assertStringContainsString('Running Project Specific Rector as configured in ' . $qaConfigConfig, $printed);
        self::assertStringContainsString('Skipping standard PHP 8.5 Rector as we assume its handled in project rector', $printed);
        self::assertStringNotContainsString(self::PHP85_CONFIG, implode("\n", $this->factory->processes->commandLines()));
    }

    #[Test]
    public function aReadOnlyPendingChangeFailsWithTheRemediationAndStopsAtThatPass(): void
    {
        $this->queuePasses(0, 2);

        $result  = new RectorTool()->run($this->context(readOnly: true));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertCount(2, $this->rectorSpecs(), 'the PHP 8.5 pass never runs');
        self::assertStringContainsString("Rector ('PHPUnit'): pending changes in a READ-ONLY run", $printed);
        self::assertStringContainsString('QA_READONLY=0 vendor/bin/qa -t rector', $printed);
        self::assertStringContainsString(RectorTool::IDENTIFIER, $printed);
        self::assertStringNotContainsString(self::ADVISORY, $printed);
    }

    #[Test]
    public function aReadOnlyGenuineErrorCrashes(): void
    {
        $this->queuePasses(1);

        $result  = new RectorTool()->run($this->context(readOnly: true));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertCount(1, $this->rectorSpecs());
        self::assertStringContainsString("Rector ('Safe') failed with exit code 1 (a genuine error, not a pending-change diff)", $printed);
        self::assertStringContainsString(RectorTool::IDENTIFIER, $printed);
        self::assertStringNotContainsString('pending changes in a READ-ONLY run', $printed);
    }

    #[Test]
    public function aWritableNonZeroExitFailsSoTheRunnerCanRetry(): void
    {
        $this->queuePasses(0, 0, 2);

        $result  = new RectorTool()->run($this->context(readOnly: false));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString("Rector ('PHP 8.5') failed (exit 2)", $result->summary);
        self::assertStringContainsString(RectorTool::IDENTIFIER, $printed);
        self::assertStringNotContainsString('pending changes in a READ-ONLY run', $printed);
        self::assertStringNotContainsString(self::ADVISORY, $printed, 'the advisory is only printed after every pass succeeded');
    }

    #[Test]
    public function aMissingPharCrashesBeforeRunningAnything(): void
    {
        $paths   = $this->factory->paths();
        $noPhars = new ProjectPathsDto(
            projectRoot: $paths->projectRoot,
            libraryRoot: $paths->libraryRoot,
            binDir: $paths->binDir,
            srcDir: $paths->srcDir,
            testsDir: $paths->testsDir,
            projectConfigDir: $paths->projectConfigDir,
            varDir: $paths->varDir,
            cacheDir: $paths->cacheDir,
            pharDir: $this->factory->project->mkdir('no-phars'),
            configDefaultsDir: $paths->configDefaultsDir,
        );
        $config = QaConfigBuilder::defaults(
            paths: $noPhars,
            platform: PlatformEnum::Generic,
            env: new EnvironmentReader([]),
            phpBinPath: '/usr/bin/php',
            xdebugEnabled: true,
            halfCpuThreads: 2,
            ci: true,
            readOnly: true,
            aggregate: true,
            jsonOutput: false,
            agentMode: false,
            singleTool: null,
            specifiedPath: null,
        )->build();

        $result  = new RectorTool()->run($this->factory->context($config));
        $printed = $this->factory->output->fetch();

        $phar = $noPhars->pharDir . '/rector.phar';
        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame('Rector PHAR not found at ' . $phar, $result->summary);
        self::assertSame([], $this->rectorSpecs());
        self::assertSame(
            'ERROR: Rector PHAR not found at ' . $phar . "\n"
            . "It should be committed under vendor-phar/. Reinstall php-qa-ci, or\n"
            . "(maintainer) rebuild it: scripts/build-rector-phar.bash\n"
            . self::IDENTIFIER_FOOTER,
            $printed,
        );
    }

    /** Each project config is looked for on its own: a missing root rector.php does not end the search. */
    #[Test]
    public function aQaConfigRectorWithNoRootRectorStillReplacesThePhp85Pass(): void
    {
        $this->queuePasses(0, 0, 0);
        $qaConfigConfig = $this->factory->project->write('qaConfig/rector.php', self::EMPTY_CONFIG);
        $context        = $this->context(readOnly: true);

        $result = new RectorTool()->run($context);

        self::assertTrue($result->isSuccess());
        self::assertCount(3, $this->rectorSpecs());
        self::assertSame($this->expectedArgsForConfig($context, $qaConfigConfig, true, ...$context->config->pathsToCheck), $this->toolArgs(2));
        self::assertStringContainsString('Skipping standard PHP 8.5 Rector as we assume its handled in project rector', $this->factory->output->fetch());
    }

    /** `tests` begins with `test`, but `test/` is not its parent, so the PHPUnit pass has nothing to run on. */
    #[Test]
    public function aCheckedPathThatIsOnlyAStringPrefixOfTheTestsDirectoryDoesNotContainIt(): void
    {
        $this->queuePasses(0, 0);
        $context = $this->factory->context($this->factory->builder(readOnly: true, specifiedPath: 'test')->build());

        new RectorTool()->run($context);

        self::assertCount(2, $this->rectorSpecs(), 'only the Safe and PHP 8.5 passes run');
        self::assertStringContainsString('Skipping PHPUnit Rector: no checked path is inside ' . $context->config->paths->testsDir, $this->factory->output->fetch());
    }

    #[Test]
    public function twoCheckedPathsThatBothNameTheTestsDirectoryGiveThePhpunitPassItOnce(): void
    {
        $this->queuePasses(0, 0, 0);
        $config  = $this->factory->builder(readOnly: true, specifiedPath: '.')->withCheckedPaths('tests')->build();
        $context = $this->factory->context($config);

        new RectorTool()->run($context);

        self::assertSame($this->expectedArgs($context, self::PHPUNIT_CONFIG, true, $config->paths->testsDir), $this->toolArgs(1));
    }

    #[Test]
    public function theRectorTempDirectoryIsCreatedWithFullPermissionsLessTheUmask(): void
    {
        $this->queuePasses(0, 0, 0);
        $config   = $this->factory->builder(readOnly: true)->build();
        $previous = umask(0o022);

        try {
            new RectorTool()->run($this->factory->context($config));
        } finally {
            umask($previous);
        }

        self::assertSame(0o755, \Safe\fileperms($config->paths->cacheDir . self::RECTOR_TMP) & 0o7777);
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new RectorTool();

        self::assertSame('rector', $tool->name());
        self::assertSame('phpqaci.rector', $tool->identifier());
    }

    /** A project rector.php of the kind copied from the older shipped rector-safe.php; the call is on line 9. */
    private function memoryCacheConfig(): string
    {
        return implode("\n", [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'use Rector\Caching\ValueObject\Storage\MemoryCacheStorage;',
            'use Rector\Config\RectorConfig;',
            '',
            'return static function (RectorConfig $rectorConfig): void {',
            '    $rectorConfig->cacheClass(MemoryCacheStorage::class);',
            '};',
            '',
        ]);
    }

    /** Queue one Rector pass per exit code. */
    private function queuePasses(int ...$exitCodes): void
    {
        foreach ($exitCodes as $exitCode) {
            if (0 === $exitCode) {
                $this->factory->processes->willSucceed();
            } else {
                $this->factory->processes->willFail($exitCode);
            }
        }
    }

    /** @return list<ProcessSpecDto> the Rector invocations made by this run */
    private function rectorSpecs(): array
    {
        return array_values(array_filter(
            $this->factory->processes->specs,
            static fn (ProcessSpecDto $spec): bool => \in_array('-f', $spec->command, true),
        ));
    }

    private function context(bool $readOnly): ToolContext
    {
        return $this->factory->context($this->factory->builder(readOnly: $readOnly)->build());
    }

    /** @return list<string> the argv after the `--` separator, i.e. what Rector itself sees */
    private function toolArgs(int $index): array
    {
        $spec      = $this->rectorSpecs()[$index];
        $separator = array_search('--', $spec->command, true);
        self::assertIsInt($separator);

        return \array_slice($spec->command, $separator + 1);
    }

    /** @return list<string> */
    private function expectedArgs(ToolContext $context, string $shippedConfig, bool $dryRun, string ...$paths): array
    {
        return $this->expectedArgsForConfig($context, $context->configPath($shippedConfig), $dryRun, ...$paths);
    }

    /** @return list<string> */
    private function expectedArgsForConfig(ToolContext $context, string $configFile, bool $dryRun, string ...$paths): array
    {
        $args = [
            'process',
            '--autoload-file',
            $context->config->paths->projectRoot . '/vendor/autoload.php',
            '--config',
            $configFile,
            '--clear-cache',
        ];
        if ($dryRun) {
            $args[] = '--dry-run';
        }

        return [...$args, ...array_values($paths)];
    }
}
