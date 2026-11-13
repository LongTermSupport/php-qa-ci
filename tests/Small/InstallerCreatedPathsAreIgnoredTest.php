<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use Iterator;
use LTS\PHPQA\Pipeline\Config\ShellCheckBinary;
use LTS\PHPQA\Tests\Support\GitSandbox;
use LTS\PHPQA\Tests\Support\TempDir;
use LTS\PHPQA\Turbo\TurboInstaller;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

use function Safe\file_get_contents;
use function Safe\symlink;

/**
 * Defence for the class "a path an installer creates under the package directory that the package's
 * .gitignore does not match as it can exist".
 *
 * `vendor/lts/php-qa-ci/` is a git checkout when a project requires a `dev-` branch, and the shipped
 * pre-commit vendor hook counts an untracked entry there as uncommitted work. An installer's output
 * is untracked by design, so each path it creates must be ignored whether it is a real directory or
 * file or a symlink to one (a worktree seeded from another checkout's installed dependencies makes
 * it a symlink, and a pattern ending in a slash matches directories only).
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class InstallerCreatedPathsAreIgnoredTest extends TestCase
{
    private TempDir $repository;

    protected function setUp(): void
    {
        $this->freshRepository();
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    #[Test]
    #[DataProvider('createdPaths')]
    public function aPathAnInstallerCreatesIsIgnoredAsARealEntryAndAsASymlinkToOne(string $path, bool $isDirectory): void
    {
        $this->create($path, $isDirectory, false);

        self::assertSame(0, $this->checkIgnore($path), $path . ' (' . ($isDirectory ? 'a directory' : 'a file') . ') is not ignored; add it to .gitignore without a trailing slash');

        $this->repository->remove();
        $this->freshRepository();
        $this->create($path, $isDirectory, true);

        self::assertSame(0, $this->checkIgnore($path), $path . ' as a symlink is not ignored; a .gitignore pattern ending in a slash matches directories only');
    }

    /** @return Iterator<string, array{string, bool}> */
    public static function createdPaths(): Iterator
    {
        yield 'the PHPStan Turbo binaries (bin/turbo-install)' => [TurboInstaller::TURBO_DIR, true];

        yield 'a ShellCheck build for another architecture (bin/shellcheck-install)' => [
            ltrim(ShellCheckBinary::pathFor('', ShellCheckBinary::ARCHITECTURE_AARCH64), '/'),
            false,
        ];
    }

    /** An empty repository holding only this package's own .gitignore. */
    private function freshRepository(): void
    {
        $this->repository = TempDir::create('installer-paths-');
        $this->repository->write('.gitignore', file_get_contents(\dirname(__DIR__, 2) . '/.gitignore'));
        $this->git('init', '--quiet');
    }

    private function create(string $path, bool $isDirectory, bool $asSymlink): void
    {
        if ($asSymlink) {
            $target = $isDirectory ? $this->repository->mkdir('elsewhere') : $this->repository->write('elsewhere', 'x');
            $this->repository->mkdir(\dirname($path));
            symlink($target, $this->repository->path . '/' . $path);

            return;
        }

        $isDirectory ? $this->repository->mkdir($path) : $this->repository->write($path, 'x');
    }

    private function checkIgnore(string $path): int
    {
        $process = new Process(['git', 'check-ignore', '--quiet', '--', $path], $this->repository->path, GitSandbox::environment());
        $process->run();

        // 0: ignored; 1: not ignored; 128: an error.
        return (int)$process->getExitCode();
    }

    private function git(string ...$arguments): void
    {
        new Process(['git', ...$arguments], $this->repository->path, GitSandbox::environment())->mustRun();
    }
}
