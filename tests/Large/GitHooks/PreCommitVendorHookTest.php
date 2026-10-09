<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\GitHooks;

use LTS\PHPQA\Tests\Support\GitSandbox;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The deployed pre-commit hook in a project whose vendor/ holds a package
 * installed from source, run as git runs it. The hook reads composer.lock
 * through `php`, so anything that `php` prints besides its answer reaches the
 * hook too: the OPcache JIT's warning when Xdebug is loaded is printed on
 * stdout at the start of every process (#100).
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class PreCommitVendorHookTest extends TestCase
{
    private const string HOOK = __DIR__ . '/../../../git-hooks/pre-commit-check-vendor-uncommitted';

    private const string JIT_WARNING = 'Warning: JIT is incompatible with third party extensions that override zend_execute_ex(). JIT disabled. in Unknown on line 0';

    private const string PACKAGE = 'vendor/acme/lib';

    private ?TempDir $project = null;

    protected function tearDown(): void
    {
        $this->project?->remove();
    }

    #[Test]
    public function aVendorPackageAtItsLockedCommitPasses(): void
    {
        $head = $this->projectWithVendorPackage();
        $this->lock($head);

        $hook = $this->runHook(withJitWarning: false);

        self::assertSame(0, $hook->getExitCode(), $hook->getOutput() . $hook->getErrorOutput());
    }

    #[Test]
    public function aWarningPrintedByPhpDoesNotStopTheHook(): void
    {
        $head = $this->projectWithVendorPackage();
        $this->lock($head);

        $hook = $this->runHook(withJitWarning: true);

        self::assertSame(0, $hook->getExitCode(), $hook->getOutput() . $hook->getErrorOutput());
    }

    /** The stray line is skipped, not the answer: a package off its locked commit is still caught. */
    #[Test]
    public function aWarningPrintedByPhpDoesNotHideAnOutOfSyncPackage(): void
    {
        $this->projectWithVendorPackage();
        $this->lock(str_repeat('a', 40));

        $hook = $this->runHook(withJitWarning: true);

        self::assertSame(1, $hook->getExitCode(), $hook->getOutput() . $hook->getErrorOutput());
        self::assertStringContainsString('composer.lock not tracking current vendor commits', $hook->getOutput());
    }

    /** @return string the vendor package's HEAD commit */
    private function projectWithVendorPackage(): string
    {
        $project       = TempDir::create('phpqa-pre-commit-hook');
        $this->project = $project;

        $this->git($project->path, 'init', '--quiet');
        $project->write(self::PACKAGE . '/src/Lib.php', "<?php\n");
        $package = $project->path . '/' . self::PACKAGE;
        $this->git($package, 'init', '--quiet');
        $this->git($package, 'add', '-A');
        $this->git($package, 'commit', '--quiet', '-m', 'Initial');

        return trim($this->git($package, 'rev-parse', 'HEAD'));
    }

    private function lock(string $reference): void
    {
        $project = $this->project;
        self::assertNotNull($project);
        $project->write('composer.lock', \Safe\json_encode([
            'packages' => [[
                'name'   => 'acme/lib',
                'source' => ['type' => 'git', 'url' => 'https://example.invalid/acme/lib.git', 'reference' => $reference],
            ]],
            'packages-dev' => [],
        ]));
    }

    private function runHook(bool $withJitWarning): Process
    {
        $project = $this->project;
        self::assertNotNull($project);

        $path = getenv('PATH');
        self::assertIsString($path, 'PATH is not set');
        if ($withJitWarning) {
            $shim = $project->write('shim/php', "#!/bin/sh\nprintf '\\n%s\\n' '" . self::JIT_WARNING . "'\nexec " . escapeshellarg(\PHP_BINARY) . " \"\$@\"\n");
            \Safe\chmod($shim, 0o755);
            $path = \dirname($shim) . ':' . $path;
        }

        $hook = new Process(['bash', self::HOOK], $project->path, [...GitSandbox::environment(), 'PATH' => $path]);
        $hook->run();

        return $hook;
    }

    private function git(string $cwd, string ...$args): string
    {
        $git = new Process(['git', ...array_values($args)], $cwd, GitSandbox::environment());
        $git->run();
        if (!$git->isSuccessful()) {
            throw new RuntimeException(\sprintf('git %s failed in %s: %s', implode(' ', $args), $cwd, $git->getErrorOutput()));
        }

        return $git->getOutput();
    }
}
