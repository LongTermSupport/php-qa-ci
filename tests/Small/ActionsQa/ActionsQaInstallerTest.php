<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\ActionsQa;

use Closure;
use LTS\PHPQA\ActionsQa\ActionsQaInstaller;
use LTS\PHPQA\Filesystem\TemporaryDirectory;
use LTS\PHPQA\Pipeline\Config\ActionsQaBinary;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Tests\Support\FakeProcessRunner;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The installer runs against a canned install.sh in place of the release, and a fake process
 * runner in place of `sh`: what is asserted is the order of the checks, that a script whose digest
 * is not the pinned one is never run, and that a finished install replaces the old one whole.
 *
 * @internal
 */
#[CoversClass(ActionsQaInstaller::class)]
#[UsesClass(ActionsQaBinary::class)]
#[UsesClass(TemporaryDirectory::class)]
#[UsesClass(\LTS\PHPQA\Filesystem\DirectoryTree::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\ComposerPlugin\ChildExit::class)]
#[UsesClass(\LTS\PHPQA\Installer\InstallerOutput::class)]
#[Small]
final class ActionsQaInstallerTest extends TestCase
{
    private const string SCRIPT = "#!/bin/sh\n# a stand-in for the release's install.sh\n";

    private const string LINUX = 'Linux';

    private const string X86_64 = 'x86_64';

    private const string CURRENT_REPORT = '{"method": "1.1.0", "toolchain": "' . ActionsQaBinary::VERSION . '"}';

    private const string OLD_REPORT = '{"method": "1.0.0", "toolchain": "0.1.0"}';

    private const string OLD_PROGRAM = 'the old program';

    private const string NEW_PROGRAM = 'the new program';

    private const string PROGRAM = '/bin/actions-qa';

    private TempDir $library;

    private BufferedOutput $output;

    private BufferedOutput $errors;

    private FakeProcessRunner $processes;

    /** @var list<string> every URL the installer asked for, in order */
    private array $requests = [];

    /** @var list<list<string>> the headers sent with each request, in order */
    private array $headers = [];

    protected function setUp(): void
    {
        $this->library   = TempDir::create('phpqa-actions-qa-installer');
        $this->output    = new BufferedOutput();
        $this->errors    = new BufferedOutput();
        $this->processes = new FakeProcessRunner();
    }

    protected function tearDown(): void
    {
        $this->library->remove();
    }

    #[Test]
    public function aFreshInstallVerifiesTheScriptRunsItIntoStagingThenMovesTheBundleIntoPlace(): void
    {
        $this->processes->willRun($this->installScriptThatInstalls(self::NEW_PROGRAM))->willSucceed(self::CURRENT_REPORT);

        $exit = $this->installer()->run($this->library->path);

        self::assertSame(0, $exit, $this->errors->fetch());
        self::assertSame([ActionsQaBinary::installScriptUrl()], $this->requests);
        self::assertSame(self::NEW_PROGRAM, \Safe\file_get_contents(ActionsQaBinary::path($this->library->path)));

        $install = $this->processes->specs[0];
        self::assertSame('sh', $install->command[0]);
        self::assertStringEndsWith('/install.sh', $install->command[1]);
        self::assertSame('--prefix', $install->command[2]);
        self::assertStringStartsWith($this->library->path . '/vendor-bin/.phpqa-actions-qa-ci', $install->command[3]);
        self::assertSame($install->cwd, $install->env['TMPDIR'] ?? null);

        self::assertSame([ActionsQaBinary::path($this->library->path) . '.staged', 'version', '--json'], $this->stagedProbe());
        self::assertSame(\sprintf("Installed actions-qa-ci %s in %s\n", ActionsQaBinary::VERSION, ActionsQaBinary::installDir($this->library->path)), $this->output->fetch());
        self::assertSame([], $this->stagingLeftovers());
    }

    #[Test]
    public function theRequestNamesItsClient(): void
    {
        $this->processes->willRun($this->installScriptThatInstalls(self::NEW_PROGRAM))->willSucceed(self::CURRENT_REPORT);

        $this->installer()->run($this->library->path);

        self::assertContains('User-Agent: php-qa-ci', $this->headers[0]);
    }

    #[Test]
    public function anInstallAlreadyAtThePinnedReleaseDownloadsAndRunsNothing(): void
    {
        $this->library->write(ActionsQaBinary::INSTALL_DIR . self::PROGRAM, self::OLD_PROGRAM);
        \Safe\chmod(ActionsQaBinary::path($this->library->path), 0o755);
        $this->processes->willSucceed(self::CURRENT_REPORT);

        $exit = $this->installer()->run($this->library->path);

        self::assertSame(0, $exit);
        self::assertSame([], $this->requests);
        self::assertSame([[ActionsQaBinary::path($this->library->path), 'version', '--json']], array_map(static fn (ProcessSpecDto $spec): array => $spec->command, $this->processes->specs));
        self::assertSame(\sprintf("actions-qa-ci %s is already installed in %s\n", ActionsQaBinary::VERSION, ActionsQaBinary::installDir($this->library->path)), $this->output->fetch());
    }

    #[Test]
    public function anInstallOfAnotherReleaseIsReplacedWhole(): void
    {
        $this->library->write(ActionsQaBinary::INSTALL_DIR . self::PROGRAM, self::OLD_PROGRAM);
        $this->library->write(ActionsQaBinary::INSTALL_DIR . '/LICENSES/gone.txt', 'a file the new release does not ship');
        \Safe\chmod(ActionsQaBinary::path($this->library->path), 0o755);
        $this->processes->willSucceed(self::OLD_REPORT)->willRun($this->installScriptThatInstalls(self::NEW_PROGRAM))->willSucceed(self::CURRENT_REPORT);

        $exit = $this->installer()->run($this->library->path);

        self::assertSame(0, $exit, $this->errors->fetch());
        self::assertSame(self::NEW_PROGRAM, \Safe\file_get_contents(ActionsQaBinary::path($this->library->path)));
        self::assertFileDoesNotExist(ActionsQaBinary::installDir($this->library->path) . '/LICENSES/gone.txt');
        self::assertStringStartsWith(\sprintf("Replacing actions-qa-ci 0.1.0 with %s\n", ActionsQaBinary::VERSION), $this->output->fetch());
    }

    /** The point of the pin: a script that is not the one php-qa-ci reviewed is never executed. */
    #[Test]
    public function aScriptWhoseDigestIsNotThePinnedOneIsRefusedAndNeverRun(): void
    {
        $exit = $this->installer(script: "#!/bin/sh\necho something else\n")->run($this->library->path);

        self::assertSame(1, $exit);
        self::assertSame([], $this->processes->specs);
        self::assertStringStartsWith(
            \sprintf('ERROR: could not install actions-qa-ci %s: %s has SHA-256 ', ActionsQaBinary::VERSION, ActionsQaBinary::installScriptUrl()),
            $this->errors->fetch(),
        );
        self::assertDirectoryDoesNotExist(ActionsQaBinary::installDir($this->library->path));
        self::assertSame([], $this->stagingLeftovers());
    }

    #[Test]
    public function aDownloadThatFailsReportsTheCauseAndRunsNothing(): void
    {
        $installer = new ActionsQaInstaller(
            $this->output,
            $this->errors,
            static fn (string $url, string ...$headers): string => throw new RuntimeException('HTTP 503'),
            $this->processes,
            self::LINUX,
            self::X86_64,
            $this->scriptDigest(),
        );

        $exit = $installer->run($this->library->path);

        self::assertSame(1, $exit);
        self::assertSame([], $this->processes->specs);
        self::assertSame(\sprintf("ERROR: could not install actions-qa-ci %s: HTTP 503\n", ActionsQaBinary::VERSION), $this->errors->fetch());
        self::assertSame([], $this->stagingLeftovers());
    }

    #[Test]
    public function aFailedInstallScriptIsReportedWithItsExitAndLeavesTheOldInstallInPlace(): void
    {
        $this->library->write(ActionsQaBinary::INSTALL_DIR . self::PROGRAM, self::OLD_PROGRAM);
        \Safe\chmod(ActionsQaBinary::path($this->library->path), 0o755);
        $this->processes->willSucceed(self::OLD_REPORT)->willFail(1, "install.sh: could not download\n");

        $exit = $this->installer()->run($this->library->path);

        self::assertSame(1, $exit);
        self::assertSame(self::OLD_PROGRAM, \Safe\file_get_contents(ActionsQaBinary::path($this->library->path)));
        self::assertStringContainsString('install.sh did not install actions-qa-ci ' . ActionsQaBinary::VERSION . ' (exit 1)', $this->errors->fetch());
        self::assertSame([], $this->stagingLeftovers());
    }

    #[Test]
    public function aStagedBundleReportingAnotherReleaseIsRefused(): void
    {
        $this->processes->willRun($this->installScriptThatInstalls(self::NEW_PROGRAM))->willSucceed(self::OLD_REPORT);

        $exit = $this->installer()->run($this->library->path);

        self::assertSame(1, $exit);
        self::assertDirectoryDoesNotExist(ActionsQaBinary::installDir($this->library->path));
        self::assertStringContainsString(\sprintf('reports 0.1.0, not the pinned %s', ActionsQaBinary::VERSION), $this->errors->fetch());
        self::assertSame([], $this->stagingLeftovers());
    }

    #[Test]
    public function aHostTheReleaseBuildsNothingForIsNamedAndNothingIsFetched(): void
    {
        $installer = new ActionsQaInstaller($this->output, $this->errors, $this->fetcher(self::SCRIPT), $this->processes, 'Darwin', 'arm64', $this->scriptDigest());

        $exit = $installer->run($this->library->path);

        self::assertSame(1, $exit);
        self::assertSame([], $this->requests);
        self::assertSame(
            "ERROR: actions-qa-ci publishes no bundle for this host (Darwin arm64); it runs on Linux with an x86_64 (linux_amd64) or aarch64 (linux_arm64) CPU.\n",
            $this->errors->fetch(),
        );
    }

    #[Test]
    public function itDeclaresTheInstallDirectoryAndItsStagingAsUntracked(): void
    {
        $outputs = ActionsQaInstaller::outputs();

        self::assertCount(2, $outputs);
        self::assertSame(ActionsQaBinary::INSTALL_DIR, $outputs[0]->path);
        self::assertTrue($outputs[0]->isDirectory);
        self::assertTrue($outputs[0]->untrackedByDesign);
        self::assertStringStartsWith('vendor-bin/.phpqa-actions-qa-ci', $outputs[1]->path);
        self::assertTrue($outputs[1]->untrackedByDesign);
    }

    private function installer(string $script = self::SCRIPT): ActionsQaInstaller
    {
        return new ActionsQaInstaller($this->output, $this->errors, $this->fetcher($script), $this->processes, self::LINUX, self::X86_64, $this->scriptDigest());
    }

    /** The digest the installer is told to expect: the canned script's own, so it is the one that is run. */
    private function scriptDigest(): string
    {
        return hash('sha256', self::SCRIPT);
    }

    /** @return Closure(string, string...): string */
    private function fetcher(string $script): Closure
    {
        return function (string $url, string ...$headers) use ($script): string {
            $this->requests[] = $url;
            $this->headers[]  = array_values($headers);

            return $script;
        };
    }

    /**
     * What `sh install.sh --prefix <dir>` does to the file system: the bundle appears at the prefix.
     *
     * @return Closure(ProcessSpecDto): ProcessResultDto
     */
    private function installScriptThatInstalls(string $program): Closure
    {
        return static function (ProcessSpecDto $spec) use ($program): ProcessResultDto {
            \Safe\mkdir($spec->command[3] . '/bin', 0o755, true);
            \Safe\file_put_contents($spec->command[3] . self::PROGRAM, $program);
            \Safe\chmod($spec->command[3] . self::PROGRAM, 0o755);

            return new ProcessResultDto(0, '', '');
        };
    }

    /**
     * The staged program's version probe, with the staging directory's random name replaced so it
     * can be compared.
     *
     * @return list<string>
     */
    private function stagedProbe(): array
    {
        $probe  = $this->processes->specs[1]->command;
        $prefix = $this->processes->specs[0]->command[3];

        return [str_replace($prefix . self::PROGRAM, ActionsQaBinary::path($this->library->path) . '.staged', $probe[0]), ...\array_slice($probe, 1)];
    }

    /** @return list<string> staging directories left beside the install */
    private function stagingLeftovers(): array
    {
        $vendorBin = $this->library->path . '/vendor-bin';
        if (!is_dir($vendorBin)) {
            return [];
        }

        return array_values(array_filter(
            \Safe\scandir($vendorBin),
            static fn (mixed $entry): bool => \is_string($entry) && str_starts_with($entry, '.phpqa-actions-qa-ci'),
        ));
    }
}
