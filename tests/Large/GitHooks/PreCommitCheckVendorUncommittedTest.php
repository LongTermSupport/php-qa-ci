<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\GitHooks;

use LTS\PHPQA\Tests\Support\GitSandbox;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The deployed pre-commit hook, run as git runs it in a project whose vendor/
 * holds packages installed from source, with php-qa-ci where Composer puts it
 * (vendor/lts/php-qa-ci, here a link to this checkout).
 *
 * Only first-party vendor clones are checked: the vendors named in
 * `extra.php-qa-ci.firstPartyVendors`, or, when that key is absent, the vendor
 * of the project's own name. A third-party package installed from source may
 * change its own files (a Composer plugin writes generated code into its own
 * tree) and is never the project's to commit, so it never blocks.
 *
 * With OPcache JIT on, a PHP that loads Xdebug prints "JIT is incompatible with
 * third party extensions" on STDOUT at every start unless XDEBUG_MODE=off. The
 * hook reads answers from `php` stdout, so the warning must never reach it and
 * any other stray line must be ignored. A PATH shim stands in for that PHP.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class PreCommitCheckVendorUncommittedTest extends TestCase
{
    private const string HOOK = __DIR__ . '/../../../git-hooks/pre-commit-check-vendor-uncommitted';

    private const string FIRST_PARTY = 'acme/widget';

    private const string FIRST_PARTY_DIR = 'vendor/acme/widget';

    private const string THIRD_PARTY = 'thirdparty/installer';

    private const string THIRD_PARTY_DIR = 'vendor/thirdparty/installer';

    private const string OWN_NAME = 'acme/app';

    private const string INIT = 'init';

    private const string SEEN_LOG = 'php-seen.log';

    private const string BLOCKED = 'COMMIT BLOCKED';

    private const string UNCOMMITTED = 'Uncommitted changes in vendor libraries';

    private const string OUT_OF_SYNC = 'composer.lock not tracking current vendor commits';

    private const string CHANGED_FILE = '/a.txt';

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-hook');
        $this->git($this->project->path, self::INIT, '-q');
        $this->project->mkdir('vendor/lts');
        \Safe\symlink(\dirname(__DIR__, 3), $this->project->path . '/vendor/lts/php-qa-ci');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function everyPhpTheHookStartsRunsWithXdebugOff(): void
    {
        $this->writeManifest(['name' => self::OWN_NAME]);
        $head = $this->installVendorClone(self::FIRST_PARTY_DIR);
        $this->writeLock([self::FIRST_PARTY => $head]);
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
        $this->writeManifest(['name' => self::OWN_NAME]);
        $head = $this->installVendorClone(self::FIRST_PARTY_DIR);
        $this->writeLock([self::FIRST_PARTY => $head]);
        $this->installPhpShim(warnUnlessXdebugOff: false);

        $result = $this->runHook();

        self::assertSame(0, $result->getExitCode(), $result->getOutput() . $result->getErrorOutput());
        self::assertStringNotContainsString('bad array subscript', $result->getErrorOutput());
    }

    #[Test]
    public function aLockBehindTheCloneIsStillReportedThroughAStrayLine(): void
    {
        $this->writeManifest(['name' => self::OWN_NAME]);
        $this->installVendorClone(self::FIRST_PARTY_DIR);
        $this->writeLock([self::FIRST_PARTY => str_repeat('a', 40)]);
        $this->installPhpShim(warnUnlessXdebugOff: false);

        $result = $this->runHook();

        self::assertSame(1, $result->getExitCode(), $result->getOutput() . $result->getErrorOutput());
        self::assertStringContainsString(self::OUT_OF_SYNC, $result->getOutput());
        self::assertStringContainsString(self::FIRST_PARTY_DIR, $result->getOutput());
    }

    /** A plugin writing generated code into its own source install is not the project's change. */
    #[Test]
    public function aDirtyThirdPartyCloneDoesNotBlock(): void
    {
        $this->writeManifest(['name' => self::OWN_NAME]);
        $head = $this->installVendorClone(self::THIRD_PARTY_DIR);
        $this->writeLock([self::THIRD_PARTY => $head]);
        $this->project->write(self::THIRD_PARTY_DIR . self::CHANGED_FILE, "generated\n");

        $result = $this->runHook();

        self::assertSame(0, $result->getExitCode(), $result->getOutput() . $result->getErrorOutput());
        self::assertStringNotContainsString(self::BLOCKED, $result->getOutput());
    }

    #[Test]
    public function aDirtyCloneOfAListedFirstPartyVendorBlocks(): void
    {
        $this->writeManifest(['name' => 'example/site', 'extra' => ['php-qa-ci' => ['firstPartyVendors' => ['acme']]]]);
        $firstParty = $this->installVendorClone(self::FIRST_PARTY_DIR);
        $thirdParty = $this->installVendorClone(self::THIRD_PARTY_DIR);
        $this->writeLock([self::FIRST_PARTY => $firstParty, self::THIRD_PARTY => $thirdParty]);
        $this->changeFirstPartyClone();
        $this->project->write(self::THIRD_PARTY_DIR . self::CHANGED_FILE, "generated\n");

        $result = $this->runHook();

        self::assertSame(1, $result->getExitCode(), $result->getOutput() . $result->getErrorOutput());
        self::assertStringContainsString(self::UNCOMMITTED, $result->getOutput());
        self::assertStringContainsString(self::FIRST_PARTY_DIR, $result->getOutput());
        self::assertStringNotContainsString(self::THIRD_PARTY_DIR, $result->getOutput());
    }

    /** With no firstPartyVendors key, the vendor of the project's own name is first party. */
    #[Test]
    public function theProjectsOwnVendorIsProtectedByDefault(): void
    {
        $this->writeManifest(['name' => self::OWN_NAME]);
        $head = $this->installVendorClone(self::FIRST_PARTY_DIR);
        $this->writeLock([self::FIRST_PARTY => $head]);
        $this->changeFirstPartyClone();

        $result = $this->runHook();

        self::assertSame(1, $result->getExitCode(), $result->getOutput() . $result->getErrorOutput());
        self::assertStringContainsString(self::UNCOMMITTED, $result->getOutput());
        self::assertStringContainsString(self::FIRST_PARTY_DIR, $result->getOutput());
    }

    #[Test]
    public function aLockOutOfSyncWithAThirdPartyCloneDoesNotBlockWhileAFirstPartyOneStillDoes(): void
    {
        $this->writeManifest(['name' => self::OWN_NAME]);
        $this->installVendorClone(self::FIRST_PARTY_DIR);
        $this->installVendorClone(self::THIRD_PARTY_DIR);
        $this->writeLock([self::FIRST_PARTY => str_repeat('a', 40), self::THIRD_PARTY => str_repeat('b', 40)]);

        $result = $this->runHook();

        self::assertSame(1, $result->getExitCode(), $result->getOutput() . $result->getErrorOutput());
        self::assertStringContainsString(self::OUT_OF_SYNC, $result->getOutput());
        self::assertStringContainsString(self::FIRST_PARTY_DIR, $result->getOutput());
        self::assertStringNotContainsString(self::THIRD_PARTY_DIR, $result->getOutput());
    }

    #[Test]
    public function aLockOutOfSyncWithOnlyAThirdPartyCloneDoesNotBlock(): void
    {
        $this->writeManifest(['name' => self::OWN_NAME]);
        $this->installVendorClone(self::THIRD_PARTY_DIR);
        $this->writeLock([self::THIRD_PARTY => str_repeat('b', 40)]);

        $result = $this->runHook();

        self::assertSame(0, $result->getExitCode(), $result->getOutput() . $result->getErrorOutput());
        self::assertStringNotContainsString(self::BLOCKED, $result->getOutput());
    }

    /** A setting that cannot be read is an error, never a silent "nothing is first party". */
    #[Test]
    public function aMalformedSettingBlocksWithTheSettingsOwnMessage(): void
    {
        $this->writeManifest(['name' => self::OWN_NAME, 'extra' => ['php-qa-ci' => ['firstPartyVendors' => 'acme']]]);
        $head = $this->installVendorClone(self::FIRST_PARTY_DIR);
        $this->writeLock([self::FIRST_PARTY => $head]);

        $result = $this->runHook();

        self::assertSame(1, $result->getExitCode(), $result->getOutput() . $result->getErrorOutput());
        self::assertStringContainsString(
            'extra.php-qa-ci.firstPartyVendors must be a list of vendor names (the part of a package name before the slash), for example ["acme"]',
            $result->getOutput() . $result->getErrorOutput(),
        );
    }

    /** No name and no setting: nothing is first party, nothing blocks, and the hook says so once. */
    #[Test]
    public function aProjectWithNoNameAndNoSettingProtectsNothingAndSaysSo(): void
    {
        $this->writeManifest(['type' => 'project']);
        $head = $this->installVendorClone(self::FIRST_PARTY_DIR);
        $this->writeLock([self::FIRST_PARTY => $head]);
        $this->changeFirstPartyClone();

        $result = $this->runHook();
        $output = $result->getOutput() . $result->getErrorOutput();

        self::assertSame(0, $result->getExitCode(), $output);
        self::assertStringNotContainsString(self::BLOCKED, $output);
        self::assertSame(1, substr_count($output, 'NOTE:'), $output);
        self::assertStringContainsString('extra.php-qa-ci.firstPartyVendors', $output);
    }

    /** @param array<string, mixed> $manifest */
    private function writeManifest(array $manifest): void
    {
        $this->project->write('composer.json', \Safe\json_encode($manifest, \JSON_THROW_ON_ERROR));
    }

    /** @return string the clone's HEAD commit */
    private function installVendorClone(string $directory): string
    {
        $clone = $this->project->mkdir($directory);
        $this->git($clone, self::INIT, '-q');
        $this->project->write($directory . self::CHANGED_FILE, "a\n");
        $this->git($clone, 'add', '.');
        $this->git($clone, 'commit', '-q', '-m', self::INIT);

        return trim($this->git($clone, 'rev-parse', 'HEAD'));
    }

    private function changeFirstPartyClone(): void
    {
        $this->project->write(self::FIRST_PARTY_DIR . self::CHANGED_FILE, "changed\n");
    }

    /** @param array<string, string> $references package name => locked source reference */
    private function writeLock(array $references): void
    {
        $packages = [];
        foreach ($references as $name => $reference) {
            $packages[] = ['name' => $name, 'source' => ['type' => 'git', 'reference' => $reference]];
        }

        $this->project->write('composer.lock', \Safe\json_encode([
            'packages'     => $packages,
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
            [...GitSandbox::environment(), 'PATH' => $this->project->path . '/shim-bin:' . getenv('PATH')],
        );
        $process->run();

        return $process;
    }

    private function git(string $cwd, string ...$args): string
    {
        $process = new Process(['git', ...array_values($args)], $cwd, GitSandbox::environment());
        $process->mustRun();

        return $process->getOutput();
    }
}
