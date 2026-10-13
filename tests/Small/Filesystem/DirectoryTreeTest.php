<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Filesystem;

use FilesystemIterator;
use LTS\PHPQA\Filesystem\DirectoryTree;
use LTS\PHPQA\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Removing a directory tree. A link to a directory reports isDir(), so a walk
 * that rmdir()s every directory it meets fails on the link ("Not a directory")
 * and leaves the rest of the tree behind; a walk that followed it would empty
 * the directory it points at. The link is removed and its target left alone.
 *
 * @internal
 */
#[CoversClass(DirectoryTree::class)]
#[Small]
final class DirectoryTreeTest extends TestCase
{
    private const string PREFIX = 'phpqa-directory-tree-test';

    private const string KEEP = '/keep.txt';

    private const string LINK = '/link';

    /** The walk that has to know about links, spelt so this file does not contain it. */
    private const string CHILD_FIRST_WALK = 'RecursiveIteratorIterator::' . 'CHILD_FIRST';

    #[Test]
    public function removingATreeRemovesEverythingInIt(): void
    {
        $directory = TemporaryDirectory::create(self::PREFIX);
        \Safe\mkdir($directory->path . '/nested/deeper', 0o700, true);
        \Safe\file_put_contents($directory->path . '/top.txt', 'x');
        \Safe\file_put_contents($directory->path . '/nested/deeper/file.txt', 'x');

        DirectoryTree::remove($directory->path);

        self::assertDirectoryDoesNotExist($directory->path);
    }

    #[Test]
    public function aLinkToADirectoryIsRemovedAndItsTargetKept(): void
    {
        $outside   = TemporaryDirectory::create('phpqa-directory-tree-target');
        $directory = TemporaryDirectory::create(self::PREFIX);
        \Safe\file_put_contents($outside->path . self::KEEP, 'x');
        \Safe\mkdir($directory->path . '/nested');
        \Safe\symlink($outside->path, $directory->path . '/nested' . self::LINK);

        try {
            DirectoryTree::remove($directory->path);

            self::assertDirectoryDoesNotExist($directory->path);
            self::assertFileExists($outside->path . self::KEEP);
        } finally {
            $outside->remove();
        }
    }

    #[Test]
    public function emptyingADirectoryKeepsTheDirectoryAndRemovesWhatIsInIt(): void
    {
        $outside   = TemporaryDirectory::create('phpqa-directory-tree-target');
        $directory = TemporaryDirectory::create(self::PREFIX);
        \Safe\file_put_contents($outside->path . self::KEEP, 'x');
        \Safe\mkdir($directory->path . '/nested/deeper', 0o700, true);
        \Safe\file_put_contents($directory->path . '/nested/deeper/file.txt', 'x');
        \Safe\symlink($outside->path, $directory->path . self::LINK);

        try {
            DirectoryTree::empty($directory->path);

            self::assertSame(['.', '..'], \Safe\scandir($directory->path));
            self::assertFileExists($outside->path . self::KEEP);
        } finally {
            $directory->remove();
            $outside->remove();
        }
    }

    #[Test]
    public function aPathThatIsNotADirectoryIsLeftAlone(): void
    {
        $directory = TemporaryDirectory::create(self::PREFIX);
        $file      = $directory->path . '/file.txt';
        \Safe\file_put_contents($file, 'x');

        try {
            DirectoryTree::remove($directory->path . '/missing');
            DirectoryTree::empty($directory->path . '/missing');
            DirectoryTree::remove($file);
            DirectoryTree::empty($file);

            self::assertFileExists($file);
        } finally {
            $directory->remove();
        }
    }

    /**
     * The link handling lives in one place. Every other child-first walk in
     * the repository is a second copy of it, which is how two of them came to
     * rmdir() a link.
     */
    #[Test]
    public function noOtherCodeWalksATreeChildFirst(): void
    {
        $root   = \dirname(__DIR__, 3);
        $owner  = $root . '/src/Filesystem/DirectoryTree.php';
        $copies = [];
        foreach (['src', 'tests', 'bin', 'scripts'] as $top) {
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $top, FilesystemIterator::SKIP_DOTS));
            foreach ($entries as $entry) {
                if (!$entry instanceof SplFileInfo || 'php' !== $entry->getExtension()) {
                    continue;
                }

                $path = $entry->getPathname();
                if ($path === $owner || str_starts_with($path, $root . '/tests/assets/')) {
                    continue;
                }

                if (str_contains(\Safe\file_get_contents($path), self::CHILD_FIRST_WALK)) {
                    $copies[] = substr($path, \strlen($root) + 1);
                }
            }
        }

        sort($copies);
        self::assertSame([], $copies, 'remove or empty a tree with ' . DirectoryTree::class . ', which removes a link instead of following it');
    }
}
