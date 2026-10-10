<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Changelog;

use LTS\PHPQA\Changelog\Exception\ChangelogReleaseException;
use LTS\PHPQA\Changelog\ReleaseBumpEnum;
use LTS\PHPQA\Changelog\ReleaseLine;
use LTS\PHPQA\Changelog\ReleaseVersionPolicy;
use LTS\PHPQA\Changelog\ReleaseVersionPolicyLoader;
use LTS\PHPQA\Pipeline\Config\ComposerBinDirReader;
use LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto;
use LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto;
use LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto;
use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;
use LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto;
use LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Config\PlatformDetector;
use LTS\PHPQA\Pipeline\Config\ProjectConfigLoader;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * `bin/changelog-release` runs outside the pipeline, so it reads the release
 * policy from the project's qaConfig/qa.php itself.
 *
 * @internal
 */
#[CoversClass(ReleaseVersionPolicyLoader::class)]
#[UsesClass(ChangelogReleaseException::class)]
#[UsesClass(ReleaseLine::class)]
#[UsesClass(ReleaseVersionPolicy::class)]
#[UsesClass(ComposerBinDirReader::class)]
#[UsesClass(DeadCodeOptionsDto::class)]
#[UsesClass(InfectionOptionsDto::class)]
#[UsesClass(PhpUnitOptionsDto::class)]
#[UsesClass(ProjectPathsDto::class)]
#[UsesClass(QaConfigDto::class)]
#[UsesClass(TypeCoverageOptionsDto::class)]
#[UsesClass(EnvironmentReader::class)]
#[UsesClass(PlatformDetector::class)]
#[UsesClass(ProjectConfigLoader::class)]
#[UsesClass(QaConfigBuilder::class)]
#[Small]
final class ReleaseVersionPolicyLoaderTest extends TestCase
{
    private const string QA_PHP = 'qaConfig/qa.php';

    private const string SEEN = 'qaConfig/seen.json';

    private const string RECORD_SEED = <<<'PHP_WRAP'
        <?php
        return static function (\LTS\PHPQA\Pipeline\Config\QaConfigBuilder $qa): \LTS\PHPQA\Pipeline\Config\QaConfigBuilder {
            $config = $qa->build();
            file_put_contents(__DIR__ . '/seen.json', json_encode([
                ...get_object_vars($config->paths),
                'phpBinPath'     => $config->phpBinPath,
                'xdebugEnabled'  => $config->xdebugEnabled,
                'halfCpuThreads' => $config->halfCpuThreads,
                'ci'             => $config->ci,
                'readOnly'       => $config->readOnly,
                'aggregate'      => $config->aggregate,
                'jsonOutput'     => $config->jsonOutput,
                'agentMode'      => $config->agentMode,
                'singleTool'     => $config->singleTool,
                'specifiedPath'  => $config->specifiedPath,
            ], JSON_THROW_ON_ERROR));

            return $qa;
        };
        PHP_WRAP;

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-release-policy');
        $this->project->write('composer.json', '{"require": {"php": "^8.5"}}');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function aProjectWithNoConfigReleasesBySemanticVersioning(): void
    {
        self::assertEquals(ReleaseVersionPolicy::semanticVersioning(), new ReleaseVersionPolicyLoader()->load($this->project->path));
    }

    /** Without a qa.php nothing about the project is read, so not even a composer.json is needed. */
    #[Test]
    public function aProjectWithNoConfigNeedsNoComposerJson(): void
    {
        \Safe\unlink($this->project->path . '/composer.json');

        self::assertEquals(ReleaseVersionPolicy::semanticVersioning(), new ReleaseVersionPolicyLoader()->load($this->project->path));
    }

    /** A qa.php that loads for bin/qa may read the paths and modes it is seeded with, so they are the pipeline's. */
    #[Test]
    public function theConfigIsSeededWithTheProjectsPathsAndAReadOnlyCiRun(): void
    {
        $this->project->write(self::QA_PHP, self::RECORD_SEED);
        $library = \dirname(__DIR__, 3);
        $root    = $this->project->path;

        new ReleaseVersionPolicyLoader()->load($root . '/');

        self::assertSame([
            'projectRoot'       => $root,
            'libraryRoot'       => $library,
            'binDir'            => $root . '/vendor/bin',
            'srcDir'            => $root . '/src',
            'testsDir'          => $root . '/tests',
            'projectConfigDir'  => $root . '/qaConfig',
            'varDir'            => $root . '/var/qa',
            'cacheDir'          => $root . '/var/qa/cache',
            'pharDir'           => $library . '/vendor-phar',
            'configDefaultsDir' => $library . '/configDefaults',
            'phpBinPath'        => 'php',
            'xdebugEnabled'     => false,
            'halfCpuThreads'    => 1,
            'ci'                => true,
            'readOnly'          => true,
            'aggregate'         => false,
            'jsonOutput'        => false,
            'agentMode'         => false,
            'singleTool'        => null,
            'specifiedPath'     => null,
        ], \Safe\json_decode($this->project->read(self::SEEN), true));
    }

    #[Test]
    public function aProjectWhoseConfigSaysNothingAboutReleasesReleasesBySemanticVersioning(): void
    {
        $this->project->write(self::QA_PHP, "<?php\nreturn static fn (\\LTS\\PHPQA\\Pipeline\\Config\\QaConfigBuilder \$qa) => \$qa->withMemoryLimit('2G');\n");

        self::assertEquals(ReleaseVersionPolicy::semanticVersioning(), new ReleaseVersionPolicyLoader()->load($this->project->path));
    }

    #[Test]
    public function thePolicyTheProjectDeclaresIsThePolicyItReleasesBy(): void
    {
        $this->project->write(self::QA_PHP, '<?php
return static fn (\LTS\PHPQA\Pipeline\Config\QaConfigBuilder $qa) => $qa->withReleaseVersionPolicy(' . ReleaseVersionPolicy::class . '::lockedMajor(7, \'v\'));
');

        self::assertEquals(ReleaseVersionPolicy::lockedMajor(7, 'v'), new ReleaseVersionPolicyLoader()->load($this->project->path));
    }

    #[Test]
    public function phpQaCiDeclaresItsMajorLockedToThePhpLine(): void
    {
        $root   = \dirname(__DIR__, 3);
        $policy = new ReleaseVersionPolicyLoader()->load($root);

        self::assertEquals(ReleaseVersionPolicy::lockedMajorFromPhpRequirement(), $policy);
        self::assertSame('85.3.0', $policy->line(\Safe\file_get_contents($root . '/composer.json'))->next(ReleaseBumpEnum::Major, '85.0.0', '85.2.0', '85.1.0'));
    }

    #[Test]
    public function aConfigThatDoesNotLoadFailsNamingTheFile(): void
    {
        $this->project->write(self::QA_PHP, "<?php\nreturn 'not a closure';\n");

        $this->expectException(ChangelogReleaseException::class);
        $this->expectExceptionMessageIsOrContains('cannot read the release policy from qaConfig/qa.php: ');

        new ReleaseVersionPolicyLoader()->load($this->project->path);
    }

    #[Test]
    public function aConfigThatDoesNotBuildFailsNamingTheFile(): void
    {
        $this->project->write(self::QA_PHP, "<?php\nreturn static fn (\\LTS\\PHPQA\\Pipeline\\Config\\QaConfigBuilder \$qa) => \$qa->withChangelogCheck(true);\n");

        try {
            new ReleaseVersionPolicyLoader()->load($this->project->path);
            self::fail('expected the unbuildable config to be refused');
        } catch (ChangelogReleaseException $changelogReleaseException) {
            self::assertStringStartsWith('cannot read the release policy from qaConfig/qa.php: withChangelogCheck(true)', $changelogReleaseException->getMessage());
            self::assertNotNull($changelogReleaseException->getPrevious());
            self::assertSame(0, $changelogReleaseException->getCode());
        }
    }
}
