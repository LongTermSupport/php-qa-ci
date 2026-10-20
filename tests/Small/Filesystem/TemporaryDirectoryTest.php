<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Filesystem;

use InvalidArgumentException;
use LTS\PHPQA\Filesystem\DirectoryTree;
use LTS\PHPQA\Filesystem\TemporaryDirectory;
use LTS\PHPQA\Tests\Support\VanishingDirectoryStreamWrapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The staging directory the installers download into. Removing it removes
 * everything in it: a cleanup that only reports what it did not remove leaves
 * every update's download behind in the system temp directory.
 *
 * @internal
 */
#[CoversClass(TemporaryDirectory::class)]
#[UsesClass(DirectoryTree::class)]
#[Small]
final class TemporaryDirectoryTest extends TestCase
{
    /** Begins the name of every staging directory this test makes. */
    private const string PREFIX = 'phpqa-temporary-directory-test';

    /** A directory path this long leaves no room under PATH_MAX (4096 on Linux) for a staging name in it. */
    private const int NO_ROOM_FOR_A_NAME = 4070;

    /** Begins the name of each directory a test makes to hold a target. */
    private const string PARENT = 'phpqa-temporary-directory-parent';

    /** The file a staging directory beside a target is made for. */
    private const string TARGET = 'library.so';

    /** What a file that must survive the cleanup holds. */
    private const string KEPT = 'kept';

    /** A directory inside a leftover, which a test makes the cleanup fail on. */
    private const string HELD = 'held';

    #[Test]
    public function aNewDirectoryIsEmptyAndUnderTheSystemTempDirectory(): void
    {
        $directory = TemporaryDirectory::create(self::PREFIX);

        try {
            self::assertDirectoryExists($directory->path);
            self::assertStringStartsWith(sys_get_temp_dir() . '/' . self::PREFIX, $directory->path);
            self::assertMatchesRegularExpression($this->stagingName(), basename($directory->path), 'the prefix, then 16 random hex digits');
            self::assertSame(['.', '..'], \Safe\scandir($directory->path));
        } finally {
            $directory->remove();
        }
    }

    /**
     * Staged beside the file it will replace, so the final rename() is on one filesystem and swaps
     * the file atomically instead of rewriting it in place.
     */
    #[Test]
    public function aDirectoryBesideATargetIsCreatedInTheTargetsOwnDirectory(): void
    {
        $parent    = TemporaryDirectory::create(self::PARENT);
        $directory = TemporaryDirectory::besides($parent->path . '/missing/yet/library.so', self::PREFIX);

        try {
            self::assertDirectoryExists($directory->path);
            self::assertSame($parent->path . '/missing/yet', \dirname($directory->path), "created in the target's directory, which is created if absent");
            self::assertMatchesRegularExpression($this->stagingName(), basename($directory->path), 'the prefix, then 16 random hex digits');
            self::assertSame(['.', '..'], \Safe\scandir($directory->path));
        } finally {
            $directory->remove();
            $parent->remove();
        }
    }

    /**
     * Defence for the class "staging meant to be beside the target silently lands in the system
     * temp directory" (issue #251). tempnam() falls back to the system temp directory, with only a
     * notice, whenever it cannot create its file in the directory it is given, which brings back
     * the cross-filesystem rename that rewrites a file in place.
     *
     * A directory whose path leaves no room for the staging name under PATH_MAX is the condition
     * used here: it makes tempnam() fall back even for root, which a directory without write
     * permission does not, and it needs no second filesystem.
     */
    #[Test]
    public function aDirectoryThatCannotBeCreatedBesideTheTargetFailsNamingItRatherThanStagingInTheSystemTempDirectory(): void
    {
        $prefix = self::PREFIX . '-not-beside';
        $parent = TemporaryDirectory::create(self::PARENT);
        $deep   = $parent->path;
        while (\strlen($deep) < self::NO_ROOM_FOR_A_NAME - 201) {
            $deep .= '/' . str_repeat('d', 200);
        }

        $deep .= '/' . str_repeat('e', self::NO_ROOM_FOR_A_NAME - \strlen($deep) - 1);
        \Safe\mkdir($deep, 0o700, true);

        try {
            TemporaryDirectory::besides($deep . '/' . self::TARGET, $prefix);
            self::fail('a staging directory was made somewhere other than beside the target');
        } catch (RuntimeException $runtimeException) {
            self::assertStringContainsString($deep, $runtimeException->getMessage(), 'the failure names the directory');
        } finally {
            $strays = array_filter(\Safe\glob(sys_get_temp_dir() . '/' . $prefix . '*'), \is_string(...));
            foreach ($strays as $stray) {
                if (is_dir($stray)) {
                    DirectoryTree::remove($stray);
                } else {
                    \Safe\unlink($stray);
                }
            }

            $parent->remove();
            self::assertSame([], $strays, 'nothing was staged in the system temp directory');
        }
    }

    /** The target's directory, when it cannot be made, fails the same way, naming it, with no warning printed besides. */
    #[Test]
    public function aTargetsDirectoryThatCannotBeMadeFailsNamingIt(): void
    {
        $parent = TemporaryDirectory::create(self::PARENT);
        \Safe\file_put_contents($parent->path . '/a-file', 'x');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageIsOrContains(\sprintf('Cannot create a staging directory in %s/a-file/library: mkdir(): Not a directory', $parent->path));

            TemporaryDirectory::besides($parent->path . '/a-file/library/' . self::TARGET, self::PREFIX);
        } finally {
            $parent->remove();
        }
    }

    /**
     * A run killed between making its staging directory and removing it leaves the directory
     * beside the file it was replacing, in a library directory a project may commit (issue #251).
     * The next run removes it; one changed recently may be another run's, still in use, and stays.
     */
    #[Test]
    public function aDirectoryBesideATargetRemovesTheStagingAKilledRunLeftThere(): void
    {
        $parent   = TemporaryDirectory::create(self::PARENT);
        $leftover = $parent->path . '/' . self::PREFIX . 'killed';
        $inUse    = $parent->path . '/' . self::PREFIX . 'running';
        \Safe\mkdir($leftover . '/nested', 0o700, true);
        \Safe\file_put_contents($leftover . '/nested/download.tar.gz', 'x');
        \Safe\touch($leftover, time() - 2 * 3600);
        \Safe\mkdir($inUse);
        \Safe\file_put_contents($parent->path . '/' . self::TARGET, self::KEPT);
        $file     = $parent->path . '/' . self::PREFIX . 'file';
        \Safe\file_put_contents($file, self::KEPT);
        \Safe\touch($file, time() - 2 * 3600);

        $directory = TemporaryDirectory::besides($parent->path . '/' . self::TARGET, self::PREFIX);

        try {
            self::assertDirectoryDoesNotExist($leftover, 'a staging directory a killed run left is removed');
            self::assertDirectoryExists($inUse, 'a staging directory changed recently may be in use, and is kept');
            self::assertFileExists($parent->path . '/' . self::TARGET);
            self::assertFileExists($file, 'only a directory is staging');
            self::assertDirectoryExists($directory->path);
        } finally {
            $directory->remove();
            $parent->remove();
        }
    }

    /**
     * A directory that is not staging is not a leftover, however old: only a name beginning with
     * the prefix is.
     */
    #[Test]
    public function aDirectoryBesideATargetKeepsAnOldDirectoryWithoutThePrefix(): void
    {
        $parent  = TemporaryDirectory::create(self::PARENT);
        $another = $parent->path . '/vendor-' . self::PREFIX;
        \Safe\mkdir($another);
        \Safe\touch($another, time() - 2 * 3600);

        $directory = TemporaryDirectory::besides($parent->path . '/' . self::TARGET, self::PREFIX);

        try {
            self::assertDirectoryExists($another, 'a directory whose name does not begin with the prefix is not staging');
        } finally {
            $directory->remove();
            $parent->remove();
        }
    }

    /**
     * A link whose name begins with the prefix was not made by mkdir() here, so it is not staging,
     * even when what it points at is an old directory.
     */
    #[Test]
    public function aDirectoryBesideATargetKeepsAnOldLinkWithThePrefix(): void
    {
        $outside = TemporaryDirectory::create('phpqa-temporary-directory-target');
        $parent  = TemporaryDirectory::create(self::PARENT);
        $link    = $parent->path . '/' . self::PREFIX . 'link';
        \Safe\symlink($outside->path, $link);
        \Safe\touch($outside->path, time() - 2 * 3600);

        $directory = TemporaryDirectory::besides($parent->path . '/' . self::TARGET, self::PREFIX);

        try {
            self::assertTrue(is_link($link), 'a link is not staging, and is kept');
        } finally {
            $directory->remove();
            $parent->remove();
            $outside->remove();
        }
    }

    /**
     * A leftover is a staging directory unchanged for longer than an hour; one changed exactly an
     * hour ago is not yet. The check is repeated until it runs within one second of the clock, the
     * second the age was set in.
     */
    #[Test]
    public function aDirectoryBesideATargetKeepsAStagingDirectoryChangedExactlyAnHourAgo(): void
    {
        $parent   = TemporaryDirectory::create(self::PARENT);
        $boundary = $parent->path . '/' . self::PREFIX . 'boundary';

        try {
            for ($attempt = 0; $attempt < 5; ++$attempt) {
                if (!is_dir($boundary)) {
                    \Safe\mkdir($boundary);
                }

                $now = time();
                \Safe\touch($boundary, $now - 3600);
                TemporaryDirectory::besides($parent->path . '/' . self::TARGET, self::PREFIX)->remove();
                if (time() === $now) {
                    self::assertDirectoryExists($boundary, 'a staging directory exactly an hour old is not yet a leftover');

                    return;
                }
            }

            self::fail('the clock moved on during every attempt');
        } finally {
            $parent->remove();
        }
    }

    /**
     * The staging directory is private to the run; a directory made to hold the target is one
     * other users may read, as the target itself is meant to be read.
     */
    #[Test]
    public function aDirectoryBesideATargetIsPrivateAndTheDirectoryMadeForTheTargetIsNot(): void
    {
        $parent   = TemporaryDirectory::create(self::PARENT);
        $previous = umask(0);

        try {
            $directory = TemporaryDirectory::besides($parent->path . '/missing/' . self::TARGET, self::PREFIX);
        } finally {
            umask($previous);
        }

        try {
            self::assertSame(0o755, \Safe\fileperms($parent->path . '/missing') & 0o777, "the target's directory");
            self::assertSame(0o700, \Safe\fileperms($directory->path) & 0o777, 'the staging directory');
        } finally {
            $directory->remove();
            $parent->remove();
        }
    }

    /** With no prefix, every old directory beside the target would count as a leftover. */
    #[Test]
    public function aDirectoryBesideATargetNeedsAPrefix(): void
    {
        $target = sys_get_temp_dir() . '/' . self::TARGET;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A staging directory beside ' . $target . ' needs a prefix');

        TemporaryDirectory::besides($target, '');
    }

    /**
     * Defence for the class "a prefix that is not part of a file name reaches outside the staging
     * names": `.` and `..` begin the names of the directory itself and its parent, so an old one
     * was emptied as a leftover, the target with it; one with a slash names another directory.
     * The prefix is refused before anything beside the target is touched.
     */
    #[Test]
    #[DataProvider('prefixesThatAreNotANamePart')]
    public function aDirectoryBesideATargetRefusesAPrefixThatIsNotPartOfAFileName(string $prefix): void
    {
        $grandparent = TemporaryDirectory::create(self::PARENT);
        $parent      = $grandparent->path . '/library';
        \Safe\mkdir($parent);
        \Safe\file_put_contents($parent . '/' . self::TARGET, self::KEPT);
        \Safe\touch($parent, time() - 2 * 3600);
        \Safe\touch($grandparent->path, time() - 2 * 3600);

        try {
            TemporaryDirectory::besides($parent . '/' . self::TARGET, $prefix);
            self::fail('a prefix that is not part of a file name was accepted');
        } catch (InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString('needs a prefix', $invalidArgumentException->getMessage());
        } finally {
            $left = \Safe\scandir($parent);
            $grandparent->remove();
        }

        self::assertSame(['.', '..', self::TARGET], $left, 'nothing beside the target was touched');
    }

    /** @return iterable<string, array{string}> */
    public static function prefixesThatAreNotANamePart(): iterable
    {
        yield 'the directory itself' => ['.'];
        yield 'its parent' => ['..'];
        yield 'a path' => ['staging/'];
        yield 'an absolute path' => ['/staging'];
        yield 'a NUL byte' => ["staging\0"];
    }

    /**
     * Another run that reaches the same leftover first removes it; this one passes it over without
     * a word, and stages as usual. The leftover is removed from under the cleanup the moment it
     * first changes anything in it, which is where concurrent runs collided.
     */
    #[Test]
    public function aDirectoryBesideATargetPassesOverALeftoverAnotherRunRemovedFirst(): void
    {
        $parent   = TemporaryDirectory::create(self::PARENT);
        $leftover = $parent->path . '/' . self::PREFIX . 'raced';
        \Safe\mkdir($leftover . '/nested', 0o700, true);
        \Safe\file_put_contents($leftover . '/nested/download.tar.gz', 'x');
        \Safe\touch($leftover, time() - 2 * 3600);
        \Safe\stream_wrapper_register(VanishingDirectoryStreamWrapper::SCHEME, VanishingDirectoryStreamWrapper::class);
        VanishingDirectoryStreamWrapper::vanishOnChange($leftover);

        try {
            $directory = TemporaryDirectory::besides($this->overTheWrapper($parent->path . '/' . self::TARGET), self::PREFIX);
            $directory->remove();

            self::assertTrue(VanishingDirectoryStreamWrapper::hasVanishedOnChange(), 'the cleanup reached the leftover');
            self::assertSame([], $directory->unremovedLeftovers, 'a leftover already gone is not one kept');
            self::assertDirectoryDoesNotExist($leftover);
        } finally {
            \Safe\stream_wrapper_unregister(VanishingDirectoryStreamWrapper::SCHEME);
            VanishingDirectoryStreamWrapper::reset();
            $parent->remove();
        }
    }

    /**
     * A leftover this run cannot remove is kept, and named with the reason, for a caller that can
     * warn; it does not stop the staging. A directory in it is refused, by name, wherever the
     * cleanup has moved it: one it cannot read, or one it cannot remove. (A real tree nested past
     * PATH_MAX would do as well, but one a mutant moved out of reach of this test's cleanup would
     * stop every later run on the host, which reaps the temp directory the same way.).
     *
     * @param bool $unreadable whether the directory cannot be read, rather than removed
     */
    #[Test]
    #[DataProvider('refusals')]
    public function aDirectoryBesideATargetKeepsALeftoverItCannotRemoveAndNamesIt(bool $unreadable, string $why): void
    {
        $parent   = TemporaryDirectory::create(self::PARENT);
        $leftover = $parent->path . '/' . self::PREFIX . 'stuck';
        \Safe\mkdir($leftover . '/' . self::HELD, 0o700, true);
        \Safe\touch($leftover, time() - 2 * 3600);
        \Safe\stream_wrapper_register(VanishingDirectoryStreamWrapper::SCHEME, VanishingDirectoryStreamWrapper::class);
        if ($unreadable) {
            VanishingDirectoryStreamWrapper::refuseOpenNamed(self::HELD);
        } else {
            VanishingDirectoryStreamWrapper::refuseRemovalNamed(self::HELD);
        }

        try {
            $directory = TemporaryDirectory::besides($this->overTheWrapper($parent->path . '/' . self::TARGET), self::PREFIX);
            $directory->remove();

            self::assertCount(1, $directory->unremovedLeftovers);
            $kept = array_key_first($directory->unremovedLeftovers);
            self::assertStringStartsWith($this->overTheWrapper($parent->path . '/' . self::PREFIX), $kept);
            self::assertDirectoryExists($kept . '/' . self::HELD, 'what could not be removed is still there');
            self::assertStringContainsString($kept . '/' . self::HELD, $directory->unremovedLeftovers[$kept]);
            self::assertStringContainsString($why, $directory->unremovedLeftovers[$kept]);
        } finally {
            \Safe\stream_wrapper_unregister(VanishingDirectoryStreamWrapper::SCHEME);
            VanishingDirectoryStreamWrapper::reset();
            $parent->remove();
        }
    }

    /** @return iterable<string, array{bool, string}> */
    public static function refusals(): iterable
    {
        yield 'a directory in it that cannot be read' => [true, 'Failed to open directory'];
        yield 'a directory in it that cannot be removed' => [false, 'Operation not permitted'];
    }

    #[Test]
    public function removingItRemovesEverythingInIt(): void
    {
        $directory = TemporaryDirectory::create(self::PREFIX);
        \Safe\mkdir($directory->path . '/nested/deeper', 0o700, true);
        \Safe\file_put_contents($directory->path . '/top.txt', 'x');
        \Safe\file_put_contents($directory->path . '/nested/deeper/file.txt', 'x');

        $directory->remove();

        self::assertDirectoryDoesNotExist($directory->path);
    }

    #[Test]
    public function aSymlinkInsideIsRemovedWithoutFollowingIt(): void
    {
        $outside   = TemporaryDirectory::create('phpqa-temporary-directory-target');
        $directory = TemporaryDirectory::create(self::PREFIX);
        \Safe\file_put_contents($outside->path . '/keep.txt', 'x');
        \Safe\symlink($outside->path, $directory->path . '/link');

        try {
            $directory->remove();

            self::assertDirectoryDoesNotExist($directory->path);
            self::assertFileExists($outside->path . '/keep.txt');
        } finally {
            $outside->remove();
        }
    }

    #[Test]
    public function removingADirectoryAlreadyGoneIsNotAnError(): void
    {
        $directory = TemporaryDirectory::create(self::PREFIX);
        $directory->remove();

        $directory->remove();

        self::assertDirectoryDoesNotExist($directory->path);
    }

    /**
     * The path as the test stream wrapper serves it.
     *
     * @return non-empty-string
     */
    private function overTheWrapper(string $path): string
    {
        return VanishingDirectoryStreamWrapper::SCHEME . '://' . $path;
    }

    /** Matches the name of a staging directory: the prefix, then 16 random hex digits. */
    private function stagingName(): string
    {
        return '/^' . preg_quote(self::PREFIX, '/') . '[0-9a-f]{16}$/';
    }
}
