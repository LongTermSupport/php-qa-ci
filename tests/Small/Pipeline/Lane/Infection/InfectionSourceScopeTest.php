<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Infection;

use InvalidArgumentException;
use LTS\PHPQA\Pipeline\Lane\Infection\InfectionSourceScope;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Why Infection does not collect a file, read from infection.json's `source`
 * as Infection's Finder reads it; null when it collects it. How each kind of
 * exclude matches is pinned in PreloadedSourceFinderTest, which runs through
 * the same reading.
 *
 * @internal
 */
#[CoversClass(InfectionSourceScope::class)]
#[Small]
final class InfectionSourceScopeTest extends TestCase
{
    private const string PLUGIN_EXCLUDE = '/ComposerPlugin/';

    private TempDir $project;

    private string $src;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-source-scope');
        foreach (['src/Domain/Kept.php', 'src/ComposerPlugin/Plugin.php', 'src/.hidden/Dot.php', 'src/view.phtml', 'lib/Extra.php'] as $file) {
            $this->project->write($file, '<?php');
        }

        $this->src = $this->project->path . '/src';
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function aFileInfectionCollectsHasNoReason(): void
    {
        self::assertNull((new InfectionSourceScope())->outside([$this->src], [self::PLUGIN_EXCLUDE], $this->src . '/Domain/Kept.php'));
    }

    #[Test]
    public function anExcludedFileNamesTheExcludeThatCoversIt(): void
    {
        self::assertSame(
            'excluded by source.excludes "/ComposerPlugin/"',
            (new InfectionSourceScope())->outside([$this->src], ['Legacy', self::PLUGIN_EXCLUDE], $this->src . '/ComposerPlugin/Plugin.php'),
        );
    }

    #[Test]
    public function aFileUnderNoSourceDirectorySaysSo(): void
    {
        self::assertSame('under no source directory', (new InfectionSourceScope())->outside([$this->src], [], $this->project->path . '/lib/Extra.php'));
    }

    #[Test]
    public function aFileUnderADirectoryFinderSkipsSaysSo(): void
    {
        self::assertSame("in a directory Infection's Finder skips", (new InfectionSourceScope())->outside([$this->src], [], $this->src . '/.hidden/Dot.php'));
    }

    #[Test]
    public function aFileNotNamedDotPhpSaysSo(): void
    {
        self::assertSame('not a .php file', (new InfectionSourceScope())->outside([$this->src], [], $this->src . '/view.phtml'));
    }

    /** Finder lists a file once per source directory it is under, so one that keeps it is enough. */
    #[Test]
    public function aFileExcludedUnderOneSourceDirectoryButKeptUnderAnotherIsCollected(): void
    {
        self::assertNull((new InfectionSourceScope())->outside([$this->src, $this->src . '/ComposerPlugin'], [self::PLUGIN_EXCLUDE], $this->src . '/ComposerPlugin/Plugin.php'));
    }

    /** A path that is not on disk is compared as written, against directories that are not either. */
    #[Test]
    public function aPathNotOnDiskIsComparedAsWritten(): void
    {
        self::assertNull((new InfectionSourceScope())->outside(['/nowhere/src'], [], '/nowhere/src/Foo.php'));
        self::assertSame('under no source directory', (new InfectionSourceScope())->outside(['/nowhere/src'], [], '/nowhere/src2/Foo.php'));
    }

    #[Test]
    public function anExcludeThatDoesNotCompileThrowsNamingIt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('source.excludes entry "/(Legacy/" is not a valid regular expression');

        (new InfectionSourceScope())->outside([$this->src], ['/(Legacy/'], $this->src . '/Domain/Kept.php');
    }
}
