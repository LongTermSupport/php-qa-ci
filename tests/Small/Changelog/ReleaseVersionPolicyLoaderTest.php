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
        }
    }
}
