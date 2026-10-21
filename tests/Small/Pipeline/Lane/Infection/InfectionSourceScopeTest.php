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

    private const string PLUGIN_EXCLUDED = 'excluded by source.excludes "/ComposerPlugin/"';

    private const string NO_SOURCE_DIRECTORY = 'under no source directory';

    private TempDir $project;

    private string $src;

    private string $plugin;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-source-scope');
        foreach (['src/Domain/Kept.php', 'src/ComposerPlugin/Plugin.php', 'src/.hidden/Dot.php', 'src/view.phtml', 'lib/Extra.php'] as $file) {
            $this->project->write($file, '<?php');
        }

        $this->src    = $this->project->path . '/src';
        $this->plugin = $this->src . '/ComposerPlugin/Plugin.php';
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function aFileInfectionCollectsHasNoReason(): void
    {
        self::assertNull(new InfectionSourceScope()->outside($this->src . '/Domain/Kept.php', [$this->src], self::PLUGIN_EXCLUDE));
    }

    #[Test]
    public function anExcludedFileNamesTheExcludeThatCoversIt(): void
    {
        self::assertSame(self::PLUGIN_EXCLUDED, new InfectionSourceScope()->outside($this->plugin, [$this->src], 'Legacy', self::PLUGIN_EXCLUDE));
    }

    #[Test]
    public function aFileUnderNoSourceDirectorySaysSo(): void
    {
        self::assertSame(self::NO_SOURCE_DIRECTORY, new InfectionSourceScope()->outside($this->project->path . '/lib/Extra.php', [$this->src]));
    }

    #[Test]
    public function aFileUnderADirectoryFinderSkipsSaysSo(): void
    {
        self::assertSame("in a directory Infection's Finder skips", new InfectionSourceScope()->outside($this->src . '/.hidden/Dot.php', [$this->src]));
    }

    #[Test]
    public function aFileNotNamedDotPhpSaysSo(): void
    {
        self::assertSame('not a .php file', new InfectionSourceScope()->outside($this->src . '/view.phtml', [$this->src]));
    }

    /** Finder lists a file once per source directory it is under, so one that keeps it is enough. */
    #[Test]
    public function aFileExcludedUnderOneSourceDirectoryButKeptUnderAnotherIsCollected(): void
    {
        self::assertNull(new InfectionSourceScope()->outside($this->plugin, [$this->src, $this->src . '/ComposerPlugin'], self::PLUGIN_EXCLUDE));
    }

    /** Left out under every source directory it is under, a file is reported by the first one's reason. */
    #[Test]
    public function aFileLeftOutUnderEverySourceDirectoryGivesTheFirstOnesReason(): void
    {
        self::assertSame(self::PLUGIN_EXCLUDED, new InfectionSourceScope()->outside($this->plugin, [$this->src, $this->src . '/ComposerPlugin'], self::PLUGIN_EXCLUDE, 'Plugin.php'));
    }

    /** A path that is not on disk is compared as written, against directories that are not either. */
    #[Test]
    public function aPathNotOnDiskIsComparedAsWritten(): void
    {
        self::assertNull(new InfectionSourceScope()->outside('/nowhere/src/Foo.php', ['/nowhere/src']));
        self::assertSame(self::NO_SOURCE_DIRECTORY, new InfectionSourceScope()->outside('/nowhere/src2/Foo.php', ['/nowhere/src']));
    }

    #[Test]
    public function anExcludeThatDoesNotCompileThrowsNamingIt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('source.excludes entry "/(Legacy/" is not a valid regular expression');

        new InfectionSourceScope()->outside($this->src . '/Domain/Kept.php', [$this->src], '/(Legacy/');
    }
}
