<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Filesystem;

use Closure;
use InvalidArgumentException;
use LogicException;
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

    /** A target that is a directory, as the detector the deadCode lane unpacks is. */
    private const string TARGET_DIRECTORY = 'target';

    /** A directory inside a leftover, which a test makes the cleanup fail on. */
    private const string HELD = 'held';

    /** What a refusal leaves beside the target: everything as it was. */
    private const string NOTHING_BESIDE_TOUCHED = 'nothing beside the target was touched';

    /** A directory, below the one a test makes, that holds the target. */
    private const string LIBRARY = '/library';

    /** The file a planted leftover holds, below the leftover. */
    private const string DOWNLOAD = '/nested/download.tar.gz';

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

    /**
     * The staging directory is made in the directory it is named for, never with directories
     * under it that a prefix names and that are missing.
     */
    #[Test]
    public function aNewDirectoryIsNotMadeUnderADirectoryThatIsMissing(): void
    {
        $missing = sys_get_temp_dir() . '/' . self::PREFIX . '-missing';

        try {
            TemporaryDirectory::create(self::PREFIX . '-missing/staging');
            self::fail('a staging directory was made under a directory that was missing');
        } catch (RuntimeException $runtimeException) {
            self::assertSame(\sprintf('Cannot create a staging directory in %s: mkdir(): No such file or directory', sys_get_temp_dir()), $runtimeException->getMessage());
        } finally {
            $made = is_dir($missing);
            DirectoryTree::remove($missing);
        }

        self::assertFalse($made, 'the missing directory was not made');
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
        $leftover = $this->stagingIn($parent->path, 1);
        $inUse    = $this->stagingIn($parent->path, 2);
        $this->plantLeftover($leftover);
        \Safe\mkdir($inUse);
        \Safe\file_put_contents($parent->path . '/' . self::TARGET, self::KEPT);
        $file     = $this->stagingIn($parent->path, 3);
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
     * Defence for the class "leftovers already on disk are never removed" (issue #251): every
     * earlier version named its staging directory with tempnam(), the prefix then 6 letters and
     * digits, so a killed run of one of them left that shape beside the target. It is a leftover
     * as well, and is removed.
     */
    #[Test]
    public function aDirectoryBesideATargetRemovesTheStagingAnEarlierVersionLeftThere(): void
    {
        $parent   = TemporaryDirectory::create(self::PARENT);
        $leftover = $parent->path . '/' . self::PREFIX . 'Ab12Cd';
        $this->plantLeftover($leftover);

        $directory = TemporaryDirectory::besides($parent->path . '/' . self::TARGET, self::PREFIX);

        try {
            self::assertDirectoryDoesNotExist($leftover, "a staging directory named by an earlier version's tempnam() is removed");
            self::assertSame([], $directory->unremovedLeftovers);
        } finally {
            $directory->remove();
            $parent->remove();
        }
    }

    /**
     * A directory that is not staging is not a leftover, however old: only a name beginning with
     * the prefix can be.
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
        $link    = $this->stagingIn($parent->path, 4);
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
        $boundary = $this->stagingIn($parent->path, 5);

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
        $parent      = $grandparent->path . self::LIBRARY;
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

        self::assertSame(['.', '..', self::TARGET], $left, self::NOTHING_BESIDE_TOUCHED);
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
     * Defence for the class "the cleanup deletes the target it stages beside": a prefix the
     * target's own name begins with made an old target directory a leftover, and it was renamed
     * and removed with everything in it. The prefix is refused before anything is touched.
     */
    #[Test]
    #[DataProvider('prefixesTheTargetsNameBeginsWith')]
    public function aDirectoryBesideATargetRefusesAPrefixTheTargetsOwnNameBeginsWith(string $name, string $prefix): void
    {
        $parent = TemporaryDirectory::create(self::PARENT);
        $target = $parent->path . '/' . $name;
        \Safe\mkdir($target);
        \Safe\file_put_contents($target . '/' . self::TARGET, self::KEPT);
        \Safe\touch($target, time() - 2 * 3600);

        try {
            TemporaryDirectory::besides($target, $prefix);
            self::fail("a prefix the target's own name begins with was accepted");
        } catch (InvalidArgumentException $invalidArgumentException) {
            self::assertSame(\sprintf('A staging directory beside %s needs a prefix its name does not begin with, not "%s": the target would be one of its leftovers', $target, $prefix), $invalidArgumentException->getMessage());
            self::assertSame(['.', '..', $name], \Safe\scandir($parent->path), self::NOTHING_BESIDE_TOUCHED);
            self::assertSame(self::KEPT, \Safe\file_get_contents($target . '/' . self::TARGET), 'the target is kept whole');
        } finally {
            $parent->remove();
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function prefixesTheTargetsNameBeginsWith(): iterable
    {
        yield 'a prefix of its name' => [self::TARGET_DIRECTORY, 'targ'];
        yield 'its whole name' => [self::TARGET_DIRECTORY, self::TARGET_DIRECTORY];
        yield "a real caller's prefix" => ['.phpqa-shellcheck-x', '.phpqa-shellcheck'];
        yield 'a name shaped as staging' => ['.unpack0123456789abcdef', '.unpack'];
        yield "a name shaped as an earlier version's staging" => ['.unpackAb12Cd', '.unpack'];
    }

    /**
     * Defence for the class "the target guard reads a name the target does not have": a target
     * spelled `lib/.` or `lib/sub/..` is `lib` itself, so the cleanup ran inside the target and
     * removed what was named as staging there, and staged inside it; one ending in a slash has no
     * name of its own to check. Each is refused before anything is touched.
     */
    #[Test]
    #[DataProvider('targetsNotEndingInTheirOwnName')]
    public function aDirectoryBesideATargetRefusesATargetNotEndingInItsOwnName(string $suffix): void
    {
        $parent   = TemporaryDirectory::create(self::PARENT);
        $library  = $parent->path . self::LIBRARY;
        $leftover = $this->stagingIn($library, 12);
        \Safe\mkdir($library . '/sub', 0o700, true);
        $this->plantLeftover($leftover);
        $target   = $library . $suffix;

        try {
            TemporaryDirectory::besides($target, self::PREFIX);
            self::fail('a target not ending in its own name was accepted');
        } catch (InvalidArgumentException $invalidArgumentException) {
            self::assertSame(\sprintf('A staging directory beside %s needs a target path that ends in its own name, not in "/", "." or "..": its leftovers would be looked for in the wrong directory', $target), $invalidArgumentException->getMessage());
        } finally {
            $left   = \Safe\scandir($library);
            $around = \Safe\scandir($parent->path);
            $parent->remove();
        }

        self::assertSame(['.', '..', basename($leftover), 'sub'], $left, 'nothing in the target was touched');
        self::assertSame(['.', '..', 'library'], $around, self::NOTHING_BESIDE_TOUCHED);
    }

    /** @return iterable<string, array{string}> */
    public static function targetsNotEndingInTheirOwnName(): iterable
    {
        yield 'the directory itself' => ['/.'];
        yield 'a parent' => ['/sub/..'];
        yield 'a trailing slash' => ['/'];
        yield 'a trailing slash after a dot' => ['/./'];
    }

    /** An empty target names no file at all; it is refused rather than staged in the working directory. */
    #[Test]
    public function aDirectoryBesideATargetRefusesAnEmptyTarget(): void
    {
        $parent   = TemporaryDirectory::create(self::PARENT);
        $previous = \Safe\getcwd();
        \Safe\chdir($parent->path);

        try {
            TemporaryDirectory::besides('', self::PREFIX);
            self::fail('an empty target was accepted');
        } catch (InvalidArgumentException $invalidArgumentException) {
            self::assertSame('A staging directory beside  needs a target path that ends in its own name, not in "/", "." or "..": its leftovers would be looked for in the wrong directory', $invalidArgumentException->getMessage());
        } finally {
            \Safe\chdir($previous);
            $left = \Safe\scandir($parent->path);
            $parent->remove();
        }

        self::assertSame(['.', '..'], $left, 'nothing was staged in the working directory');
    }

    /**
     * Defence for the class "the target guard reads the link, not what it points at": a target that
     * is a link to an old directory named as staging beside it made that directory a leftover, and
     * it was removed with everything in it. The guard reads the name the target resolves to, so it
     * is refused before anything is touched.
     */
    #[Test]
    #[DataProvider('stagingShapes')]
    public function aDirectoryBesideATargetRefusesATargetThatIsALinkToAStagingNamedDirectory(string $suffix): void
    {
        $parent   = TemporaryDirectory::create(self::PARENT);
        $pointee  = $parent->path . '/' . self::PREFIX . $suffix;
        $link     = $parent->path . '/current';
        $this->plantLeftover($pointee);
        \Safe\symlink(basename($pointee), $link);

        try {
            TemporaryDirectory::besides($link, self::PREFIX);
            self::fail('a link to a directory named as staging was accepted as the target');
        } catch (InvalidArgumentException $invalidArgumentException) {
            self::assertSame(\sprintf('A staging directory beside %s needs a prefix the name it resolves to, %s, does not begin with, not "%s": the target would be one of its leftovers', $link, \Safe\realpath($pointee), self::PREFIX), $invalidArgumentException->getMessage());
        } finally {
            $left = \Safe\scandir($parent->path);
            $kept = file_exists($pointee . self::DOWNLOAD);
            $parent->remove();
        }

        self::assertTrue($kept, 'the directory the target points at is kept whole');
        self::assertSame(['.', '..', 'current', basename($pointee)], $left, self::NOTHING_BESIDE_TOUCHED);
    }

    /** @return iterable<string, array{string}> */
    public static function stagingShapes(): iterable
    {
        yield 'as this version names one' => ['0123456789abcdef'];
        yield "as an earlier version's tempnam() named one" => ['Ab12Cd'];
    }

    /**
     * A target that is a link to something not named as staging, or to nothing, is staged beside
     * as any other: an installed binary may be a link.
     */
    #[Test]
    #[DataProvider('linkTargetsThatAreAccepted')]
    public function aDirectoryBesideATargetAcceptsALinkTargetThatResolvesToAnotherName(bool $dangling): void
    {
        $parent  = TemporaryDirectory::create(self::PARENT);
        $pointee = $parent->path . '/' . self::TARGET;
        $link    = $parent->path . '/current';
        if (!$dangling) {
            \Safe\file_put_contents($pointee, self::KEPT);
        }

        \Safe\symlink(basename($pointee), $link);
        $leftover = $this->stagingIn($parent->path, 14);
        $this->plantLeftover($leftover);

        $directory = TemporaryDirectory::besides($link, self::PREFIX);

        try {
            self::assertSame($parent->path, \dirname($directory->path));
            self::assertDirectoryDoesNotExist($leftover, 'a leftover beside a link target is removed');
            self::assertTrue(is_link($link), 'the target is kept');
        } finally {
            $directory->remove();
            $parent->remove();
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function linkTargetsThatAreAccepted(): iterable
    {
        yield 'a link to a file' => [false];
        yield 'a dangling link' => [true];
    }

    /**
     * Defence for the class "the cleanup removes what the target resolves into": a target that is
     * a link to a file inside an old directory named as staging beside it passed the name guard,
     * since the name it resolves to is the file's, and the cleanup removed that directory with the
     * file the target resolves to in it, leaving the target dangling. A directory holding what the
     * target resolves to is in use, not a leftover: it is kept, and is no failure to warn about.
     */
    #[Test]
    #[DataProvider('stagingShapes')]
    public function aDirectoryBesideATargetKeepsALeftoverThatHoldsWhatTheTargetResolvesTo(string $suffix): void
    {
        $parent   = TemporaryDirectory::create(self::PARENT);
        $holder   = $parent->path . '/' . self::PREFIX . $suffix;
        $leftover = $this->stagingIn($parent->path, 15);
        $link     = $parent->path . '/' . self::TARGET;
        $this->plantLeftover($holder);
        $this->plantLeftover($leftover);
        \Safe\symlink(basename($holder) . self::DOWNLOAD, $link);

        $directory = TemporaryDirectory::besides($link, self::PREFIX);

        try {
            self::assertFileExists($holder . self::DOWNLOAD, 'the directory holding what the target resolves to is kept whole');
            self::assertFileExists($link, 'the target still resolves');
            self::assertSame([], $directory->unremovedLeftovers, 'a directory in use is not a leftover that could not be removed');
            self::assertDirectoryDoesNotExist($leftover, 'a leftover not holding the target is still removed');
        } finally {
            $directory->remove();
            $parent->remove();
        }
    }

    /**
     * Defence for the class "the target guard reads a name the target does not have", through a
     * link: a target that is a link to the directory it is in, or to one above it, is that
     * directory, so the cleanup ran inside the target and the staging was made inside it, as for a
     * target spelled `lib/.`. It is refused before anything is touched.
     */
    #[Test]
    #[DataProvider('linksToTheirOwnDirectoryOrAbove')]
    public function aDirectoryBesideATargetRefusesATargetThatIsALinkToItsOwnDirectoryOrAbove(string $pointee): void
    {
        $parent   = TemporaryDirectory::create(self::PARENT);
        $library  = $parent->path . self::LIBRARY;
        $leftover = $this->stagingIn($library, 16);
        $link     = $library . '/turbo';
        $this->plantLeftover($leftover);
        \Safe\symlink($pointee, $link);

        try {
            TemporaryDirectory::besides($link, self::PREFIX);
            self::fail('a link to the directory it is in, or one above it, was accepted as the target');
        } catch (InvalidArgumentException $invalidArgumentException) {
            self::assertSame(\sprintf('A staging directory beside %s needs a target that does not resolve to the directory it is in or one above it, not %s: the staging and its leftovers would be inside the target', $link, \Safe\realpath($link)), $invalidArgumentException->getMessage());
        } finally {
            $left = \Safe\scandir($library);
            $parent->remove();
        }

        self::assertSame(['.', '..', basename($leftover), 'turbo'], $left, 'nothing in the target was touched');
    }

    /** @return iterable<string, array{string}> */
    public static function linksToTheirOwnDirectoryOrAbove(): iterable
    {
        yield 'its own directory' => ['.'];
        yield 'the directory above' => ['..'];
        yield 'the root' => ['/'];
    }

    /**
     * Defence for the class "the cleanup deletes what this class did not make": an old directory
     * whose name begins with the prefix but is not the prefix and 16 lowercase hex digits, as
     * every staging directory is named, is kept.
     */
    #[Test]
    #[DataProvider('namesThatAreNotStaging')]
    public function aDirectoryBesideATargetKeepsAnOldDirectoryNotNamedAsStaging(string $name): void
    {
        $parent  = TemporaryDirectory::create(self::PARENT);
        $another = $parent->path . '/' . $name;
        \Safe\mkdir($another);
        \Safe\file_put_contents($another . '/' . self::TARGET, self::KEPT);
        \Safe\touch($another, time() - 2 * 3600);

        $directory = TemporaryDirectory::besides($parent->path . '/' . self::TARGET, self::PREFIX);

        try {
            self::assertSame(self::KEPT, \Safe\file_get_contents($another . '/' . self::TARGET), 'a directory not named as staging is not a leftover');
        } finally {
            $directory->remove();
            $parent->remove();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function namesThatAreNotStaging(): iterable
    {
        yield 'the prefix and a word' => [self::PREFIX . '-user'];
        yield 'the prefix alone' => [self::PREFIX];
        yield 'the prefix and 15 digits' => [self::PREFIX . '0123456789abcde'];
        yield 'the prefix and 17 digits' => [self::PREFIX . '0123456789abcdef0'];
        yield 'the prefix and uppercase digits' => [self::PREFIX . '0123456789ABCDEF'];
        yield 'the prefix and a digit that is not hex' => [self::PREFIX . '0123456789abcdeg'];
        yield 'the prefix and a separator first' => [self::PREFIX . '-0123456789abcde'];
        yield 'another prefix as long, and 16 digits' => [substr(self::PREFIX, 0, -1) . 'X0123456789abcdef'];
        yield 'the prefix and 5 letters and digits' => [self::PREFIX . 'Ab12C'];
        yield 'the prefix and 7 letters and digits' => [self::PREFIX . 'Ab12CdE'];
        yield 'the prefix and 6 characters with a dash' => [self::PREFIX . 'Ab-2Cd'];
        yield 'the prefix and 6 characters with an underscore' => [self::PREFIX . 'Ab12C_'];
        yield 'the prefix and 6 characters with a dot first' => [self::PREFIX . '.b12Cd'];
        yield 'another prefix as long, and 6 letters and digits' => [substr(self::PREFIX, 0, -1) . 'XAb12Cd'];
    }

    /**
     * A leftover this run has claimed, which another run that listed it earlier claims again and
     * removes while this one is emptying it, is passed over without a word: it is gone, not kept.
     */
    #[Test]
    public function aDirectoryBesideATargetPassesOverALeftoverAnotherRunRemovesWhileThisOneEmptiesIt(): void
    {
        $parent   = TemporaryDirectory::create(self::PARENT);
        $leftover = $this->stagingIn($parent->path, 11);
        $this->plantLeftover($leftover);
        $this->registerTheWrapper();
        VanishingDirectoryStreamWrapper::vanishOnChangeOnceRenamed($leftover);

        try {
            $directory = TemporaryDirectory::besides($this->overTheWrapper($parent->path . '/' . self::TARGET), self::PREFIX);
            $directory->remove();

            self::assertTrue(VanishingDirectoryStreamWrapper::hasVanishedOnChange(), 'the cleanup reached the claimed leftover');
            self::assertSame([], $directory->unremovedLeftovers, 'a leftover another run removed is not one kept');
            self::assertSame(['.', '..'], \Safe\scandir($parent->path));
        } finally {
            $this->unregisterTheWrapper();
            $parent->remove();
        }
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
        $leftover = $this->stagingIn($parent->path, 6);
        $this->plantLeftover($leftover);
        $this->registerTheWrapper();
        VanishingDirectoryStreamWrapper::vanishOnChange($leftover);

        try {
            $directory = TemporaryDirectory::besides($this->overTheWrapper($parent->path . '/' . self::TARGET), self::PREFIX);
            $directory->remove();

            self::assertTrue(VanishingDirectoryStreamWrapper::hasVanishedOnChange(), 'the cleanup reached the leftover');
            self::assertSame([], $directory->unremovedLeftovers, 'a leftover already gone is not one kept');
            self::assertDirectoryDoesNotExist($leftover);
        } finally {
            $this->unregisterTheWrapper();
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
     * Each leftover is first moved to a fresh staging name, so that only one run removes it; each
     * one kept is named there.
     *
     * @param bool $unreadable whether the directory cannot be read, rather than removed
     */
    #[Test]
    #[DataProvider('refusals')]
    public function aDirectoryBesideATargetKeepsEachLeftoverItCannotRemoveAndNamesIt(bool $unreadable, string $why): void
    {
        $parent = TemporaryDirectory::create(self::PARENT);
        foreach ([7, 8] as $number) {
            \Safe\mkdir($this->stagingIn($parent->path, $number) . '/' . self::HELD, 0o700, true);
            \Safe\touch($this->stagingIn($parent->path, $number), time() - 2 * 3600);
        }

        $this->registerTheWrapper();
        if ($unreadable) {
            VanishingDirectoryStreamWrapper::refuseOpenNamed(self::HELD);
        } else {
            VanishingDirectoryStreamWrapper::refuseRemovalNamed(self::HELD);
        }

        try {
            $directory = TemporaryDirectory::besides($this->overTheWrapper($parent->path . '/' . self::TARGET), self::PREFIX);
            $directory->remove();

            self::assertCount(2, $directory->unremovedLeftovers);
            foreach ($directory->unremovedLeftovers as $key => $reason) {
                $kept = (string)$key;
                self::assertSame($this->overTheWrapper($parent->path), \dirname($kept));
                self::assertMatchesRegularExpression($this->stagingName(), basename($kept), 'moved to a fresh staging name');
                self::assertDirectoryExists($kept . '/' . self::HELD, 'what could not be removed is still there');
                self::assertStringContainsString($kept . '/' . self::HELD, $reason);
                self::assertStringContainsString($why, $reason);
            }
        } finally {
            $this->unregisterTheWrapper();
            $parent->remove();
        }
    }

    /** @return iterable<string, array{bool, string}> */
    public static function refusals(): iterable
    {
        yield 'a directory in it that cannot be read' => [true, 'Failed to open directory'];
        yield 'a directory in it that cannot be removed' => [false, 'Operation not permitted'];
    }

    /** A leftover that cannot even be moved aside is kept under its own name. */
    #[Test]
    public function aDirectoryBesideATargetKeepsALeftoverItCannotMoveUnderItsOwnName(): void
    {
        $parent   = TemporaryDirectory::create(self::PARENT);
        $leftover = $this->stagingIn($parent->path, 9);
        \Safe\mkdir($leftover);
        \Safe\touch($leftover, time() - 2 * 3600);
        $this->registerTheWrapper();
        VanishingDirectoryStreamWrapper::refuseRemovalNamed(basename($leftover));

        try {
            $directory = TemporaryDirectory::besides($this->overTheWrapper($parent->path . '/' . self::TARGET), self::PREFIX);
            $directory->remove();

            self::assertSame([$this->overTheWrapper($leftover)], array_keys($directory->unremovedLeftovers));
            self::assertStringStartsWith('rename(' . $this->overTheWrapper($leftover) . ',', $directory->unremovedLeftovers[$this->overTheWrapper($leftover)]);
            self::assertStringEndsWith('): Operation not permitted', $directory->unremovedLeftovers[$this->overTheWrapper($leftover)]);
            self::assertDirectoryExists($leftover);
        } finally {
            $this->unregisterTheWrapper();
            $parent->remove();
        }
    }

    /**
     * Only a filesystem failure is a leftover kept: anything else thrown while one is removed is
     * not caught, and PHP's warnings are its own again afterwards.
     */
    #[Test]
    public function aDirectoryBesideATargetLetsAnythingButAFilesystemFailurePass(): void
    {
        $parent   = TemporaryDirectory::create(self::PARENT);
        $leftover = $this->stagingIn($parent->path, 10);
        \Safe\mkdir($leftover);
        \Safe\touch($leftover, time() - 2 * 3600);
        $this->registerTheWrapper();
        $thrown = new LogicException('not a filesystem failure');
        VanishingDirectoryStreamWrapper::throwOnRename($thrown);

        try {
            TemporaryDirectory::besides($this->overTheWrapper($parent->path . '/' . self::TARGET), self::PREFIX);
            self::fail('what the cleanup threw was caught');
        } catch (LogicException $logicException) {
            self::assertSame($thrown, $logicException);
        } finally {
            $handler = set_error_handler(static fn (): bool => false);
            restore_error_handler();
            $this->unregisterTheWrapper();
            $parent->remove();
        }

        self::assertNotInstanceOf(Closure::class, $handler, 'the error handler the cleanup set is restored');
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

    private function registerTheWrapper(): void
    {
        \Safe\stream_wrapper_register(VanishingDirectoryStreamWrapper::SCHEME, VanishingDirectoryStreamWrapper::class);
    }

    private function unregisterTheWrapper(): void
    {
        \Safe\stream_wrapper_unregister(VanishingDirectoryStreamWrapper::SCHEME);
        VanishingDirectoryStreamWrapper::reset();
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

    /** Leaves at $leftover what a run killed mid-download leaves: a staging directory, with a file in it, unchanged for two hours. */
    private function plantLeftover(string $leftover): void
    {
        \Safe\mkdir($leftover . '/nested', 0o700, true);
        \Safe\file_put_contents($leftover . self::DOWNLOAD, 'x');
        \Safe\touch($leftover, time() - 2 * 3600);
    }

    /** The path of a directory in $parent named as a staging directory is: the prefix, then $number as 16 hex digits. */
    private function stagingIn(string $parent, int $number): string
    {
        return $parent . '/' . self::PREFIX . \sprintf('%016x', $number);
    }

    /** Matches the name of a staging directory: the prefix, then 16 random hex digits. */
    private function stagingName(): string
    {
        return '/^' . preg_quote(self::PREFIX, '/') . '[0-9a-f]{16}$/';
    }
}
