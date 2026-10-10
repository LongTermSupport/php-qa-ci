<?php

declare(strict_types=1);

namespace LTS\PHPQA\Turbo;

use LTS\PHPQA\Filesystem\TemporaryDirectory;
use LTS\PHPQA\PhpstanDocs\PhpstanDocsCatalogue;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;
use UnexpectedValueException;

/**
 * Gives this host PHPStan's Turbo binary and its core, on the composer events that already carry
 * the PHAR tooling. The files are not committed: upstream builds a binary per OS, CPU, libc, PHP
 * minor version and thread safety, so each install fetches only the host's, from the
 * `turbo-ext/` of the phpstan/phpstan tag matching the shipped phpstan.phar. What is committed is
 * vendor-phar/turbo-ext.json, the SHA-256 of every file there, and a download whose digest differs
 * is refused.
 *
 * The files land at `vendor-phar/turbo-ext/<platform>/`, where phpstan.phar looks: it loads the
 * binary only with the platform's core beside it. A stamp beside the binary names the files and
 * digests they came from, so a phar update replaces them.
 *
 * An update regenerates the manifest from the tag, and is the maintainer path: it is gated on
 * phive being installed, the marker scripts/tool-install.bash uses for the PHAR rebuild, so a
 * consumer's `composer update` never rewrites a committed file.
 *
 * @api
 */
final readonly class TurboInstaller
{
    /** Fetch the host's binary if it is missing or stale; the manifest is left as committed. */
    public const string MODE_INSTALL = 'install';

    /** The maintainer path: regenerate the manifest for the shipped phar, then install. */
    public const string MODE_UPDATE = 'update';

    /** Where phpstan.phar looks for Turbo binaries, relative to the library root. */
    public const string TURBO_DIR = 'vendor-phar/turbo-ext';

    /** Beside the binary: the stamp of the files and digests it was installed from. */
    private const string STAMP_SUFFIX = '.source';

    public function __construct(
        private TurboReleaseSourceInterface $source,
        private TurboPlatform $platform,
        private bool $maintainer,
        private OutputInterface $output,
        private OutputInterface $errors,
    ) {
    }

    /** @return int the process exit code */
    public static function main(string $libraryRoot, string $mode): int
    {
        $output = new ConsoleOutput();

        return new self(
            new GitHubTurboReleaseSource(),
            TurboPlatform::fromRuntime(),
            null !== new ExecutableFinder()->find('phive'),
            $output,
            $output->getErrorOutput(),
        )->run($libraryRoot, $mode);
    }

    public function run(string $libraryRoot, string $mode): int
    {
        $pharVersion  = new PhpstanDocsCatalogue($libraryRoot)->installedPhpstanVersion();
        $manifestPath = $libraryRoot . '/' . TurboManifest::PATH;

        if (self::MODE_UPDATE === $mode && $this->maintainer && null !== $pharVersion && !$this->refreshManifest($manifestPath, $pharVersion)) {
            return 1;
        }

        try {
            $manifest = is_file($manifestPath) ? TurboManifest::fromJson(\Safe\file_get_contents($manifestPath)) : null;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage() . '. Restore it from git, or run scripts/tool-install.bash update.');

            return 1;
        }

        $turboDir = $libraryRoot . '/' . self::TURBO_DIR . '/';
        $relative = $this->platform->binaryPath();
        $binary   = null === $relative ? null : $turboDir . $relative;
        $core     = $this->platform->corePath();
        $decision = new TurboInstallDecider()->decide($manifest, $pharVersion, $this->platform, $this->installedStamp($binary, null === $core ? null : $turboDir . $core));

        return match ($decision->action) {
            TurboActionEnum::Ready       => $this->reportReady($decision->message, $mode),
            TurboActionEnum::Unsupported => $this->reportUnsupported($decision->message),
            TurboActionEnum::Broken      => $this->reportBroken($decision->message),
            TurboActionEnum::Fetch       => $this->fetch($turboDir, (string)$relative, (string)$pharVersion, $decision->files, $decision->message),
        };
    }

    /** False when the tag cannot give a manifest; a tag that cannot be read keeps the manifest. */
    private function refreshManifest(string $manifestPath, string $pharVersion): bool
    {
        $json = $this->source->tree($pharVersion);
        if (null === $json) {
            $this->errors->writeln(\sprintf('WARNING: could not read the phpstan/phpstan %s tree; %s left as it is.', $pharVersion, TurboManifest::PATH));

            return true;
        }

        try {
            $paths = TurboManifest::pathsInRepositoryTree($json);
        } catch (UnexpectedValueException $unexpectedValueException) {
            $this->error($unexpectedValueException->getMessage() . '.');

            return false;
        }

        $files = [];
        foreach ($paths as $path) {
            $bytes = $this->source->file($pharVersion, $path);
            if (null === $bytes) {
                $this->errors->writeln(\sprintf('WARNING: could not download turbo-ext/%s; %s left as it is.', $path, TurboManifest::PATH));

                return true;
            }

            $files[$path] = hash('sha256', $bytes);
        }

        $json = new TurboManifest($pharVersion, $files)->toJson();
        if (is_file($manifestPath) && \Safe\file_get_contents($manifestPath) === $json) {
            return true;
        }

        \Safe\file_put_contents($manifestPath, $json);
        $this->output->writeln(\sprintf('IMPORTANT: %s now pins the PHPStan Turbo %s binaries. Commit %s.', TurboManifest::PATH, $pharVersion, TurboManifest::PATH));

        return true;
    }

    private function installedStamp(?string $binary, ?string $core): ?string
    {
        if (null === $binary || null === $core || !is_file($binary) || !is_file($core) || !is_file($binary . self::STAMP_SUFFIX)) {
            return null;
        }

        return trim(\Safe\file_get_contents($binary . self::STAMP_SUFFIX));
    }

    private function reportReady(string $message, string $mode): int
    {
        // An install that is simply fine says nothing: it runs on every `composer install` in
        // every consuming project.
        if (self::MODE_UPDATE === $mode) {
            $this->output->writeln($message);
        }

        return 0;
    }

    private function reportUnsupported(string $message): int
    {
        $this->output->writeln($message);

        return 0;
    }

    private function reportBroken(string $message): int
    {
        $this->error($message . '.');

        return 1;
    }

    /**
     * @param string                $turboDir slash-terminated directory phpstan.phar looks in
     * @param string                $binary   the binary's path under it; the stamp goes beside it
     * @param array<string, string> $files    path under it => SHA-256, in the order to place them
     */
    private function fetch(string $turboDir, string $binary, string $version, array $files, string $message): int
    {
        $this->output->writeln($message);

        // Everything is downloaded and verified before anything is placed: a binary without its
        // core is a file PHPStan refuses to load, and one half of a pair is never left behind.
        $downloads = [];
        foreach ($files as $path => $digest) {
            $bytes = $this->source->file($version, $path);
            if (null === $bytes) {
                // Not fatal: PHPStan runs without Turbo, and the next install tries again.
                $this->errors->writeln(\sprintf('WARNING: could not download turbo-ext/%s; PHPStan runs without Turbo until the next composer install.', $path));

                return 0;
            }

            $actual = hash('sha256', $bytes);
            if (!hash_equals($digest, $actual)) {
                $this->error(\sprintf('turbo-ext/%s has SHA-256 %s, but %s pins %s. Nothing was installed.', $path, $actual, TurboManifest::PATH, $digest));

                return 1;
            }

            $downloads[$path] = $bytes;
        }

        // Staged beside the binary: a running PHPStan has the old files mapped, and only a
        // same-filesystem rename replaces one without rewriting the pages under that process.
        $staging = TemporaryDirectory::besides($turboDir . $binary, '.phpqa-turbo');

        try {
            foreach ($downloads as $path => $bytes) {
                $staged = $staging->path . '/' . basename($path);
                \Safe\file_put_contents($staged, $bytes);
                // A fresh file's mode follows the umask; a shared library nobody else may rewrite is 0644.
                \Safe\chmod($staged, 0o644);
                \Safe\rename($staged, $turboDir . $path);
            }

            \Safe\file_put_contents($turboDir . $binary . self::STAMP_SUFFIX, TurboInstallDecider::stamp($files) . "\n");
        } catch (Throwable $throwable) {
            $staging->remove();
            $this->error(\sprintf('could not install the Turbo files: %s', $throwable->getMessage()));

            return 1;
        }

        $staging->remove();

        return 0;
    }

    private function error(string $message): void
    {
        $this->errors->writeln('ERROR: ' . $message);
    }
}
