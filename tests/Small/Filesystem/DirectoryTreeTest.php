<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Filesystem;

use FilesystemIterator;
use LTS\PHPQA\Filesystem\DirectoryTree;
use LTS\PHPQA\Tests\Support\TempDir;
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

    // The walk that has to know about links, spelt so this file does not contain it.
    private const string CHILD_FIRST_WALK = RecursiveIteratorIterator::class . '::CHILD_FIRST';

    #[Test]
    public function removingATreeRemovesEverythingInIt(): void
    {
        $directory = TempDir::create(self::PREFIX);
        \Safe\mkdir($directory->path . '/nested/deeper', 0o700, true);
        \Safe\file_put_contents($directory->path . '/top.txt', 'x');
        \Safe\file_put_contents($directory->path . '/nested/deeper/file.txt', 'x');

        DirectoryTree::remove($directory->path);

        self::assertDirectoryDoesNotExist($directory->path);
    }

    #[Test]
    public function aLinkToADirectoryIsRemovedAndItsTargetKept(): void
    {
        $outside   = TempDir::create('phpqa-directory-tree-target');
        $directory = TempDir::create(self::PREFIX);
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
        $outside   = TempDir::create('phpqa-directory-tree-target');
        $directory = TempDir::create(self::PREFIX);
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
        $directory = TempDir::create(self::PREFIX);
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
     * The link handling lives in two places: DirectoryTree for src/, and
     * TempDir for test support, which cannot call into src/ without every test
     * executing production code its coverage metadata does not list. Any other
     * child-first walk is a further copy, which is how two of them came to
     * rmdir() a link.
     */
    #[Test]
    public function noOtherCodeWalksATreeChildFirst(): void
    {
        $root   = \dirname(__DIR__, 3);
        $owners = [$root . '/src/Filesystem/DirectoryTree.php', $root . '/tests/Support/TempDir.php'];
        $copies = [];
        foreach (['src', 'tests', 'bin', 'scripts'] as $top) {
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $top, FilesystemIterator::SKIP_DOTS));
            foreach ($entries as $entry) {
                if (!$entry instanceof SplFileInfo || 'php' !== $entry->getExtension()) {
                    continue;
                }

                $path = $entry->getPathname();
                if (\in_array($path, $owners, true) || str_starts_with($path, $root . '/tests/assets/')) {
                    continue;
                }

                if (str_contains(\Safe\file_get_contents($path), self::CHILD_FIRST_WALK)) {
                    $copies[] = substr($path, \strlen($root) + 1);
                }
            }
        }

        sort($copies);
        self::assertSame([], $copies, 'remove or empty a tree with ' . DirectoryTree::class . ' (or, in test support, ' . TempDir::class . '::removeTree()), which removes a link instead of following it');
    }
}
