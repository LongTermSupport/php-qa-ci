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
    #[Test]
    public function aNewDirectoryIsEmptyAndUnderTheSystemTempDirectory(): void
    {
        $directory = TemporaryDirectory::create('phpqa-temporary-directory-test');

        try {
            self::assertDirectoryExists($directory->path);
            self::assertStringStartsWith(sys_get_temp_dir() . '/phpqa-temporary-directory-test', $directory->path);
            self::assertSame(['.', '..'], \Safe\scandir($directory->path));
        } finally {
            $directory->remove();
        }
    }

    #[Test]
    public function removingItRemovesEverythingInIt(): void
    {
        $directory = TemporaryDirectory::create('phpqa-temporary-directory-test');
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
        $directory = TemporaryDirectory::create('phpqa-temporary-directory-test');
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
        $directory = TemporaryDirectory::create('phpqa-temporary-directory-test');
        $directory->remove();

        $directory->remove();

        self::assertDirectoryDoesNotExist($directory->path);
    }
}
