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

    private const string FROM = '--from';

    private const string USAGE = 'Usage: actions-qa-install [install] [--from <directory>], or actions-qa-install state';

    private TempDir $library;

    /** @var list<TempDir> release-asset directories a test made */
    private array $assets = [];

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
        foreach ($this->assets as $assets) {
            $assets->remove();
        }
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

        self::assertSame(self::versionProbe(ActionsQaBinary::path($this->library->path) . '.staged'), $this->stagedProbe());
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
        self::assertSame([self::versionProbe(ActionsQaBinary::path($this->library->path))], array_map(static fn (ProcessSpecDto $spec): array => $spec->command, $this->processes->specs));
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
    public function installIsTheModeWhenNoneIsNamed(): void
    {
        $this->library->write(ActionsQaBinary::INSTALL_DIR . self::PROGRAM, self::OLD_PROGRAM);
        \Safe\chmod(ActionsQaBinary::path($this->library->path), 0o755);
        $this->processes->willSucceed(self::CURRENT_REPORT)->willSucceed(self::CURRENT_REPORT);

        self::assertSame(0, $this->installer()->run($this->library->path));
        self::assertSame(0, $this->installer()->run($this->library->path, ActionsQaInstaller::MODE_INSTALL));
        self::assertSame([], $this->requests);
    }

    #[Test]
    public function anArgumentItDoesNotKnowIsRefusedAndNothingIsDone(): void
    {
        $exit = $this->installer()->run($this->library->path, 'upgrade');

        self::assertSame(1, $exit);
        self::assertSame([], $this->requests);
        self::assertSame([], $this->processes->specs);
        self::assertSame("ERROR: unknown argument 'upgrade'. " . self::USAGE . "\n", $this->errors->fetch());
    }

    #[Test]
    public function fromWithNoDirectoryIsRefused(): void
    {
        self::assertSame(1, $this->installer()->run($this->library->path, self::FROM));
        self::assertSame('ERROR: --from needs the directory that holds the release assets. ' . self::USAGE . "\n", $this->errors->fetch());
    }

    /** The state check changes nothing, so a source to install from has no place in it. */
    #[Test]
    public function fromWithTheStateCheckIsRefused(): void
    {
        self::assertSame(1, $this->installer()->run($this->library->path, ActionsQaInstaller::MODE_STATE, self::FROM, $this->library->path));
        self::assertSame('ERROR: --from names what to install from, and the state check installs nothing. ' . self::USAGE . "\n", $this->errors->fetch());
        self::assertSame([], $this->processes->specs);
    }

    /** An offline project downloads nothing: one named SKIPPED line, and the install still succeeds. */
    #[Test]
    public function anOfflineBuildDownloadsNothingAndSaysSoByName(): void
    {
        $exit = $this->installer(offline: true)->run($this->library->path);

        self::assertSame(0, $exit, $this->errors->fetch());
        self::assertSame([], $this->requests);
        self::assertSame([], $this->processes->specs);
        self::assertSame(
            \sprintf(
                "SKIPPED actionsQa (phpqaci.actionsQa): this project builds offline (withOfflineBuild(true) in qaConfig/qa.php), so actions-qa-ci %s was not downloaded. Provision it from the release assets with: %s --from <directory>\n",
                ActionsQaBinary::VERSION,
                ActionsQaBinary::installCommand($this->library->path),
            ),
            $this->output->fetch(),
        );
        self::assertDirectoryDoesNotExist(ActionsQaBinary::installDir($this->library->path));
    }

    /** A pre-seeded offline host already has the pinned release, which is the truth to report. */
    #[Test]
    public function anOfflineBuildWithThePinnedReleaseInPlaceSaysItIsInstalled(): void
    {
        $this->library->write(ActionsQaBinary::INSTALL_DIR . self::PROGRAM, self::OLD_PROGRAM);
        \Safe\chmod(ActionsQaBinary::path($this->library->path), 0o755);
        $this->processes->willSucceed(self::CURRENT_REPORT);

        self::assertSame(0, $this->installer(offline: true)->run($this->library->path));
        self::assertSame([], $this->requests);
        self::assertStringStartsWith(\sprintf('actions-qa-ci %s is already installed', ActionsQaBinary::VERSION), $this->output->fetch());
    }

    /** Whether to download is the project's to say, so a qa.php that cannot be read stops the install. */
    #[Test]
    public function aProjectConfigThatCannotBeReadDownloadsNothing(): void
    {
        $installer = new ActionsQaInstaller(
            $this->output,
            $this->errors,
            $this->fetcher(self::SCRIPT),
            $this->processes,
            self::LINUX,
            self::X86_64,
            $this->scriptDigest(),
            static fn (): bool => throw new RuntimeException('qa.php must return a closure'),
        );

        self::assertSame(1, $installer->run($this->library->path));
        self::assertSame([], $this->requests);
        self::assertSame(
            "ERROR: could not read qaConfig/qa.php, so whether this project builds offline is unknown and nothing was downloaded: qa.php must return a closure\n",
            $this->errors->fetch(),
        );
    }

    /**
     * An air-gapped host installs from the release assets copied to it. install.sh is still held
     * to the pinned digest, and it is handed the tarball beside it rather than downloading one,
     * which it then checks against the digest written into it.
     */
    #[Test]
    public function fromADirectoryTheVerifiedScriptInstallsTheBundleBesideIt(): void
    {
        $assets = $this->releaseAssets(self::SCRIPT);
        $this->processes->willRun($this->installScriptThatInstalls(self::NEW_PROGRAM))->willSucceed(self::CURRENT_REPORT);

        $exit = $this->installer(offline: true)->run($this->library->path, self::FROM, $assets->path);

        self::assertSame(0, $exit, $this->errors->fetch());
        self::assertSame([], $this->requests);
        self::assertSame(self::NEW_PROGRAM, \Safe\file_get_contents(ActionsQaBinary::path($this->library->path)));
        self::assertSame($assets->path . '/' . ActionsQaBinary::bundleAsset(ActionsQaBinary::PLATFORM_AMD64), $this->processes->specs[0]->env['ACTIONS_QA_CI_BUNDLE'] ?? null);
        self::assertStringStartsWith($this->library->path . '/vendor-bin/.phpqa-actions-qa-ci', $this->processes->specs[0]->command[1], 'the verified copy in staging is what runs, not the file in the source directory');
    }

    #[Test]
    public function fromTakesItsDirectoryAfterAnEqualsSignToo(): void
    {
        $assets = $this->releaseAssets(self::SCRIPT);
        $this->processes->willRun($this->installScriptThatInstalls(self::NEW_PROGRAM))->willSucceed(self::CURRENT_REPORT);

        self::assertSame(0, $this->installer()->run($this->library->path, ActionsQaInstaller::MODE_INSTALL, self::FROM . '=' . $assets->path . '/'));
        self::assertSame($assets->path . '/' . ActionsQaBinary::bundleAsset(ActionsQaBinary::PLATFORM_AMD64), $this->processes->specs[0]->env['ACTIONS_QA_CI_BUNDLE'] ?? null);
    }

    /** The pin holds whatever the source: a copied install.sh that is not the reviewed one never runs. */
    #[Test]
    public function fromADirectoryAScriptWhoseDigestIsNotThePinnedOneIsRefusedAndNeverRun(): void
    {
        $assets = $this->releaseAssets("#!/bin/sh\necho something else\n");

        $exit = $this->installer()->run($this->library->path, self::FROM, $assets->path);

        self::assertSame(1, $exit);
        self::assertSame([], $this->processes->specs);
        self::assertStringStartsWith(
            \sprintf('ERROR: could not install actions-qa-ci %s: %s/install.sh has SHA-256 ', ActionsQaBinary::VERSION, $assets->path),
            $this->errors->fetch(),
        );
        self::assertDirectoryDoesNotExist(ActionsQaBinary::installDir($this->library->path));
        self::assertSame([], $this->stagingLeftovers());
    }

    #[Test]
    public function fromADirectoryWithoutTheBundleForThisHostRunsNothing(): void
    {
        $assets = $this->assetDirectory();
        $assets->write('install.sh', self::SCRIPT);

        $exit = $this->installer()->run($this->library->path, self::FROM, $assets->path);

        self::assertSame(1, $exit);
        self::assertSame([], $this->processes->specs);
        self::assertSame(
            \sprintf(
                "ERROR: could not install actions-qa-ci %s: %s holds no %s, the bundle for this host, beside install.sh\n",
                ActionsQaBinary::VERSION,
                $assets->path,
                ActionsQaBinary::bundleAsset(ActionsQaBinary::PLATFORM_AMD64),
            ),
            $this->errors->fetch(),
        );
    }

    #[Test]
    public function fromADirectoryWithoutTheScriptRunsNothing(): void
    {
        $assets = $this->assetDirectory();

        self::assertSame(1, $this->installer()->run($this->library->path, self::FROM, $assets->path));
        self::assertSame([], $this->processes->specs);
        self::assertStringContainsString(\sprintf('%s/install.sh', $assets->path), $this->errors->fetch());
    }

    /** The state check is read-only: it asks the installed program its release and fetches nothing. */
    #[Test]
    public function theStateOfThePinnedReleaseInstalledIsReportedAsCurrent(): void
    {
        $this->library->write(ActionsQaBinary::INSTALL_DIR . self::PROGRAM, self::OLD_PROGRAM);
        \Safe\chmod(ActionsQaBinary::path($this->library->path), 0o755);
        $this->processes->willSucceed(self::CURRENT_REPORT);

        $exit = $this->installer()->run($this->library->path, ActionsQaInstaller::MODE_STATE);

        self::assertSame(0, $exit);
        self::assertSame([], $this->requests);
        self::assertSame([self::versionProbe(ActionsQaBinary::path($this->library->path))], array_map(static fn (ProcessSpecDto $spec): array => $spec->command, $this->processes->specs));
        self::assertSame(\sprintf("actions-qa-ci %s, the pinned release, is installed in %s.\n", ActionsQaBinary::VERSION, ActionsQaBinary::installDir($this->library->path)), $this->output->fetch());
    }

    #[Test]
    public function theStateOfAMissingBundleIsReportedWithTheCommandThatInstallsIt(): void
    {
        $exit = $this->installer()->run($this->library->path, ActionsQaInstaller::MODE_STATE);

        self::assertSame(ActionsQaInstaller::EXIT_NOT_CURRENT, $exit);
        self::assertSame([], $this->requests);
        self::assertSame([], $this->processes->specs);
        self::assertSame(
            \sprintf(
                "actions-qa-ci is not installed in %s, so the actionsQa lane crashes until %s installs it.\n",
                ActionsQaBinary::installDir($this->library->path),
                ActionsQaBinary::installCommand($this->library->path),
            ),
            $this->output->fetch(),
        );
        self::assertDirectoryDoesNotExist(ActionsQaBinary::installDir($this->library->path));
    }

    #[Test]
    public function theStateOfAnotherReleaseIsReportedWithTheCommandThatReplacesIt(): void
    {
        $this->library->write(ActionsQaBinary::INSTALL_DIR . self::PROGRAM, self::OLD_PROGRAM);
        \Safe\chmod(ActionsQaBinary::path($this->library->path), 0o755);
        $this->processes->willSucceed(self::OLD_REPORT);

        $exit = $this->installer()->run($this->library->path, ActionsQaInstaller::MODE_STATE);

        self::assertSame(ActionsQaInstaller::EXIT_NOT_CURRENT, $exit);
        self::assertSame([], $this->requests);
        self::assertSame(
            \sprintf(
                "%s holds actions-qa-ci 0.1.0, not the pinned %s, so the actionsQa lane crashes until %s replaces it.\n",
                ActionsQaBinary::installDir($this->library->path),
                ActionsQaBinary::VERSION,
                ActionsQaBinary::installCommand($this->library->path),
            ),
            $this->output->fetch(),
        );
        self::assertSame(self::OLD_PROGRAM, \Safe\file_get_contents(ActionsQaBinary::path($this->library->path)));
    }

    #[Test]
    public function theStateOfABundleThatReportsNoReleaseSaysSo(): void
    {
        $this->library->write(ActionsQaBinary::INSTALL_DIR . self::PROGRAM, self::OLD_PROGRAM);
        \Safe\chmod(ActionsQaBinary::path($this->library->path), 0o755);
        $this->processes->willFail(1, 'it crashed');

        $exit = $this->installer()->run($this->library->path, ActionsQaInstaller::MODE_STATE);

        self::assertSame(ActionsQaInstaller::EXIT_NOT_CURRENT, $exit);
        self::assertStringStartsWith(
            \sprintf('%s holds an actions-qa-ci that reports no readable release, not the pinned %s, ', ActionsQaBinary::installDir($this->library->path), ActionsQaBinary::VERSION),
            $this->output->fetch(),
        );
    }

    #[Test]
    public function theStateOnAHostTheReleaseBuildsNothingForNamesTheHost(): void
    {
        $installer = new ActionsQaInstaller($this->output, $this->errors, $this->fetcher(self::SCRIPT), $this->processes, 'Darwin', 'arm64', $this->scriptDigest());

        $exit = $installer->run($this->library->path, ActionsQaInstaller::MODE_STATE);

        self::assertSame(ActionsQaInstaller::EXIT_NOT_CURRENT, $exit);
        self::assertSame([], $this->processes->specs);
        self::assertSame(
            "actions-qa-ci publishes no bundle for this host (Darwin arm64); it runs on Linux with an x86_64 (linux_amd64) or aarch64 (linux_arm64) CPU.\n",
            $this->output->fetch(),
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

    /**
     * The command that asks a program which release it is.
     *
     * @return list<string>
     */
    private static function versionProbe(string $program): array
    {
        return [$program, 'version', '--json'];
    }

    private function installer(string $script = self::SCRIPT, bool $offline = false): ActionsQaInstaller
    {
        return new ActionsQaInstaller($this->output, $this->errors, $this->fetcher($script), $this->processes, self::LINUX, self::X86_64, $this->scriptDigest(), static fn (): bool => $offline);
    }

    /** An empty directory to hold release assets, removed after the test. */
    private function assetDirectory(): TempDir
    {
        $assets         = TempDir::create('phpqa-actions-qa-assets');
        $this->assets[] = $assets;

        return $assets;
    }

    /** A directory holding a release's assets as an air-gapped host receives them. */
    private function releaseAssets(string $script): TempDir
    {
        $assets = $this->assetDirectory();
        $assets->write('install.sh', $script);
        $assets->write(ActionsQaBinary::bundleAsset(ActionsQaBinary::PLATFORM_AMD64), 'a stand-in for the tarball');

        return $assets;
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
