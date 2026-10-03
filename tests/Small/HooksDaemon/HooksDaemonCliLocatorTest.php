<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\HooksDaemon;

use LTS\PHPQA\HooksDaemon\HooksDaemonCliLocator;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        $this->root->mkdir('.git');
        $cli = $this->root->write(HooksDaemonCliLocator::CLI, "#!/bin/bash\n");

        self::assertSame($cli, new HooksDaemonCliLocator()->locate($this->root->path));
    }

    #[Test]
    public function theDaemonAtTheRootOfTheWorkTreeServesAPackageNestedInIt(): void
    {
        $this->root->mkdir('.git');
        $cli     = $this->root->write(HooksDaemonCliLocator::CLI, "#!/bin/bash\n");
        $package = $this->root->mkdir(self::PACKAGE);

        self::assertSame($cli, new HooksDaemonCliLocator()->locate($package));
    }

    #[Test]
    public function aDaemonAboveTheWorkTreeBelongsToAnotherCheckoutAndIsNotUsed(): void
    {
        $this->root->write(HooksDaemonCliLocator::CLI, "#!/bin/bash\n");
        $checkout = $this->root->mkdir('checkout');
        $this->root->write('checkout/.git', "gitdir: /elsewhere\n");

        self::assertNull(new HooksDaemonCliLocator()->locate($checkout));
    }

    #[Test]
    public function noDaemonAnywhereIsNull(): void
    {
        $this->root->mkdir('.git');
        $package = $this->root->mkdir(self::PACKAGE);

        self::assertNull(new HooksDaemonCliLocator()->locate($package));
    }
}
