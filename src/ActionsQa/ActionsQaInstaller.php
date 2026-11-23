<?php

declare(strict_types=1);

namespace LTS\PHPQA\ActionsQa;

use Closure;
use LTS\PHPQA\Filesystem\DirectoryTree;
use LTS\PHPQA\Filesystem\TemporaryDirectory;
use LTS\PHPQA\Installer\InstallerOutput;
use LTS\PHPQA\Installer\InstallerOutputsInterface;
use LTS\PHPQA\Pipeline\Config\ActionsQaBinary;
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
 * Run by hand, or by CI, with the command the lane prints; never by a composer event, so a
 * consumer's `composer install` does not download the bundle unasked.
 *
 * @api
 */
final readonly class ActionsQaInstaller implements InstallerOutputsInterface
{
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

    /**
     * @param Closure(string, string...): string $fetch          the body at a URL, given the request headers; throws when the request fails
     * @param string                             $os             PHP_OS_FAMILY of the host the bundle is for
     * @param string                             $machine        php_uname('m') of that host
     * @param string                             $expectedDigest the SHA-256 install.sh must have to be run
     */
    public function __construct(
        private OutputInterface $output,
        private OutputInterface $errors,
        private Closure $fetch,
        private ProcessRunnerInterface $processes,
        private string $os,
        private string $machine,
        private string $expectedDigest = ActionsQaBinary::INSTALL_SCRIPT_SHA256,
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

    /** @return int the process exit code */
    public static function main(string $libraryRoot, ConsoleOutputInterface $output): int
    {
        return new self(
            $output,
            $output->getErrorOutput(),
            self::httpGet(...),
            new SymfonyProcessRunner($output),
            \PHP_OS_FAMILY,
            php_uname('m'),
        )->run($libraryRoot);
    }

    public function run(string $libraryRoot): int
    {
        if (null === ActionsQaBinary::platformFor($this->os, $this->machine)) {
            $this->error(\sprintf(
                'ERROR: actions-qa-ci publishes no bundle for this host (%s %s); it runs on Linux with an x86_64 (%s) or aarch64 (%s) CPU.',
                $this->os,
                $this->machine,
                ActionsQaBinary::PLATFORM_AMD64,
                ActionsQaBinary::PLATFORM_ARM64,
            ));

            return 1;
        }

        $installDir = ActionsQaBinary::installDir($libraryRoot);
        $program    = ActionsQaBinary::path($libraryRoot);
        if (is_executable($program)) {
            $installed = $this->reportedVersion($program, $libraryRoot);
            if (ActionsQaBinary::VERSION === $installed) {
                $this->write(\sprintf('actions-qa-ci %s is already installed in %s', ActionsQaBinary::VERSION, $installDir));

                return 0;
            }

            $this->write(\sprintf('Replacing actions-qa-ci %s with %s', $installed ?? '(a release it could not report)', ActionsQaBinary::VERSION));
        }

        $staging = null;

        try {
            $staging = TemporaryDirectory::besides($installDir, self::STAGING_PREFIX);
            foreach ($staging->unremovedLeftovers as $leftover => $why) {
                $this->error(\sprintf('WARNING: could not remove %s, a staging directory a killed install left: %s', $leftover, $why));
            }

            $bundle = $this->installInto($staging->path);
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

    /** Downloads, verifies and runs install.sh into the staging directory; returns the staged bundle. */
    private function installInto(string $staging): string
    {
        $url    = ActionsQaBinary::installScriptUrl();
        $script = ($this->fetch)($url, self::USER_AGENT);
        $digest = hash(self::DIGEST_ALGORITHM, $script);
        if (!hash_equals($this->expectedDigest, $digest)) {
            throw new RuntimeException(\sprintf('%s has SHA-256 %s, not the %s php-qa-ci pins, so it was not run', $url, $digest, $this->expectedDigest));
        }

        $scriptPath = $staging . '/' . self::SCRIPT;
        $bundle     = $staging . '/' . self::BUNDLE;
        \Safe\file_put_contents($scriptPath, $script);

        $result = $this->processes->run(new ProcessSpecDto(
            ['sh', $scriptPath, '--prefix', $bundle],
            $staging,
            ['TMPDIR' => $staging],
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
