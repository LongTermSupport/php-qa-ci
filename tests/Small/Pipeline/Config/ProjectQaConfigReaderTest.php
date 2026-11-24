<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Config;

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
use LTS\PHPQA\Pipeline\Config\ProjectQaConfigReader;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A program that runs outside the pipeline (the release CLI, the actions-qa-ci installer) reads
 * the project's qaConfig/qa.php through the loader the pipeline uses, seeded as a read-only CI
 * run is, so a qa.php that loads for `bin/qa` loads for it.
 *
 * @internal
 */
#[CoversClass(ProjectQaConfigReader::class)]
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
#[UsesClass(\LTS\PHPQA\Pipeline\Config\CoverageDriverEnum::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class ProjectQaConfigReaderTest extends TestCase
{
    private const string QA_PHP = 'qaConfig/qa.php';

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-project-config-reader');
        $this->project->write('composer.json', '{"require": {"php": "^8.5"}}');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /** Without a qa.php nothing about the project is read, so not even a composer.json is needed. */
    #[Test]
    public function aProjectWithNoConfigHasNoneToRead(): void
    {
        \Safe\unlink($this->project->path . '/composer.json');

        self::assertNull(new ProjectQaConfigReader()->read($this->project->path, $this->library()));
    }

    #[Test]
    public function theSettingsTheProjectDeclaresAreRead(): void
    {
        $this->project->write(self::QA_PHP, "<?php\nreturn static fn (\\LTS\\PHPQA\\Pipeline\\Config\\QaConfigBuilder \$qa) => \$qa->withOfflineBuild(true)->withMemoryLimit('2G');\n");

        $config = new ProjectQaConfigReader()->read($this->project->path . '/', $this->library());

        self::assertNotNull($config);
        self::assertTrue($config->offlineBuild);
        self::assertSame('2G', $config->memoryLimit);
        self::assertSame($this->project->path, $config->paths->projectRoot);
        self::assertSame($this->library(), $config->paths->libraryRoot);
        self::assertTrue($config->ci);
        self::assertTrue($config->readOnly);
    }

    #[Test]
    public function aConfigThatSaysNothingOfTheNetworkIsOnline(): void
    {
        $this->project->write(self::QA_PHP, "<?php\nreturn static fn (\\LTS\\PHPQA\\Pipeline\\Config\\QaConfigBuilder \$qa) => \$qa;\n");

        self::assertFalse(new ProjectQaConfigReader()->read($this->project->path, $this->library())?->offlineBuild);
    }

    #[Test]
    public function aConfigThatDoesNotLoadIsAnErrorForTheCallerToName(): void
    {
        $this->project->write(self::QA_PHP, "<?php\nreturn 'not a closure';\n");

        $this->expectException(RuntimeException::class);

        new ProjectQaConfigReader()->read($this->project->path, $this->library());
    }

    private function library(): string
    {
        return \dirname(__DIR__, 4);
    }
}
