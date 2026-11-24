<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Config;

use LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The one project setting that says the project builds without network access: off by default,
 * set only in qaConfig/qa.php, so it is a fact about the project and no environment variable
 * turns it on for one host.
 *
 * @internal
 */
#[CoversClass(QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\CoverageDriverEnum::class)]
#[UsesClass(QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class QaConfigBuilderOfflineBuildTest extends TestCase
{
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
    public function aProjectBuildsWithNetworkAccessByDefault(): void
    {
        self::assertFalse($this->factory->builder()->build()->offlineBuild);
    }

    #[Test]
    public function theBuilderDeclaresAnOfflineBuildAndKeepsTheOtherSettings(): void
    {
        $offline = $this->factory->builder()->withComposerAudit(false)->withOfflineBuild(true);

        self::assertTrue($offline->build()->offlineBuild);
        self::assertFalse($offline->build()->useComposerAudit);
        self::assertFalse($offline->withOfflineBuild(false)->build()->offlineBuild);
    }

    #[Test]
    public function noEnvironmentVariableDeclaresIt(): void
    {
        self::assertFalse($this->factory->builder(['offlineBuild' => '1', 'withOfflineBuild' => '1'])->build()->offlineBuild);
    }
}
