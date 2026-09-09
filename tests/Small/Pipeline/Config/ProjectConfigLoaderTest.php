<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Config;

use LTS\PHPQA\Pipeline\Config\Exception\LegacyBashConfigException;
use LTS\PHPQA\Pipeline\Config\ProjectConfigLoader;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(ProjectConfigLoader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(LegacyBashConfigException::class)]
#[Small]
final class ProjectConfigLoaderTest extends TestCase
{
    private const string QA_CONFIG = '/qaConfig';

    private const string QA_CONFIG_QA_PHP = 'qaConfig/qa.php';

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
    public function withoutAQaPhpTheBuilderIsReturnedUntouched(): void
    {
        $builder = $this->factory->builder();

        $result = new ProjectConfigLoader($this->factory->output)->apply($builder, $this->factory->project->path . self::QA_CONFIG);

        self::assertSame($builder, $result);
        self::assertSame('', $this->factory->output->fetch());
    }

    #[Test]
    public function aQaPhpClosureAdjustsTheBuilder(): void
    {
        $this->factory->project->write(self::QA_CONFIG_QA_PHP, <<<'PHP_WRAP'
            <?php
            use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
            return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
                ->withInfectionFloors(msi: 82, coveredMsi: 82)
                ->withIgnoredPaths('tests/assets');
            PHP_WRAP);

        $config = new ProjectConfigLoader($this->factory->output)->apply($this->factory->builder(), $this->factory->project->path . self::QA_CONFIG)->build();

        self::assertSame(82, $config->infection->minMsi);
        self::assertSame(['tests/assets'], $config->pathsToIgnore);
        self::assertStringContainsString('Project config applied', $this->factory->output->fetch());
    }

    #[Test]
    public function aLeftoverBashConfigIsRefusedWithGuidance(): void
    {
        $this->factory->project->write('qaConfig/qaConfig.inc.bash', 'export useInfection=0');

        try {
            new ProjectConfigLoader($this->factory->output)->apply($this->factory->builder(), $this->factory->project->path . self::QA_CONFIG);
            self::fail('expected LegacyBashConfigException');
        } catch (LegacyBashConfigException $legacyBashConfigException) {
            self::assertStringContainsString('qaConfig.inc.bash is no longer read', $legacyBashConfigException->getMessage());
            self::assertStringContainsString('qa.php', $legacyBashConfigException->getMessage());
        }
    }

    #[Test]
    public function aQaPhpThatReturnsSomethingElseIsRejected(): void
    {
        $this->factory->project->write(self::QA_CONFIG_QA_PHP, '<?php return ["useInfection" => false];');

        $this->expectException(RuntimeException::class);
        new ProjectConfigLoader($this->factory->output)->apply($this->factory->builder(), $this->factory->project->path . self::QA_CONFIG);
    }

    #[Test]
    public function aClosureThatDoesNotReturnTheBuilderIsRejected(): void
    {
        $this->factory->project->write(self::QA_CONFIG_QA_PHP, '<?php return static function ($qa): void { $qa->withInfection(false); };');

        $this->expectException(RuntimeException::class);
        new ProjectConfigLoader($this->factory->output)->apply($this->factory->builder(), $this->factory->project->path . self::QA_CONFIG);
    }
}
