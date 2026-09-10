<?php

declare(strict_types=1);

namespace LTS\PHPQA\ShellCheck;

use LTS\PHPQA\Pipeline\Config\ShellCheckBinary;
use PharData;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Keeps the vendored ShellCheck in step with upstream, on the composer events
 * that already carry the PHAR tooling. This is PHP rather than Bash because it
 * is real scripting — a release lookup, an archive to unpack, a decision with
 * several branches — and because the branches are then testable
 * (see InstallDecider).
 *
 * Nothing is shelled out: the release is fetched over HTTP and unpacked with
 * PharData, which reads the `.tar.gz` asset. (PharData is exempt from
 * phar.readonly, which only governs executable phars.)
 *
 * An update is the maintainer path and is gated on phive being installed, the
 * same marker scripts/tool-install.bash uses for the PHAR rebuild: a
 * consumer's `composer update` must never reach out to GitHub or rewrite a
 * committed artefact.
 *
 * @api
 */
final readonly class ShellCheckInstaller
{
    /** Verify only: the binary is committed, so a composer install never downloads. */
    public const string MODE_INSTALL = 'install';

    /** The maintainer path, and the only place the pin ever moves. */
    public const string MODE_UPDATE = 'update';

    /** Where the newest release tag is read from. */
    private const string RELEASE_API = 'https://api.github.com/repos/koalaman/shellcheck/releases/latest';

    /** The gzip asset rather than the smaller xz one: PharData reads gzip, so nothing is shelled out. */
    private const string DOWNLOAD_URL = 'https://github.com/koalaman/shellcheck/releases/download/%1$s/shellcheck-%1$s.%2$s.tar.gz';

    /** GitHub rejects an API request with no User-Agent. */
    private const string USER_AGENT = 'php-qa-ci';

    /** Long enough for a 3.7 MB asset on a slow runner, short enough not to hang a composer event. */
    private const int TIMEOUT_SECONDS = 60;

    /** @return int the process exit code */
    public static function main(string $libraryRoot, string $mode): int
    {
        return new self()->run($libraryRoot, $mode);
    }

    public function run(string $libraryRoot, string $mode): int
    {
        $binary  = ShellCheckBinary::path($libraryRoot);
        $pinned  = ShellCheckBinary::pinnedVersion($libraryRoot);
        $present = is_file($binary);
        $decider = new InstallDecider();

        if (self::MODE_UPDATE === $mode && !$this->isMaintainerEnvironment()) {
            $this->write('ShellCheck left as committed — skipping update (not a maintainer build).');

            return 0;
        }

        $decision = self::MODE_UPDATE === $mode
            ? $decider->forUpdate($present, $pinned, $this->latestVersion())
            : $decider->forInstall($present, $pinned);

        return match ($decision->action) {
            InstallActionEnum::Ready  => $this->reportReady($decision->message, $mode),
            InstallActionEnum::Broken => $this->reportBroken($decision->message, $libraryRoot, $pinned),
            InstallActionEnum::Fetch  => $this->fetch($libraryRoot, (string)$decision->version, $decision->message),
        };
    }

    private function reportReady(string $message, string $mode): int
    {
        // An install that is simply fine says nothing: it runs on every
        // `composer install` in every consuming project.
        if (self::MODE_UPDATE === $mode) {
            $this->write($message);
        }

        return 0;
    }

    private function reportBroken(string $message, string $libraryRoot, ?string $pinned): int
    {
        $this->write('ERROR: ' . $message . '.', \STDERR);
        $this->write(\sprintf('Expected: %s (%s)', ShellCheckBinary::path($libraryRoot), ShellCheckBinary::ARCHITECTURE), \STDERR);
        $this->write('This indicates a corrupted or incomplete checkout. Re-clone, or restore it with:', \STDERR);
        $this->write(
            null === $pinned
                ? '  scripts/tool-install.bash update   (re-pins to the newest release)'
                : '  ' . ShellCheckBinary::fetchCommand($libraryRoot, $pinned),
            \STDERR,
        );

        return 1;
    }

    private function fetch(string $libraryRoot, string $version, string $message): int
    {
        $this->write($message);

        $staging = $this->stagingDirectory();

        try {
            $archive = $staging . '/shellcheck.tar.gz';
            $this->download(\sprintf(self::DOWNLOAD_URL, $version, ShellCheckBinary::ARCHITECTURE), $archive);

            $entry = \sprintf('shellcheck-%s/shellcheck', $version);
            new PharData($archive)->extractTo($staging, $entry, true);

            $binary = ShellCheckBinary::path($libraryRoot);
            if (!is_dir(\dirname($binary))) {
                \Safe\mkdir(\dirname($binary), 0o755, true);
            }

            \Safe\rename($staging . '/' . $entry, $binary);
            \Safe\chmod($binary, 0o755);
            \Safe\file_put_contents($libraryRoot . '/' . ShellCheckBinary::VERSION_FILE, $version . "\n");
        } catch (Throwable $throwable) {
            $this->remove($staging);
            $this->write('ERROR: could not install ShellCheck ' . $version . ': ' . $throwable->getMessage(), \STDERR);

            return 1;
        }

        $this->remove($staging);
        $this->write('IMPORTANT: ShellCheck is tracked in git. Commit vendor-bin/shellcheck and vendor-bin/shellcheck.version.');

        return 0;
    }

    /** The newest published release tag, or null when GitHub cannot be asked. */
    private function latestVersion(): ?string
    {
        $body = $this->get(self::RELEASE_API);
        if (null === $body) {
            return null;
        }

        try {
            $release = \Safe\json_decode($body, true);
        } catch (Throwable $throwable) {
            $this->write('Note: the GitHub release API returned something unreadable: ' . $throwable->getMessage(), \STDERR);

            return null;
        }

        if (!\is_array($release)) {
            return null;
        }

        $tag = $release['tag_name'] ?? null;

        return \is_string($tag) && '' !== $tag ? $tag : null;
    }

    private function download(string $url, string $destination): void
    {
        $body = $this->get($url);
        if (null === $body) {
            throw new RuntimeException('download failed: ' . $url);
        }

        \Safe\file_put_contents($destination, $body);
    }

    /** The response body, or null when the request failed for any reason. */
    private function get(string $url): ?string
    {
        $headers = ['User-Agent: ' . self::USER_AGENT, 'Accept: application/vnd.github+json'];
        $token   = getenv('GITHUB_AUTH_TOKEN');
        if (\is_string($token) && '' !== $token) {
            // Unauthenticated release lookups are 60 an hour, which a handful
            // of runs exhausts.
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $context = stream_context_create([
            'http' => [
                'header'        => implode("\r\n", $headers),
                'timeout'       => self::TIMEOUT_SECONDS,
                'ignore_errors' => false,
            ],
        ]);

        try {
            return \Safe\file_get_contents($url, false, $context);
        } catch (Throwable $throwable) {
            // Not fatal on its own: the caller decides, and for a release
            // lookup the answer is to leave the pin alone. The cause is still
            // worth printing, or a rate limit looks like an empty release list.
            $this->write('Note: could not fetch ' . $url . ': ' . $throwable->getMessage(), \STDERR);

            return null;
        }
    }

    private function isMaintainerEnvironment(): bool
    {
        return null !== new ExecutableFinder()->find('phive');
    }

    private function stagingDirectory(): string
    {
        $path = \Safe\tempnam(sys_get_temp_dir(), 'phpqa-shellcheck');
        \Safe\unlink($path);
        \Safe\mkdir($path, 0o700, true);

        return $path;
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (\Safe\scandir($directory) as $entry) {
            if (!\is_string($entry) || '.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->remove($path);

                continue;
            }

            $this->write('Note: could not remove the temporary file ' . $path, \STDERR);
        }

        $this->write('Note: could not remove the temporary directory ' . $directory, \STDERR);
    }

    /** @param resource $stream */
    private function write(string $line, $stream = \STDOUT): void
    {
        \Safe\fwrite($stream, $line . \PHP_EOL);
    }
}
