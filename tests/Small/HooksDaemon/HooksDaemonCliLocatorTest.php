<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\HooksDaemon;

use LTS\PHPQA\HooksDaemon\HooksDaemonCliLocator;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Safe\Exceptions\FilesystemException;

/**
 * The daemon CLI is found in the project, or in a directory above it up to the
 * root of the git work tree the project sits in (a package in a monorepo), and
 * never above that work tree: a daemon there belongs to some other checkout.
 *
 * @internal
 */
#[CoversClass(HooksDaemonCliLocator::class)]
#[Small]
final class HooksDaemonCliLocatorTest extends TestCase
{
    private const string PACKAGE = 'packages/app';

    private const string WORK_TREE_MARKER = '.git';

    private const string CLI_SCRIPT = "#!/bin/bash\n";

    private const string MAIN_CHECKOUT = 'main/';

    private const string WORKTREE_GITDIR = 'main/.git/worktrees/feature';

    private const string WORKTREE_COMMONDIR = 'main/.git/worktrees/feature/commondir';

    private const string COMMONDIR_TO_MAIN_GIT = "../..\n";

    private const string GITDIR_POINTER = 'gitdir: ';

    private const string WORKTREE = 'main/untracked/worktrees/feature/';

    private TempDir $root;

    protected function setUp(): void
    {
        $this->root = TempDir::create('phpqaci-daemon-cli');
    }

    protected function tearDown(): void
    {
        $this->root->remove();
    }

    #[Test]
    public function theDaemonInTheProjectItselfIsFound(): void
    {
        $this->root->mkdir(self::WORK_TREE_MARKER);
        $cli = $this->root->write(HooksDaemonCliLocator::CLI, self::CLI_SCRIPT);

        self::assertSame($cli, new HooksDaemonCliLocator()->locate($this->root->path));
    }

    #[Test]
    public function theDaemonAtTheRootOfTheWorkTreeServesAPackageNestedInIt(): void
    {
        $this->root->mkdir(self::WORK_TREE_MARKER);
        $cli     = $this->root->write(HooksDaemonCliLocator::CLI, self::CLI_SCRIPT);
        $package = $this->root->mkdir(self::PACKAGE);

        self::assertSame($cli, new HooksDaemonCliLocator()->locate($package));
    }

    #[Test]
    public function aDaemonAboveTheWorkTreeBelongsToAnotherCheckoutAndIsNotUsed(): void
    {
        $this->root->write(HooksDaemonCliLocator::CLI, self::CLI_SCRIPT);
        $checkout = $this->root->mkdir('checkout');
        $this->root->write('checkout/' . self::WORK_TREE_MARKER, "gitdir: /elsewhere\n");

        self::assertNull(new HooksDaemonCliLocator()->locate($checkout));
    }

    /**
     * A linked worktree is another checkout of the same repository: its `.git` file
     * points into the main checkout's `.git/worktrees/`, whose `commondir` leads back
     * to the main `.git`. The daemon, gitignored, exists only in the main checkout.
     */
    #[Test]
    public function aLinkedWorktreeUsesTheDaemonOfItsMainCheckout(): void
    {
        $cli = $this->root->write(self::MAIN_CHECKOUT . HooksDaemonCliLocator::CLI, self::CLI_SCRIPT);
        $this->root->write(self::WORKTREE_COMMONDIR, self::COMMONDIR_TO_MAIN_GIT);
        $worktree = $this->root->mkdir(self::WORKTREE);
        $this->root->write(self::WORKTREE . self::WORK_TREE_MARKER, self::GITDIR_POINTER . $this->root->path . '/' . self::WORKTREE_GITDIR . "\n");
        $package = $this->root->mkdir(self::WORKTREE . self::PACKAGE);

        self::assertSame($cli, new HooksDaemonCliLocator()->locate($worktree));
        self::assertSame($cli, new HooksDaemonCliLocator()->locate($package));
    }

    /** From a worktree, the main checkout is walked from the same place, so a package's own daemon serves that package. */
    #[Test]
    public function aDaemonInAPackageOfTheMainCheckoutServesThatPackageInAWorktree(): void
    {
        $cli = $this->root->write(self::MAIN_CHECKOUT . self::PACKAGE . '/' . HooksDaemonCliLocator::CLI, self::CLI_SCRIPT);
        $this->root->write(self::WORKTREE_COMMONDIR, self::COMMONDIR_TO_MAIN_GIT);
        $worktree = $this->root->mkdir(self::WORKTREE);
        $this->root->write(self::WORKTREE . self::WORK_TREE_MARKER, self::GITDIR_POINTER . $this->root->path . '/' . self::WORKTREE_GITDIR . "\n");
        $package = $this->root->mkdir(self::WORKTREE . self::PACKAGE);

        self::assertSame($cli, new HooksDaemonCliLocator()->locate($package));
        self::assertNull(new HooksDaemonCliLocator()->locate($worktree), 'the package daemon serves only its package');
    }

    /** A relative project root is the same directory as the absolute one, its place in the main checkout included. */
    #[Test]
    public function aRelativeProjectRootInAWorktreeIsWalkedFromItsPlaceInTheMainCheckout(): void
    {
        $this->root->write(HooksDaemonCliLocator::CLI, self::CLI_SCRIPT);
        $cli = $this->root->write(self::MAIN_CHECKOUT . self::PACKAGE . '/' . HooksDaemonCliLocator::CLI, self::CLI_SCRIPT);
        $this->root->write(self::WORKTREE_COMMONDIR, self::COMMONDIR_TO_MAIN_GIT);
        $worktree = $this->root->mkdir(self::WORKTREE);
        $this->root->write(self::WORKTREE . self::WORK_TREE_MARKER, self::GITDIR_POINTER . $this->root->path . '/' . self::WORKTREE_GITDIR . "\n");
        $this->root->mkdir(self::WORKTREE . self::PACKAGE);

        $workingDirectory = \Safe\getcwd();
        \Safe\chdir($worktree);
        try {
            self::assertSame($cli, new HooksDaemonCliLocator()->locate(self::PACKAGE));
        } finally {
            \Safe\chdir($workingDirectory);
        }
    }

    /** A root's `.` and `..` name directories, not steps of the walk, whether the root is absolute or relative. */
    #[Test]
    public function aRootThroughDotSegmentsIsTheDirectoryItNames(): void
    {
        $this->root->write(HooksDaemonCliLocator::CLI, self::CLI_SCRIPT);
        $mainCli    = $this->root->write(self::MAIN_CHECKOUT . HooksDaemonCliLocator::CLI, self::CLI_SCRIPT);
        $packageCli = $this->root->write(self::MAIN_CHECKOUT . self::PACKAGE . '/' . HooksDaemonCliLocator::CLI, self::CLI_SCRIPT);
        $this->root->write(self::WORKTREE_COMMONDIR, self::COMMONDIR_TO_MAIN_GIT);
        $worktree = $this->root->mkdir(self::WORKTREE);
        $this->root->write(self::WORKTREE . self::WORK_TREE_MARKER, self::GITDIR_POINTER . $this->root->path . '/' . self::WORKTREE_GITDIR . "\n");
        $package = $this->root->mkdir(self::WORKTREE . self::PACKAGE);

        self::assertSame($mainCli, new HooksDaemonCliLocator()->locate($worktree . '/..'), 'the directory holding the worktree is in the main checkout');

        $workingDirectory = \Safe\getcwd();
        \Safe\chdir($package);
        try {
            self::assertSame($packageCli, new HooksDaemonCliLocator()->locate('.'));
        } finally {
            \Safe\chdir($workingDirectory);
        }
    }

    /** A pointer that exists but cannot be read is not the same as no daemon: the failure reaches the caller. */
    #[Test]
    public function aPointerThatCannotBeReadFailsRatherThanFindingNoDaemon(): void
    {
        $this->root->write(self::WORK_TREE_MARKER, self::GITDIR_POINTER . "/elsewhere\n");
        $locator = new HooksDaemonCliLocator(static fn (string $path): string => throw new FilesystemException('cannot read ' . $path));

        $this->expectExceptionObject(new FilesystemException('cannot read ' . $this->root->path . '/' . self::WORK_TREE_MARKER));

        $locator->locate($this->root->path);
    }

    /** Git writes a worktree's gitdir relative to it when `worktree.useRelativePaths` is set. */
    #[Test]
    public function aLinkedWorktreeWithARelativeGitdirUsesTheDaemonOfItsMainCheckout(): void
    {
        $cli = $this->root->write(self::MAIN_CHECKOUT . HooksDaemonCliLocator::CLI, self::CLI_SCRIPT);
        $this->root->write(self::WORKTREE_COMMONDIR, self::COMMONDIR_TO_MAIN_GIT);
        $worktree = $this->root->mkdir(self::WORKTREE);
        $this->root->write(self::WORKTREE . self::WORK_TREE_MARKER, self::GITDIR_POINTER . "../../../.git/worktrees/feature\n");

        self::assertSame($cli, new HooksDaemonCliLocator()->locate($worktree));
    }

    /** A bare repository has no checkout of its own to hold the daemon. */
    #[Test]
    public function aWorktreeOfABareRepositoryHasNoMainCheckout(): void
    {
        $this->root->write(HooksDaemonCliLocator::CLI, self::CLI_SCRIPT);
        $this->root->write('repository.git/worktrees/feature/commondir', self::COMMONDIR_TO_MAIN_GIT);

        $worktree = $this->root->mkdir('feature');
        $this->root->write('feature/' . self::WORK_TREE_MARKER, self::GITDIR_POINTER . $this->root->path . "/repository.git/worktrees/feature\n");

        self::assertNull(new HooksDaemonCliLocator()->locate($worktree));
    }

    #[Test]
    public function aWorktreeWhoseRepositoryIsGoneHasNoMainCheckout(): void
    {
        $this->root->write(self::MAIN_CHECKOUT . HooksDaemonCliLocator::CLI, self::CLI_SCRIPT);
        $this->root->write(self::WORKTREE_COMMONDIR, "../../../gone/.git\n");

        $worktree = $this->root->mkdir('main/feature');
        $this->root->write('main/feature/' . self::WORK_TREE_MARKER, self::GITDIR_POINTER . $this->root->path . '/' . self::WORKTREE_GITDIR . "\n");

        self::assertNull(new HooksDaemonCliLocator()->locate($worktree));
    }

    #[Test]
    public function aGitFileThatNamesNoGitdirEndsTheWalk(): void
    {
        $this->root->write(HooksDaemonCliLocator::CLI, self::CLI_SCRIPT);
        $checkout = $this->root->mkdir('checkout');
        $this->root->write('checkout/' . self::WORK_TREE_MARKER, "not a pointer\n");

        self::assertNull(new HooksDaemonCliLocator()->locate($checkout));
    }

    /** A submodule's gitdir has no `commondir`: the repository above it is another project. */
    #[Test]
    public function aSubmoduleDoesNotUseTheDaemonOfTheRepositoryHoldingIt(): void
    {
        $this->root->write(HooksDaemonCliLocator::CLI, self::CLI_SCRIPT);
        $this->root->mkdir('.git/modules/library');

        $submodule = $this->root->mkdir('library');
        $this->root->write('library/' . self::WORK_TREE_MARKER, "gitdir: ../.git/modules/library\n");

        self::assertNull(new HooksDaemonCliLocator()->locate($submodule));
    }

    #[Test]
    public function noDaemonAnywhereIsNull(): void
    {
        $this->root->mkdir(self::WORK_TREE_MARKER);
        $package = $this->root->mkdir(self::PACKAGE);

        self::assertNull(new HooksDaemonCliLocator()->locate($package));
    }
}
