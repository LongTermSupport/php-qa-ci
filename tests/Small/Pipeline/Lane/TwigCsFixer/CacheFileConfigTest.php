<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\TwigCsFixer;

use LTS\PHPQA\Pipeline\Lane\TwigCsFixer\CacheFileConfig;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The generated config is executed here exactly as twig-cs-fixer executes it: required, with
 * the project's own config behind it. That config is a stand-in with the two methods the
 * wrapper calls, since twig-cs-fixer's classes exist only inside its phar.
 *
 * @internal
 */
#[CoversClass(CacheFileConfig::class)]
#[Small]
final class CacheFileConfigTest extends TestCase
{
    private const string INNER = 'qaConfig/.twig-cs-fixer.php';

    private const string WRAPPER = 'var/qa/twigCsFixer/.twig-cs-fixer.php';

    private const string CACHE = '/project/var/qa/cache/twig-cs-fixer.cache';

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-twigconfig');
        $this->project->write(self::INNER, <<<'PHP'
            <?php

            declare(strict_types=1);

            // Would collide with the wrapper's own variable if it shared its scope.
            $config = 'clobbered';

            return new class {
                public ?string $cacheFile = '.twig-cs-fixer.cache';

                public ?object $cacheManager;

                public string $configDir = __DIR__;

                public function __construct()
                {
                    $this->cacheManager = new stdClass();
                }

                public function setCacheFile(?string $cacheFile): self
                {
                    $this->cacheFile = $cacheFile;

                    return $this;
                }

                public function setCacheManager(?object $cacheManager): self
                {
                    $this->cacheManager = $cacheManager;

                    return $this;
                }
            };
            PHP);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function theProjectConfigIsReturnedWithItsCacheFileReplaced(): void
    {
        $config = $this->configFromWrapper();

        self::assertSame(self::CACHE, $config->cacheFile);
    }

    /** A cache manager wins over the cache file in twig-cs-fixer, so one the project set would put the cache back wherever it writes. */
    #[Test]
    public function aCacheManagerTheProjectSetIsDropped(): void
    {
        $config = $this->configFromWrapper();

        self::assertNull($config->cacheManager);
    }

    #[Test]
    public function theProjectConfigStillResolvesItsOwnRelativePaths(): void
    {
        $config = $this->configFromWrapper();

        self::assertSame($this->project->path . '/qaConfig', $config->configDir);
    }

    /** @return object{cacheFile: ?string, cacheManager: ?object, configDir: string} */
    private function configFromWrapper(): object
    {
        $wrapper = $this->project->write(self::WRAPPER, new CacheFileConfig()->source($this->project->path . '/' . self::INNER, self::CACHE));

        $config = (static fn (): mixed => require $wrapper)();
        self::assertIsObject($config);
        self::assertObjectHasProperty('cacheFile', $config);
        self::assertObjectHasProperty('cacheManager', $config);
        self::assertObjectHasProperty('configDir', $config);

        /** @var object{cacheFile: ?string, cacheManager: ?object, configDir: string} $config */
        return $config;
    }
}
