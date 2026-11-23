<?php

declare(strict_types=1);

namespace LTS\PHPQA\ActionsQa;

use Closure;
use LTS\PHPQA\Filesystem\DirectoryTree;
use LTS\PHPQA\Filesystem\TemporaryDirectory;
use LTS\PHPQA\Installer\InstallerOutput;
use LTS\PHPQA\Installer\InstallerOutputsInterface;
use LTS\PHPQA\Pipeline\Config\ActionsQaBinary;
use LTS\PHPQA\Pipeline\Config\ProjectQaConfigReader;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\ProcessRunnerInterface;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Puts the pinned actions-qa-ci bundle in the library's untracked vendor-bin/, for the actionsQa
 * lane. Nothing is committed and nothing is piped into a shell: the release's install.sh is
 * downloaded, its SHA-256 is compared with the one php-qa-ci pins, and only a match is run, with
 * a prefix in a staging directory beside the install. install.sh then checks the bundle against
 * the digest written into it. The staged bundle has to report the pinned release before it
 * replaces the installed one whole, so a failure at any step leaves the old install as it was.
 *
 * A consumer's `composer install` and `composer update` run it through ActionsQaInstallPlugin,
 * php-qa-ci's own through scripts/tool-install.bash; it can also be run by hand with the command
 * the lane prints. An install already at the pinned release downloads nothing. A project that
 * declares withOfflineBuild(true) downloads nothing either: the install says so in one SKIPPED
 * line and succeeds, and `--from <directory>` installs from release assets copied to the host,
 * with install.sh held to the same pin.
 *
 * @api
 */
final readonly class ActionsQaInstaller implements InstallerOutputsInterface
{
    /** Fetch the pinned bundle when it is missing or of another release. */
    public const string MODE_INSTALL = 'install';

    /**
     * Read-only: say on stdout whether the pinned release is installed, for an install killed
     * before it could say so itself. The Composer plugin and scripts/tool-install.bash ask it.
     */
    public const string MODE_STATE = 'state';

    /** The state check's exit status when the bundle is missing, of another release, or has no build for the host. */
    public const int EXIT_NOT_CURRENT = 3;

    /** What the staging directory beside the install is named from; a random suffix follows. */
    private const string STAGING_PREFIX = '.phpqa-actions-qa-ci';

    /** A suffix of the length TemporaryDirectory::besides() adds, to show what a staging directory is called. */
    private const string STAGING_SAMPLE_SUFFIX = '0123456789abcdef';

    /** GitHub rejects a request with no User-Agent. */
    private const string USER_AGENT = 'User-Agent: php-qa-ci';

    /** Long enough for the install script on a slow runner, short enough not to hang. */
    private const int FETCH_TIMEOUT_SECONDS = 60;

    /** install.sh downloads a bundle of some tens of megabytes. */
    private const float INSTALL_TIMEOUT_SECONDS = 900.0;

    /** The digest install.sh is compared by. */
    private const string DIGEST_ALGORITHM = 'sha256';

    /** Staging-relative: the downloaded install.sh. */
    private const string SCRIPT = 'install.sh';

    /** Staging-relative: the prefix install.sh installs into; it refuses one that exists. */
    private const string BUNDLE = 'bundle';

    /** Install-prefix-relative: the program a finished install holds. */
    private const string PROGRAM = '/bin/actions-qa';

    /** The option naming a directory of release assets to install from instead of downloading. */
    private const string FROM_OPTION = '--from';

    /** What install.sh reads to unpack a tarball on disk instead of downloading one. */
    private const string BUNDLE_ENV = 'ACTIONS_QA_CI_BUNDLE';

    /**
     * @param Closure(string, string...): string $fetch          the body at a URL, given the request headers; throws when the request fails
     * @param string                             $os             PHP_OS_FAMILY of the host the bundle is for
     * @param string                             $machine        php_uname('m') of that host
     * @param string                             $expectedDigest the SHA-256 install.sh must have to be run
     * @param (Closure(): bool)|null             $offlineBuild   whether the project declares withOfflineBuild(true), asked
     *                                                           only before a download; throws when its config cannot be
     *                                                           read; null for a project that cannot declare it
     */
    public function __construct(
        private OutputInterface $output,
        private OutputInterface $errors,
        private Closure $fetch,
        private ProcessRunnerInterface $processes,
        private string $os,
        private string $machine,
        private string $expectedDigest = ActionsQaBinary::INSTALL_SCRIPT_SHA256,
        private ?Closure $offlineBuild = null,
    ) {
    }

    /**
     * The install directory and its staging directory are both untracked by design.
     *
     * @internal
     */
    public static function outputs(): array
    {
        return [
            new InstallerOutput(ActionsQaBinary::INSTALL_DIR, true, true),
            new InstallerOutput(\dirname(ActionsQaBinary::INSTALL_DIR) . '/' . self::STAGING_PREFIX . self::STAGING_SAMPLE_SUFFIX, true, true),
        ];
    }

    /**
     * @param string $projectRoot the project whose qaConfig/qa.php says whether it builds offline
     *
     * @return int the process exit code
     */
    public static function main(string $libraryRoot, string $projectRoot, ConsoleOutputInterface $output, string ...$arguments): int
    {
        return new self(
            $output,
            $output->getErrorOutput(),
            self::httpGet(...),
            // The runner traces each command it runs; on stderr, so the state check's stdout is
            // only what it says is on disk, which the Composer plugin relays.
            new SymfonyProcessRunner($output->getErrorOutput()),
            \PHP_OS_FAMILY,
            php_uname('m'),
            offlineBuild: static function () use ($projectRoot, $libraryRoot): bool {
                $config = new ProjectQaConfigReader()->read($projectRoot, $libraryRoot);

                return null !== $config && $config->offlineBuild;
            },
        )->run($libraryRoot, ...$arguments);
    }

    /** @param string ...$arguments the command line: a mode, and for install an optional `--from <directory>` */
    public function run(string $libraryRoot, string ...$arguments): int
    {
        $mode      = null;
        $from      = null;
        $arguments = array_values($arguments);
        while ([] !== $arguments) {
            $argument = array_shift($arguments);
            if (self::FROM_OPTION === $argument || str_starts_with($argument, self::FROM_OPTION . '=')) {
                $from = self::FROM_OPTION === $argument ? array_shift($arguments) : substr($argument, \strlen(self::FROM_OPTION) + 1);
                if (null === $from || '' === $from) {
                    return $this->usage(self::FROM_OPTION . ' needs the directory that holds the release assets');
                }

                continue;
            }

            if (null === $mode && \in_array($argument, [self::MODE_INSTALL, self::MODE_STATE], true)) {
                $mode = $argument;

                continue;
            }

            return $this->usage(\sprintf("unknown argument '%s'", $argument));
        }

        if (self::MODE_STATE === $mode) {
            return null === $from ? $this->state($libraryRoot) : $this->usage(self::FROM_OPTION . ' names what to install from, and the state check installs nothing');
        }

        return $this->install($libraryRoot, $from);
    }

    private function usage(string $problem): int
    {
        $this->error(\sprintf('ERROR: %s. Usage: actions-qa-install [%s] [%s <directory>], or actions-qa-install %s', $problem, self::MODE_INSTALL, self::FROM_OPTION, self::MODE_STATE));

        return 1;
    }

    /** Changes nothing and fetches nothing: says whether the pinned release is what is installed. */
    private function state(string $libraryRoot): int
    {
        if (null === ActionsQaBinary::platformFor($this->os, $this->machine)) {
            $this->write($this->unsupportedHost());

            return self::EXIT_NOT_CURRENT;
        }

        $installDir = ActionsQaBinary::installDir($libraryRoot);
        $command    = ActionsQaBinary::installCommand($libraryRoot);
        $program    = ActionsQaBinary::path($libraryRoot);
        if (!is_executable($program)) {
            $this->write(\sprintf('actions-qa-ci is not installed in %s, so the actionsQa lane crashes until %s installs it.', $installDir, $command));

            return self::EXIT_NOT_CURRENT;
        }

        $installed = $this->reportedVersion($program, $libraryRoot);
        if (ActionsQaBinary::VERSION === $installed) {
            $this->write(\sprintf('actions-qa-ci %s, the pinned release, is installed in %s.', ActionsQaBinary::VERSION, $installDir));

            return 0;
        }

        $this->write(\sprintf(
            '%s holds %s, not the pinned %s, so the actionsQa lane crashes until %s replaces it.',
            $installDir,
            null === $installed ? 'an actions-qa-ci that reports no readable release' : 'actions-qa-ci ' . $installed,
            ActionsQaBinary::VERSION,
            $command,
        ));

        return self::EXIT_NOT_CURRENT;
    }

    private function unsupportedHost(): string
    {
        return \sprintf(
            'actions-qa-ci publishes no bundle for this host (%s %s); it runs on Linux with an x86_64 (%s) or aarch64 (%s) CPU.',
            $this->os,
            $this->machine,
            ActionsQaBinary::PLATFORM_AMD64,
            ActionsQaBinary::PLATFORM_ARM64,
        );
    }

    /** @param string|null $from a directory of release assets to install from, or null to download them */
    private function install(string $libraryRoot, ?string $from): int
    {
        $platform = ActionsQaBinary::platformFor($this->os, $this->machine);
        if (null === $platform) {
            $this->error('ERROR: ' . $this->unsupportedHost());

            return 1;
        }

        $installDir = ActionsQaBinary::installDir($libraryRoot);
        $program    = ActionsQaBinary::path($libraryRoot);
        $present    = is_executable($program);
        $installed  = $present ? $this->reportedVersion($program, $libraryRoot) : null;
        if (ActionsQaBinary::VERSION === $installed) {
            $this->write(\sprintf('actions-qa-ci %s is already installed in %s', ActionsQaBinary::VERSION, $installDir));

            return 0;
        }

        if (null === $from) {
            try {
                $offline = $this->offlineBuild instanceof Closure && true === ($this->offlineBuild)();
            } catch (Throwable $throwable) {
                $this->error('ERROR: could not read qaConfig/qa.php, so whether this project builds offline is unknown and nothing was downloaded: ' . $throwable->getMessage());

                return 1;
            }

            if ($offline) {
                $this->write(\sprintf(
                    'SKIPPED actionsQa (%s): this project builds offline (withOfflineBuild(true) in qaConfig/qa.php), so actions-qa-ci %s was not downloaded. Provision it from the release assets with: %s %s <directory>',
                    ActionsQaBinary::IDENTIFIER,
                    ActionsQaBinary::VERSION,
                    ActionsQaBinary::installCommand($libraryRoot),
                    self::FROM_OPTION,
                ));

                return 0;
            }
        }

        if ($present) {
            $this->write(\sprintf('Replacing actions-qa-ci %s with %s', $installed ?? '(a release it could not report)', ActionsQaBinary::VERSION));
        }

        $staging = null;

        try {
            $staging = TemporaryDirectory::besides($installDir, self::STAGING_PREFIX);
            foreach ($staging->unremovedLeftovers as $leftover => $why) {
                $this->error(\sprintf('WARNING: could not remove %s, a staging directory a killed install left: %s', $leftover, $why));
            }

            $bundle = $this->installInto($staging->path, $platform, $from);
            if (is_dir($installDir)) {
                DirectoryTree::remove($installDir);
            }

            \Safe\rename($bundle, $installDir);
        } catch (Throwable $throwable) {
            $staging?->remove();
            $this->error(\sprintf('ERROR: could not install actions-qa-ci %s: %s', ActionsQaBinary::VERSION, $throwable->getMessage()));

            return 1;
        }

        $staging->remove();
        $this->write(\sprintf('Installed actions-qa-ci %s in %s', ActionsQaBinary::VERSION, $installDir));

        return 0;
    }

    /**
     * Downloads install.sh, or reads it from $from, verifies it and runs it into the staging
     * directory; returns the staged bundle. From a directory, install.sh is handed the platform's
     * tarball beside it rather than downloading one, and checks it against its own digest.
     */
    private function installInto(string $staging, string $platform, ?string $from): string
    {
        $env = ['TMPDIR' => $staging];
        if (null === $from) {
            $source = ActionsQaBinary::installScriptUrl();
            $script = ($this->fetch)($source, self::USER_AGENT);
        } else {
            $from    = rtrim(str_starts_with($from, '/') ? $from : \Safe\getcwd() . '/' . $from, '/');
            $source  = $from . '/' . self::SCRIPT;
            $tarball = $from . '/' . ActionsQaBinary::bundleAsset($platform);
            if (!is_file($source)) {
                throw new RuntimeException(\sprintf('%s does not exist: %s holds no install.sh of the release', $source, $from));
            }

            if (!is_file($tarball)) {
                throw new RuntimeException(\sprintf('%s holds no %s, the bundle for this host, beside install.sh', $from, ActionsQaBinary::bundleAsset($platform)));
            }

            $script                = \Safe\file_get_contents($source);
            $env[self::BUNDLE_ENV] = $tarball;
        }

        $digest = hash(self::DIGEST_ALGORITHM, $script);
        if (!hash_equals($this->expectedDigest, $digest)) {
            throw new RuntimeException(\sprintf('%s has SHA-256 %s, not the %s php-qa-ci pins, so it was not run', $source, $digest, $this->expectedDigest));
        }

        $scriptPath = $staging . '/' . self::SCRIPT;
        $bundle     = $staging . '/' . self::BUNDLE;
        \Safe\file_put_contents($scriptPath, $script);

        $result = $this->processes->run(new ProcessSpecDto(
            ['sh', $scriptPath, '--prefix', $bundle],
            $staging,
            $env,
            self::INSTALL_TIMEOUT_SECONDS,
        ));
        if (!$result->succeeded()) {
            throw new RuntimeException(\sprintf('install.sh did not install actions-qa-ci %s (%s)', ActionsQaBinary::VERSION, $result->describeExit()));
        }

        $reported = $this->reportedVersion($bundle . self::PROGRAM, $staging);
        if (ActionsQaBinary::VERSION !== $reported) {
            throw new RuntimeException(\sprintf('the bundle install.sh installed reports %s, not the pinned %s', $reported ?? 'no readable version', ActionsQaBinary::VERSION));
        }

        return $bundle;
    }

    private function reportedVersion(string $program, string $cwd): ?string
    {
        $result = $this->processes->run(new ProcessSpecDto([$program, 'version', '--json'], $cwd, streamOutput: false));

        return $result->succeeded() ? ActionsQaBinary::reportedVersion($result->stdout) : null;
    }

    /** The body at a URL, fetched over HTTP with the request headers given. */
    private static function httpGet(string $url, string ...$headers): string
    {
        $context = stream_context_create([
            'http' => [
                'header'        => implode("\r\n", $headers),
                'timeout'       => self::FETCH_TIMEOUT_SECONDS,
                'ignore_errors' => false,
            ],
        ]);

        return \Safe\file_get_contents($url, false, $context);
    }

    private function write(string $line): void
    {
        $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
    }

    private function error(string $line): void
    {
        $this->errors->writeln($line, OutputInterface::OUTPUT_RAW);
    }
}
