<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Filesystem;

use LTS\PHPQA\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The staging directory the installers download into. Removing it removes
 * everything in it: a cleanup that only reports what it did not remove leaves
 * every update's download behind in the system temp directory.
 *
 * @internal
 */
#[CoversClass(TemporaryDirectory::class)]
#[Small]
final class TemporaryDirectoryTest extends TestCase
{
    private const string PREFIX = 'phpqa-temporary-directory-test';

    #[Test]
    public function aNewDirectoryIsEmptyAndUnderTheSystemTempDirectory(): void
    {
        $directory = TemporaryDirectory::create(self::PREFIX);

        try {
            self::assertDirectoryExists($directory->path);
            self::assertStringStartsWith(sys_get_temp_dir() . '/' . self::PREFIX, $directory->path);
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
        $parent    = TemporaryDirectory::create('phpqa-temporary-directory-parent');
        $directory = TemporaryDirectory::besides($parent->path . '/missing/yet/library.so', self::PREFIX);

        try {
            self::assertDirectoryExists($directory->path);
            self::assertSame($parent->path . '/missing/yet', \dirname($directory->path), "created in the target's directory, which is created if absent");
            self::assertStringStartsWith(self::PREFIX, basename($directory->path));
            self::assertSame(['.', '..'], \Safe\scandir($directory->path));
        } finally {
            $directory->remove();
            $parent->remove();
        }
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
}
