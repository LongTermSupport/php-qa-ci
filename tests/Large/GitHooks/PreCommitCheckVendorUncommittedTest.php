<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\GitHooks;

use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * With OPcache JIT on, a PHP that loads Xdebug prints "JIT is incompatible with
 * third party extensions" on STDOUT at every start unless XDEBUG_MODE=off. The
 * hook reads a `php -r` map from stdout, so the warning must never reach it and
 * any other stray line must be ignored. A PATH shim stands in for that PHP.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class PreCommitCheckVendorUncommittedTest extends TestCase
{
    private const string HOOK = __DIR__ . '/../../../git-hooks/pre-commit-check-vendor-uncommitted';

    private const string PACKAGE = 'acme/widget';

    private const string CLONE_DIR = 'vendor/acme/widget';

    private const string INIT = 'init';

    private const string SEEN_LOG = 'php-seen.log';

    private const string GIT_IDENTITY = 'user.name=t';

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-hook');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function everyPhpTheHookStartsRunsWithXdebugOff(): void
    {
        $head = $this->installVendorClone();
        $this->writeLock($head);
        $this->installPhpShim(warnUnlessXdebugOff: true);

        $result = $this->runHook();

        self::assertSame(0, $result->getExitCode(), $result->getOutput() . $result->getErrorOutput());
        $seen = trim($this->project->read(self::SEEN_LOG));
        self::assertNotSame('', $seen, 'the shim php was never reached');
        foreach (explode("\n", $seen) as $mode) {
            self::assertSame('XDEBUG_MODE=off', $mode);
        }

        self::assertStringNotContainsString('JIT is incompatible', $result->getOutput() . $result->getErrorOutput());
    }

    #[Test]
    public function aStrayLineOnPhpStdoutIsNotTakenForAPackage(): void
    {
        $head = $this->installVendorClone();
        $this->writeLock($head);
        $this->installPhpShim(warnUnlessXdebugOff: false);

        $result = $this->runHook();

        self::assertSame(0, $result->getExitCode(), $result->getOutput() . $result->getErrorOutput());
        self::assertStringNotContainsString('bad array subscript', $result->getErrorOutput());
    }

    #[Test]
    public function aLockBehindTheCloneIsStillReportedThroughAStrayLine(): void
    {
        $this->installVendorClone();
        $this->writeLock(str_repeat('a', 40));
        $this->installPhpShim(warnUnlessXdebugOff: false);

        $result = $this->runHook();

        self::assertSame(1, $result->getExitCode());
        self::assertStringContainsString('composer.lock not tracking current vendor commits', $result->getOutput());
        self::assertStringContainsString(self::CLONE_DIR, $result->getOutput());
    }

    private function installVendorClone(): string
    {
        $this->git($this->project->path, self::INIT, '-q');
        $clone = $this->project->mkdir(self::CLONE_DIR);
        $this->git($clone, self::INIT, '-q');
        $this->project->write(self::CLONE_DIR . '/a.txt', "a\n");
        $this->git($clone, 'add', '.');
        $this->git($clone, '-c', self::GIT_IDENTITY, '-c', 'user.email=t@example.com', 'commit', '-q', '-m', self::INIT);

        return trim($this->git($clone, 'rev-parse', 'HEAD'));
    }

    private function writeLock(string $reference): void
    {
        $this->project->write('composer.lock', \Safe\json_encode([
            'packages'     => [[
                'name'   => self::PACKAGE,
                'source' => ['type' => 'git', 'reference' => $reference],
            ]],
            'packages-dev' => [],
        ], \JSON_THROW_ON_ERROR));
    }

    private function installPhpShim(bool $warnUnlessXdebugOff): void
    {
        $condition = $warnUnlessXdebugOff ? '[[ "${XDEBUG_MODE:-}" != off ]]' : 'true';
        $shim      = $this->project->write('shim-bin/php', \sprintf(
            <<<'BASH'
                #!/usr/bin/env bash
                echo "XDEBUG_MODE=${XDEBUG_MODE:-unset}" >> "%1$s/php-seen.log"
                if %2$s; then
                    echo "Warning: JIT is incompatible with third party extensions that override zend_execute_ex(). JIT disabled. in Unknown on line 0"
                fi
                exec "%3$s" "$@"

                BASH,
            $this->project->path,
            $condition,
            \PHP_BINARY,
        ));
        \Safe\chmod($shim, 0o755);
    }

    private function runHook(): Process
    {
        $process = new Process(
            ['bash', self::HOOK],
            $this->project->path,
            ['PATH' => $this->project->path . '/shim-bin:' . getenv('PATH')],
        );
        $process->run();

        return $process;
    }

    private function git(string $cwd, string ...$args): string
    {
        $process = new Process(['git', ...array_values($args)], $cwd);
        $process->mustRun();

        return $process->getOutput();
    }
}
