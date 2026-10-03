<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\PhpCsFixer;

use LTS\PHPQA\Pipeline\Config\IgnoredPaths;
use LTS\PHPQA\Pipeline\Lane\PhpCsFixer\IgnoredPathsConfig;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * The generated config is executed here exactly as PHP CS Fixer executes it:
 * required, with the project's own config behind it. That config is a stand-in
 * with the two methods the wrapper calls, since PHP CS Fixer's classes exist
 * only inside its phar.
 *
 * @internal
 */
#[CoversClass(IgnoredPathsConfig::class)]
#[UsesClass(IgnoredPaths::class)]
#[Small]
final class IgnoredPathsConfigTest extends TestCase
{
    private const string INNER = 'qaConfig/php_cs.php';

    private const string WRAPPER = 'var/php_cs.php';

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-csconfig');
        foreach (['src/Kept.php', 'src/Legacy/Fixture.php', 'src/Legacy/deep/Fixture.php', 'src/LegacyExtra/Kept.php', 'tests/assets/Fixture.php'] as $file) {
            $this->project->write($file, "<?php\n");
        }

        $this->project->write(self::INNER, <<<'PHP'
            <?php

            declare(strict_types=1);

            // Would collide with the wrapper's own variables if it shared their scope.
            $config  = 'clobbered';
            $ignored = 'clobbered';

            return new class (__DIR__ . '/..') {
                private iterable $finder;

                public function __construct(string $root)
                {
                    $files = [];
                    foreach (['src/Kept.php', 'src/Legacy/Fixture.php', 'src/Legacy/deep/Fixture.php', 'src/LegacyExtra/Kept.php', 'tests/assets/Fixture.php'] as $file) {
                        $files[$root . '/' . $file] = new SplFileInfo($root . '/' . $file);
                    }

                    $this->finder = new ArrayIterator($files);
                }

                public function getFinder(): iterable
                {
                    return $this->finder;
                }

                public function setFinder(iterable $finder): self
                {
                    $this->finder = $finder;

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
    public function theFinderYieldsEverythingButTheIgnoredPathsAndKeepsItsKeys(): void
    {
        $finder = $this->finderFromWrapper(new IgnoredPaths($this->project->path, 'src/Legacy', 'tests/assets'));

        // The stand-in roots its finder at qaConfig/.., so a match on the
        // spelling of the path rather than on the real path would miss.
        $root = \Safe\realpath($this->project->path);
        self::assertSame(
            [$this->project->path . '/qaConfig/../src/Kept.php', $this->project->path . '/qaConfig/../src/LegacyExtra/Kept.php'],
            array_keys(iterator_to_array($finder)),
        );
        self::assertSame(
            [$root . '/src/Kept.php', $root . '/src/LegacyExtra/Kept.php'],
            array_values(array_map(static fn (SplFileInfo $file): string => (string)$file->getRealPath(), iterator_to_array($finder))),
            'iterated twice, the finder yields the same files twice',
        );
    }

    #[Test]
    public function anIgnoredPathThatDoesNotExistExcludesNothing(): void
    {
        $finder = $this->finderFromWrapper(new IgnoredPaths($this->project->path, 'src/Gone'));

        self::assertCount(5, iterator_to_array($finder));
    }

    #[Test]
    public function theProjectConfigIsRequiredByItsAbsolutePath(): void
    {
        $source = new IgnoredPathsConfig()->source($this->project->path . '/' . self::INNER, new IgnoredPaths($this->project->path, 'src/Legacy'));

        self::assertStringContainsString("require '" . $this->project->path . '/' . self::INNER . "'", $source);
        self::assertStringStartsWith("<?php\n\ndeclare(strict_types=1);\n", $source);
    }

    /** @return iterable<mixed, SplFileInfo> */
    private function finderFromWrapper(IgnoredPaths $ignored): iterable
    {
        $wrapper = $this->project->write(self::WRAPPER, new IgnoredPathsConfig()->source($this->project->path . '/' . self::INNER, $ignored));
        $config  = require $wrapper;
        self::assertIsObject($config);
        self::assertTrue(method_exists($config, 'getFinder'));

        $finder = $config->getFinder();
        self::assertIsIterable($finder);

        /** @var iterable<mixed, SplFileInfo> $finder */
        return $finder;
    }
}
