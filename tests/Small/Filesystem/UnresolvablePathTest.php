<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Filesystem;

use LTS\PHPQA\Filesystem\UnresolvablePath;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A file a lane walks to whose real path cannot be resolved (a dangling symlink) is
 * recognised, named by its directory's real path, and reported in one line every lane
 * shares (#239, #241).
 *
 * @internal
 */
#[CoversClass(UnresolvablePath::class)]
#[Small]
final class UnresolvablePathTest extends TestCase
{
    private const string DANGLING = '/dir/Gone.php';

    private const string REAL = '/dir/Real.php';

    private TempDir $directory;

    protected function setUp(): void
    {
        $this->directory = TempDir::create('phpqa-unresolvable-path');
        $this->directory->write('dir/Real.php', "<?php\n");
        \Safe\symlink('missing-target.php', $this->directory->path . self::DANGLING);
        \Safe\symlink('Real.php', $this->directory->path . '/dir/Linked.php');
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    #[Test]
    public function aDanglingSymlinkIsUnresolvable(): void
    {
        self::assertTrue(UnresolvablePath::is($this->directory->path . self::DANGLING));
    }

    #[Test]
    public function aFileOrAResolvingSymlinkIsNot(): void
    {
        self::assertFalse(UnresolvablePath::is($this->directory->path . self::REAL));
        self::assertFalse(UnresolvablePath::is($this->directory->path . '/dir/Linked.php'));
    }

    #[Test]
    public function aPathThatIsNotThereAtAllIsNot(): void
    {
        self::assertFalse(UnresolvablePath::is($this->directory->path . '/dir/Absent.php'));
    }

    #[Test]
    public function theNominalPathIsTheDirectorysRealPathAndTheFileName(): void
    {
        \Safe\symlink('dir', $this->directory->path . '/alias');

        self::assertSame(
            \Safe\realpath($this->directory->path) . self::DANGLING,
            UnresolvablePath::nominal($this->directory->path . '/alias/Gone.php'),
        );
    }

    #[Test]
    public function aPathWhoseDirectoryCannotBeResolvedIsItsOwnNominalPath(): void
    {
        $path = $this->directory->path . '/nowhere/Gone.php';

        self::assertSame($path, UnresolvablePath::nominal($path));
    }

    #[Test]
    public function theSkipLineNamesTheFileRelativeToTheProjectRoot(): void
    {
        self::assertSame(
            'Probe skipped dir/Gone.php: its real path cannot be resolved (a symlink whose target does not exist), so it was not checked',
            UnresolvablePath::skipped('Probe', $this->directory->path . self::DANGLING, $this->directory->path),
        );
        self::assertSame(
            'Probe skipped dir/Gone.php: its real path cannot be resolved (a symlink whose target does not exist), so it was not checked',
            UnresolvablePath::skipped('Probe', \Safe\realpath($this->directory->path) . self::DANGLING, $this->directory->path . '/'),
        );
    }

    #[Test]
    public function aFileOutsideTheProjectRootIsNamedInFull(): void
    {
        self::assertStringStartsWith(
            'Probe skipped /elsewhere/Gone.php: ',
            UnresolvablePath::skipped('Probe', '/elsewhere/Gone.php', $this->directory->path),
        );
    }

    #[Test]
    public function aListNamesEachEntryOnceInTheOrderFirstWritten(): void
    {
        $list = $this->directory->write('list.txt', "/b\n/a\n\n/b\n/a\n");

        self::assertSame(['/b', '/a'], UnresolvablePath::listedIn($list));
    }

    #[Test]
    public function aListThatWasNeverWrittenNamesNothing(): void
    {
        self::assertSame([], UnresolvablePath::listedIn($this->directory->path . '/absent.txt'));
    }

    #[Test]
    public function forgettingAListRemovesIt(): void
    {
        $list = $this->directory->write('list.txt', "/a\n");

        UnresolvablePath::forget($list);
        UnresolvablePath::forget($list);

        self::assertFileDoesNotExist($list);
    }
}
