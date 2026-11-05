<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline\Lock;

use Closure;
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
 * Real git, real worktrees: the checkouts of one repository resolve one lock
 * directory and exclude each other, and the caller's git environment cannot
 * change which repository a project belongs to. The resolver runs with the
 * host's git configuration switched off, as every git call here does.
 *
 * @internal
 */
#[CoversClass(LockDirectoryResolver::class)]
#[CoversClass(RunLock::class)]
#[Large]
final class SharedWorktreeLockTest extends TestCase
{
    private const string GIT_DIR = 'GIT_DIR';

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
        $work   = $this->sandbox->root->path . '/work';
        $second = $this->sandbox->root->path . '/second';
        new Process(['git', 'worktree', 'add', '-b', 'second', $second], $work, GitSandbox::environment())->mustRun();

        $first        = $this->resolve($work);
        $fromSecond   = $this->resolve($second);

        self::assertSame($first, $fromSecond);
        self::assertSame(\Safe\realpath($work . '/.git') . '/php-qa-ci-lock', $first);

        $holder = new RunLock($first, new BufferedOutput());
        self::assertTrue($holder->acquire('unit', '', 'h', 1));

        $contender = new RunLock($fromSecond, new BufferedOutput());
        self::assertFalse($contender->acquire('stan', '', 'h', 2));

        $holder->release(0);
    }

    #[Test]
    public function aGitDirInheritedFromTheCallerDoesNotMakeAnotherDirectoryShareTheRepositoryLock(): void
    {
        $unrelated = $this->sandbox->root->mkdir('unrelated');
        $viaDirty  = $this->withEnvironment(
            [self::GIT_DIR => $this->sandbox->root->path . '/work/.git'],
            fn (): string => $this->resolve($unrelated),
        );

        self::assertSame($unrelated . '/qaConfig/.qa-lock', $viaDirty, 'the project root decides, not GIT_DIR');

        $work = $this->sandbox->root->path . '/work';
        $own  = $this->withEnvironment(
            [self::GIT_DIR => $unrelated . '/elsewhere.git', 'GIT_WORK_TREE' => $unrelated],
            fn (): string => $this->resolve($work),
        );

        self::assertSame(\Safe\realpath($work . '/.git') . '/php-qa-ci-lock', $own, 'a repository is still found from its own root');
    }

    #[Test]
    public function aBareRepositoryKeepsItsOwnQaConfigLockDirectory(): void
    {
        self::assertSame($this->sandbox->origin . '/qaConfig/.qa-lock', $this->resolve($this->sandbox->origin));
    }

    private function resolve(string $projectRoot): string
    {
        return $this->withEnvironment(GitSandbox::environment(), static fn (): string => new LockDirectoryResolver(new SymfonyProcessRunner(new NullOutput()))->resolve($projectRoot));
    }

    /**
     * @param array<string, string> $variables
     * @param Closure(): string     $run
     */
    private function withEnvironment(array $variables, Closure $run): string
    {
        $previous = [];
        foreach ($variables as $name => $value) {
            $previous[$name] = getenv($name);
            \Safe\putenv($name . '=' . $value);
        }

        try {
            return $run();
        } finally {
            foreach ($previous as $name => $value) {
                \Safe\putenv(false === $value ? $name : $name . '=' . $value);
            }
        }
    }
}
