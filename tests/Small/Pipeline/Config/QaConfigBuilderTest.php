<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Config;

use LogicException;
use LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto;
use LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto;
use LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto;
use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;
use LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(QaConfigBuilder::class)]
#[CoversClass(QaConfigDto::class)]
#[CoversClass(PhpUnitOptionsDto::class)]
#[CoversClass(InfectionOptionsDto::class)]
#[CoversClass(DeadCodeOptionsDto::class)]
#[CoversClass(ProjectPathsDto::class)]
#[CoversClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(EnvironmentReader::class)]
#[Small]
final class QaConfigBuilderTest extends TestCase
{
    private const string PROJECT_TESTS = '/p/tests';

    private const string PROJECT_SRC = '/p/src';

    private const string TEMPLATES_DIR = '/p/templates';

    private const string EXPECTED_LOGIC_EXCEPTION = 'expected LogicException';

    private const string SRC_WATCHED = 'src/';

    private const string DIFF_BASE = 'origin/php8.5';

    private const string ENV_DIFF_BASE = 'origin/main';

    private const string GENERATED_DIR = 'src/Generated';

    private const string ASSETS_DIR = 'tests/assets';

    private const string ARKITECT_EXCLUDED = 'Quote/API';

    #[Test]
    public function defaultsMirrorThePipelineDefaults(): void
    {
        $config = $this->defaults()->build();

        self::assertSame([self::PROJECT_TESTS, self::PROJECT_SRC], $config->pathsToCheck);
        self::assertSame([], $config->pathsToIgnore);
        self::assertSame('4G', $config->memoryLimit);
        self::assertFalse($config->quickTests);
        self::assertTrue($config->phpUnit->coverage);
        self::assertFalse($config->phpUnit->quickTests);
        self::assertFalse($config->phpUnit->iterativeMode);
        self::assertTrue($config->infection->enabled);
        self::assertSame(4, $config->infection->threads);
        self::assertSame(60, $config->infection->minMsi);
        self::assertSame(80, $config->infection->minCoveredMsi);
        self::assertNull($config->infection->diffBase);
        self::assertSame(100, $config->infection->diffCoveredMsi);
        self::assertTrue($config->useComposerAudit);
        self::assertTrue($config->useArkitect);
        self::assertTrue($config->useSensitiveParameterCheck);
        // templates/ on every platform: twigCsFixer is gated on Twig, not Symfony.
        self::assertSame([self::TEMPLATES_DIR], $config->twigDirectories);
        self::assertSame(PlatformEnum::Generic, $config->platform);
    }

    #[Test]
    public function theEnvironmentSeedsEveryAdjustableValue(): void
    {
        $env = new EnvironmentReader([
            'phpqaMemoryLimit'           => '8G',
            'phpqaQuickTests'            => '1',
            'phpUnitCoverage'            => '0',
            'phpUnitQuickTests'          => '1',
            'phpUnitIterativeMode'       => '1',
            'useInfection'               => '0',
            'infectionThreads'           => '2',
            'infectionOnlyCovered'       => '1',
            'mutationScoreIndicator'     => '70',
            'coveredCodeMSI'             => '90',
            'infectionDiffBase'          => self::ENV_DIFF_BASE,
            'infectionDiffCoveredMsi'    => '95',
            'useComposerAudit'           => '0',
            'useArkitect'                => '0',
            'useSensitiveParameterCheck' => '0',
        ]);

        $config = $this->defaults(env: $env)->build();

        self::assertSame('8G', $config->memoryLimit);
        self::assertTrue($config->quickTests);
        self::assertFalse($config->phpUnit->coverage);
        self::assertTrue($config->phpUnit->quickTests);
        self::assertTrue($config->phpUnit->iterativeMode);
        self::assertFalse($config->infection->enabled);
        self::assertSame(2, $config->infection->threads);
        self::assertTrue($config->infection->onlyCovered);
        self::assertSame(70, $config->infection->minMsi);
        self::assertSame(90, $config->infection->minCoveredMsi);
        self::assertSame(self::ENV_DIFF_BASE, $config->infection->diffBase);
        self::assertSame(95, $config->infection->diffCoveredMsi);
        self::assertFalse($config->useComposerAudit);
        self::assertFalse($config->useArkitect);
        self::assertFalse($config->useSensitiveParameterCheck);
    }

    #[Test]
    public function aSpecifiedPathReplacesThePathsToCheck(): void
    {
        $config = $this->defaults(specifiedPath: 'src/Domain')->build();

        self::assertSame(['/p/src/Domain'], $config->pathsToCheck);
        self::assertSame('src/Domain', $config->specifiedPath);
    }

    #[Test]
    public function withoutXdebugCoverageAndInfectionAreForcedOff(): void
    {
        $config = $this->defaults(xdebug: false)->withPhpUnitCoverage(true)->withInfection(true)->build();

        self::assertFalse($config->xdebugEnabled);
        self::assertFalse($config->phpUnit->coverage);
        self::assertFalse($config->infection->enabled);
    }

    #[Test]
    public function turningCoverageOffTurnsInfectionOff(): void
    {
        $config = $this->defaults()->withPhpUnitCoverage(false)->build();

        self::assertFalse($config->phpUnit->coverage);
        self::assertFalse($config->infection->enabled);
    }

    #[Test]
    public function withersReturnNewInstancesAndLeaveTheOriginalAlone(): void
    {
        $base     = $this->defaults();
        $adjusted = $base
            ->withMemoryLimit('16G')
            ->withIgnoredPaths(self::ASSETS_DIR, self::GENERATED_DIR)
            ->withCheckedPaths('lib')
            ->withInfectionFloors(msi: 82, coveredMsi: 84)
            ->withInfectionThreads(0)
            ->withInfectionDiffBase(self::DIFF_BASE, 95)
            ->withComposerAudit(false)
            ->withArkitect(false)
            ->withArkitectExcludedPaths(self::ARKITECT_EXCLUDED)
            ->withSensitiveParameterCheck(false)
            ->withTwigDirectories('templates', '/abs/views')
            ->withYamlDirectories('config')
        ;

        self::assertSame('4G', $base->build()->memoryLimit);

        $config = $adjusted->build();
        self::assertSame('16G', $config->memoryLimit);
        self::assertSame([self::ASSETS_DIR, self::GENERATED_DIR], $config->pathsToIgnore);
        self::assertSame([self::PROJECT_TESTS, self::PROJECT_SRC, '/p/lib'], $config->pathsToCheck);
        self::assertSame(82, $config->infection->minMsi);
        self::assertSame(84, $config->infection->minCoveredMsi);
        self::assertSame(1, $config->infection->threads);
        self::assertSame(self::DIFF_BASE, $config->infection->diffBase);
        self::assertSame(95, $config->infection->diffCoveredMsi);
        self::assertFalse($config->useComposerAudit);
        self::assertFalse($config->useArkitect);
        self::assertSame([self::ARKITECT_EXCLUDED], $config->arkitectExcludePaths);
        self::assertFalse($config->useSensitiveParameterCheck);
        self::assertSame([self::TEMPLATES_DIR, '/abs/views'], $config->twigDirectories);
        self::assertSame(['/p/config'], $config->yamlDirectories);
    }

    #[Test]
    public function everyWitherOverridesItsDefaultAndTheListWithersAccumulate(): void
    {
        $config = $this->defaults()
            ->withIgnoredPaths(self::ASSETS_DIR)
            ->withIgnoredPaths(self::GENERATED_DIR, 'var/')
            ->withCheckedPaths('/lib', 'tools')
            ->withPhpUnitIterativeMode(true)
            ->withInfection(false)
            ->withInfectionDiffBase(self::DIFF_BASE)
            ->withTypeCoverageFloors(returnType: 90, declare: 100)
            ->withArkitectExcludedPaths(self::ARKITECT_EXCLUDED, 'Legacy')
            ->withArkitectExcludedPaths('Generated')
            ->withYamlDirectories('cfg')
            ->withShellCheckGlobs('scripts/*.bash', 'ci.bash')
            ->withShellCheckGlobs('bin/*')
            ->withDeadCodeDetection(true)
            ->withDeadCodeEntryPoints('bin/console', 'bin/tool')
            ->build()
        ;

        self::assertSame([self::ASSETS_DIR, self::GENERATED_DIR, 'var/'], $config->pathsToIgnore);
        self::assertSame([self::PROJECT_TESTS, self::PROJECT_SRC, '/p/lib', '/p/tools'], $config->pathsToCheck);
        self::assertTrue($config->phpUnit->iterativeMode);
        self::assertFalse($config->infection->enabled);
        self::assertSame(self::DIFF_BASE, $config->infection->diffBase);
        self::assertSame(100, $config->infection->diffCoveredMsi);
        self::assertSame(90, $config->typeCoverage->returnType);
        self::assertNull($config->typeCoverage->paramType);
        self::assertSame(100, $config->typeCoverage->declare);
        self::assertSame([self::ARKITECT_EXCLUDED, 'Legacy', 'Generated'], $config->arkitectExcludePaths);
        self::assertSame(['/p/cfg'], $config->yamlDirectories);
        self::assertSame(['scripts/*.bash', 'ci.bash', 'bin/*'], $config->shellCheckGlobs);
        self::assertSame(['/p/bin/console', '/p/bin/tool'], $config->deadCode->entryPoints);
    }

    #[Test]
    public function aProjectDiffBaseWinsOverTheEnvironmentOne(): void
    {
        $config = $this->defaults(env: new EnvironmentReader(['infectionDiffBase' => self::ENV_DIFF_BASE]))->withInfectionDiffBase(self::DIFF_BASE, 90)->build();

        self::assertSame(self::DIFF_BASE, $config->infection->diffBase);
        self::assertSame(90, $config->infection->diffCoveredMsi);
    }

    #[Test]
    public function infectionMutatesUncoveredCodeTooByDefault(): void
    {
        self::assertFalse($this->defaults()->build()->infection->onlyCovered);
    }

    #[Test]
    public function deadCodeDetectionIsOffByDefaultWithNoEntryPoints(): void
    {
        $deadCode = $this->defaults()->build()->deadCode;

        self::assertFalse($deadCode->enabled);
        self::assertSame([], $deadCode->entryPoints);
    }

    #[Test]
    public function enablingDeadCodeDetectionWithoutAnEntryPointDecisionRefusesToBuild(): void
    {
        try {
            $this->defaults()->withDeadCodeDetection(true)->build();
            self::fail(self::EXPECTED_LOGIC_EXCEPTION);
        } catch (LogicException $logicException) {
            self::assertStringContainsString('withDeadCodeEntryPoints(...)', $logicException->getMessage());
        }
    }

    #[Test]
    public function deadCodeEntryPointsAreResolvedUnderTheProjectRootAndAccumulate(): void
    {
        $config = $this->defaults()
            ->withDeadCodeDetection(true)
            ->withDeadCodeEntryPoints('bin/console')
            ->withDeadCodeEntryPoints('/abs/tool')
            ->build()
        ;

        self::assertTrue($config->deadCode->enabled);
        self::assertSame(['/p/bin/console', '/abs/tool'], $config->deadCode->entryPoints);
    }

    #[Test]
    public function optingOutOfEntryPointsIsAnExplicitEmptyList(): void
    {
        $config = $this->defaults()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        self::assertTrue($config->deadCode->enabled);
        self::assertSame([], $config->deadCode->entryPoints);
    }

    #[Test]
    public function theChangelogCheckIsOffByDefaultWithNothingWatched(): void
    {
        $config = $this->defaults()->build();

        self::assertFalse($config->useChangelogCheck);
        self::assertSame([], $config->changelogWatchedPaths);
    }

    #[Test]
    public function enablingTheChangelogCheckWithoutWatchedPathsRefusesToBuild(): void
    {
        try {
            $this->defaults()->withChangelogCheck(true)->build();
            self::fail(self::EXPECTED_LOGIC_EXCEPTION);
        } catch (LogicException $logicException) {
            self::assertStringContainsString('withChangelogWatchedPaths(...)', $logicException->getMessage());
        }

        try {
            $this->defaults(env: new EnvironmentReader(['useChangelogCheck' => '1']))->build();
            self::fail(self::EXPECTED_LOGIC_EXCEPTION);
        } catch (LogicException $logicException) {
            self::assertStringContainsString('useChangelogCheck=1', $logicException->getMessage());
        }
    }

    #[Test]
    public function changelogWatchedPathsAccumulateAndTheEnvironmentCanSwitchTheCheckOn(): void
    {
        $config = $this->defaults(env: new EnvironmentReader(['useChangelogCheck' => 'true']))
            ->withChangelogWatchedPaths(self::SRC_WATCHED, 'composer.json')
            ->withChangelogWatchedPaths('bin/')
            ->build()
        ;

        self::assertTrue($config->useChangelogCheck);
        self::assertSame([self::SRC_WATCHED, 'composer.json', 'bin/'], $config->changelogWatchedPaths);
        self::assertFalse($this->defaults()->withChangelogWatchedPaths(self::SRC_WATCHED)->withChangelogCheck(false)->build()->useChangelogCheck);
    }

    #[Test]
    public function everyPlatformGetsTheTwigAndYamlDirectoriesByDefault(): void
    {
        foreach (PlatformEnum::cases() as $platform) {
            $config = $this->defaults(platform: $platform)->build();

            self::assertSame([self::TEMPLATES_DIR], $config->twigDirectories, $platform->name);
            self::assertSame(['/p/config'], $config->yamlDirectories, $platform->name);
        }
    }

    #[Test]
    public function halfCpuThreadsIsNeverBelowOne(): void
    {
        self::assertSame(1, $this->defaults(halfCpu: 0)->build()->halfCpuThreads);
    }

    private function defaults(
        ?EnvironmentReader $env = null,
        ?string $specifiedPath = null,
        bool $xdebug = true,
        PlatformEnum $platform = PlatformEnum::Generic,
        int $halfCpu = 4,
    ): QaConfigBuilder {
        $paths = new ProjectPathsDto(
            projectRoot: '/p',
            libraryRoot: '/lib',
            binDir: '/p/vendor/bin',
            srcDir: self::PROJECT_SRC,
            testsDir: self::PROJECT_TESTS,
            projectConfigDir: '/p/qaConfig',
            varDir: '/p/var/qa',
            cacheDir: '/p/var/qa/cache',
            pharDir: '/lib/vendor-phar',
            configDefaultsDir: '/lib/configDefaults',
        );

        return QaConfigBuilder::defaults(
            paths: $paths,
            platform: $platform,
            env: $env ?? new EnvironmentReader([]),
            phpBinPath: '/usr/bin/php',
            xdebugEnabled: $xdebug,
            halfCpuThreads: $halfCpu,
            ci: true,
            readOnly: true,
            aggregate: true,
            jsonOutput: false,
            agentMode: false,
            singleTool: null,
            specifiedPath: $specifiedPath,
        );
    }
}
