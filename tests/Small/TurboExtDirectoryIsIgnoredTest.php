<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use Iterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

use function Safe\file_get_contents;
use function Safe\file_put_contents;
use function Safe\mkdir;
use function Safe\symlink;

/**
 * Defence for the class "a path another release line's installer creates under the package directory
 * that this line's .gitignore does not match".
 *
 * The `php8.5` line's installer creates `vendor-phar/turbo-ext/` (the PHPStan Turbo binaries). This
 * line has no such installer, but `vendor/lts/php-qa-ci/` is a git checkout when a project requires a
 * `dev-` branch, and a project that was on `php8.5` and moves to this line keeps the directory. Git
 * then lists it as untracked, and the shipped pre-commit vendor hook counts an untracked entry in a
 * vendor checkout as uncommitted work and refuses every commit until it is deleted by hand.
 *
 * A directory and a symlink to one (a worktree seeded from another checkout's installed
 * dependencies) must both be ignored, and the tracked manifest `vendor-phar/turbo-ext.json` must not
 * be.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class TurboExtDirectoryIsIgnoredTest extends TestCase
{
    private const string TURBO_DIRECTORY = 'vendor-phar/turbo-ext';

    private string $repository;

    protected function setUp(): void
    {
        $this->repository = sys_get_temp_dir() . '/php-qa-ci-gitignore-' . bin2hex(random_bytes(6));
        mkdir($this->repository . '/vendor-phar', 0o777, true);
        file_put_contents($this->repository . '/.gitignore', file_get_contents(\dirname(__DIR__, 2) . '/.gitignore'));
        $this->git('init', '--quiet');
    }

    protected function tearDown(): void
    {
        $this->git('worktree', 'prune');
        new Process(['rm', '-rf', '--', $this->repository])->mustRun();
    }

    #[Test]
    #[DataProvider('shapes')]
    public function theTurboDirectoryIsIgnoredAsADirectoryAndAsASymlinkToOne(bool $asSymlink): void
    {
        if ($asSymlink) {
            mkdir($this->repository . '/elsewhere');
            symlink($this->repository . '/elsewhere', $this->repository . '/' . self::TURBO_DIRECTORY);
        } else {
            mkdir($this->repository . '/' . self::TURBO_DIRECTORY);
        }

        self::assertSame(0, $this->checkIgnore(self::TURBO_DIRECTORY), self::TURBO_DIRECTORY . ' is not ignored (' . ($asSymlink ? 'a symlink' : 'a directory') . '); add /vendor-phar/turbo-ext to .gitignore');
    }

    /** @return Iterator<string, array{bool}> */
    public static function shapes(): Iterator
    {
        yield 'a real directory' => [false];

        yield 'a symlink to a directory' => [true];
    }

    #[Test]
    public function theTrackedManifestBesideTheDirectoryIsNotIgnored(): void
    {
        file_put_contents($this->repository . '/' . self::TURBO_DIRECTORY . '.json', '{}');

        self::assertSame(1, $this->checkIgnore(self::TURBO_DIRECTORY . '.json'), 'vendor-phar/turbo-ext.json is a tracked file and must not be ignored');
    }

    private function checkIgnore(string $path): int
    {
        $process = new Process(['git', 'check-ignore', '--quiet', '--', $path], $this->repository, $this->isolatedGitEnvironment());
        $process->run();

        // 0: ignored; 1: not ignored; 128: an error.
        return (int)$process->getExitCode();
    }

    private function git(string ...$arguments): void
    {
        new Process(['git', ...$arguments], $this->repository, $this->isolatedGitEnvironment())->mustRun();
    }

    /** @return array<string, string> the environment that keeps git from reading the host's configuration */
    private function isolatedGitEnvironment(): array
    {
        return ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1'];
    }
}
