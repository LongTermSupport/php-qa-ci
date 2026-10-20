<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\FormatterCache;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(FormatterCache::class)]
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
final class FormatterCacheTest extends TestCase
{
    private const string NAME = 'formatter.cache';

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
    public function theCacheLivesInTheCacheDirectoryUnderVarQa(): void
    {
        $context = $this->factory->context();

        self::assertSame($context->config->paths->cacheDir . '/' . self::NAME, FormatterCache::in($context, self::NAME)->path);
    }

    #[Test]
    public function forgettingTheCacheDeletesItsFile(): void
    {
        $cache = FormatterCache::in($this->factory->context(), self::NAME);
        $this->factory->project->write('var/qa/cache/' . self::NAME, '{}');

        $cache->forget();

        self::assertFileDoesNotExist($cache->path);
    }

    #[Test]
    public function forgettingACacheThatWasNeverWrittenDoesNothing(): void
    {
        $cache = FormatterCache::in($this->factory->context(), self::NAME);

        $cache->forget();

        self::assertFileDoesNotExist($cache->path);
    }

    #[Test]
    public function preparingTheCacheCreatesItsDirectory(): void
    {
        $cache = FormatterCache::in($this->factory->context(), self::NAME);

        self::assertSame($cache->path, $cache->prepared());
        self::assertDirectoryExists(\dirname($cache->path));
    }
}
