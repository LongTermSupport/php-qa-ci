<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\OwnTwigDirectories;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(OwnTwigDirectories::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class OwnTwigDirectoriesTest extends TestCase
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
    public function aDirectoryUnderVendorIsDroppedAndOneOutsideItKept(): void
    {
        $root   = $this->factory->project->path;
        $config = $this->factory->builder()
            ->withTwigDirectories('templates', 'vendor/acme/pkg/templates', 'vendor-templates')
            ->build()
        ;

        $own = OwnTwigDirectories::declared($this->factory->context($config));

        self::assertSame([$root . '/templates', $root . '/vendor-templates'], $own);
    }

    #[Test]
    public function aSymlinkIntoVendorIsDropped(): void
    {
        $root = $this->factory->project->path;
        $this->factory->project->mkdir('vendor/acme/pkg/templates');
        \Safe\symlink($root . '/vendor/acme/pkg/templates', $root . '/templates');

        self::assertSame([], OwnTwigDirectories::declared($this->factory->context()));
    }
}
