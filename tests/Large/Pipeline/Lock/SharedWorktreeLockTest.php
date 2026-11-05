<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline\Lock;

use LTS\PHPQA\Pipeline\Lock\LockDirectoryResolver;
use LTS\PHPQA\Pipeline\Lock\RunLock;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use LTS\PHPQA\Tests\Support\GitSandbox;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Process\Process;

/**
 * Real git, a real second worktree: the two checkouts resolve one lock directory
 * and exclude each other.
 *
 * @internal
 */
#[CoversClass(LockDirectoryResolver::class)]
#[CoversClass(RunLock::class)]
#[Large]
final class SharedWorktreeLockTest extends TestCase
{
    private GitSandbox $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = GitSandbox::create(['README.md' => "seed\n"]);
    }

    protected function tearDown(): void
    {
        $this->sandbox->remove();
    }

    #[Test]
    public function twoWorktreesOfOneRepositoryShareOneLockAndExcludeEachOther(): void
    {
        $second = $this->sandbox->root->path . '/second';
        $add    = new Process(['git', 'worktree', 'add', '-b', 'second', $second], $this->sandbox->root->path . '/work', GitSandbox::environment());
        $add->mustRun();

        $resolver = new LockDirectoryResolver(new SymfonyProcessRunner(new NullOutput()));
        $first    = $resolver->resolve($this->sandbox->root->path . '/work');

        self::assertSame($first, $resolver->resolve($second));
        self::assertSame(\Safe\realpath($this->sandbox->root->path . '/work/.git') . '/php-qa-ci-lock', $first);

        $holder = new RunLock($first, new BufferedOutput());
        self::assertTrue($holder->acquire('unit', '', 'h', 1));

        $contender = new RunLock($resolver->resolve($second), new BufferedOutput());
        self::assertFalse($contender->acquire('stan', '', 'h', 2));

        $holder->release(0);
    }
}
