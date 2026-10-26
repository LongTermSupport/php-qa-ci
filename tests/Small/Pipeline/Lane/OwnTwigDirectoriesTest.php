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
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class OwnTwigDirectoriesTest extends TestCase
{
    private const string TEMPLATES_SUFFIX = '/templates';

    private const string VENDORED = 'vendor/acme/pkg/templates';

    private const string SIBLING = 'vendor-templates';

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
            ->withTwigDirectories('templates', self::VENDORED, self::SIBLING)
            ->build()
        ;

        $own = OwnTwigDirectories::declared($this->factory->context($config));

        self::assertSame([$root . self::TEMPLATES_SUFFIX, $root . '/vendor-templates'], $own);
    }

    #[Test]
    public function aSymlinkIntoVendorIsDropped(): void
    {
        $root = $this->factory->project->path;
        $this->factory->project->mkdir(self::VENDORED);
        \Safe\symlink($root . '/vendor/acme/pkg/templates', $root . self::TEMPLATES_SUFFIX);

        self::assertSame([], OwnTwigDirectories::declared($this->factory->context()));
    }

    #[Test]
    public function aSymlinkToADirectoryOutsideVendorIsKept(): void
    {
        $root = $this->factory->project->path;
        $this->factory->project->mkdir('shared/views');
        $this->factory->project->mkdir('vendor/acme');
        \Safe\symlink($root . '/shared/views', $root . self::TEMPLATES_SUFFIX);

        self::assertSame([$root . self::TEMPLATES_SUFFIX], OwnTwigDirectories::declared($this->factory->context()));
    }

    #[Test]
    public function aDirectoryThatExistsUnderVendorIsDropped(): void
    {
        $root = $this->factory->project->path;
        $this->factory->project->mkdir(self::VENDORED);
        $config = $this->factory->builder()->withTwigDirectories(self::VENDORED, 'vendor')->build();

        self::assertSame([], OwnTwigDirectories::declared($this->factory->context($config)), $root);
    }

    #[Test]
    public function aSiblingWhoseNameOnlyStartsWithVendorIsKept(): void
    {
        $root = $this->factory->project->path;
        $this->factory->project->mkdir('vendor/acme');
        $this->factory->project->mkdir(self::SIBLING);

        $config = $this->factory->builder()->withTwigDirectories(self::SIBLING, 'vendorish')->build();

        self::assertSame(
            [$root . '/vendor-templates', $root . '/vendorish'],
            OwnTwigDirectories::declared($this->factory->context($config)),
        );
    }

    #[Test]
    public function aMissingDirectoryIsKeptWhenNotUnderVendorAndVendorItselfIsAbsent(): void
    {
        $root = $this->factory->project->path;

        self::assertSame([$root . self::TEMPLATES_SUFFIX], OwnTwigDirectories::declared($this->factory->context()));
    }

    #[Test]
    public function aMissingDirectoryUnderAbsentVendorIsStillDropped(): void
    {
        $config = $this->factory->builder()->withTwigDirectories('vendor/acme/pkg/templates/')->build();

        self::assertSame([], OwnTwigDirectories::declared($this->factory->context($config)));
    }

    #[Test]
    public function noConfiguredDirectoryGivesNone(): void
    {
        $config = $this->factory->builder()->withTwigDirectories()->build();

        self::assertSame([], OwnTwigDirectories::declared($this->factory->context($config)));
    }

    #[Test]
    public function theSkipIsAnnouncedWithTheReason(): void
    {
        OwnTwigDirectories::announceSkip($this->factory->context());

        self::assertSame(
            'Twig: the project declares no template directory of its own (withTwigDirectories(); templates under vendor/ are never linted) — skipping.',
            trim($this->factory->output->fetch()),
        );
    }
}
