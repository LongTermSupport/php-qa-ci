<?php

declare(strict_types=1);

namespace LTS\PHPQA\Turbo;

use LTS\PHPQA\Filesystem\TemporaryDirectory;
use LTS\PHPQA\PhpstanDocs\PhpstanDocsCatalogue;
use PharData;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;
use UnexpectedValueException;

/**
 * Gives this host PHPStan's Turbo binary, on the composer events that already carry the PHAR
 * tooling. The binary is not committed: upstream builds one per OS, CPU, libc, PHP minor version
 * and thread safety, so each install fetches only the host's. What is committed is
 * vendor-phar/turbo-ext.json, the SHA-256 of every asset of the release matching the shipped
 * phpstan.phar, and a download whose digest differs is refused.
 *
 * The binary lands at `vendor-phar/turbo-ext/<platform>/`, where phpstan.phar looks, with a stamp
 * beside it naming the asset and digest it came from, so a phar update replaces it.
 *
 * An update regenerates the manifest from the release, and is the maintainer path: it is gated on
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

    /** Beside the binary: the stamp of the asset and digest it was installed from. */
    private const string STAMP_SUFFIX = '.source';

    /** The one file in every release zip. */
    private const string ZIP_ENTRY = 'phpstan_turbo.so';

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

        $relative = $this->platform->binaryPath();
        $binary   = null === $relative ? null : $libraryRoot . '/' . self::TURBO_DIR . '/' . $relative;
        $decision = new TurboInstallDecider()->decide($manifest, $pharVersion, $this->platform, $this->installedStamp($binary));

        return match ($decision->action) {
            TurboActionEnum::Ready       => $this->reportReady($decision->message, $mode),
            TurboActionEnum::Unsupported => $this->reportUnsupported($decision->message),
            TurboActionEnum::Broken      => $this->reportBroken($decision->message),
            TurboActionEnum::Fetch       => $this->fetch((string)$binary, (string)$pharVersion, (string)$decision->asset, (string)$decision->digest, $decision->message),
        };
    }

    /** False when the release contradicts the phar; a release that cannot be read keeps the manifest. */
    private function refreshManifest(string $manifestPath, string $pharVersion): bool
    {
        $json = $this->source->release($pharVersion);
        if (null === $json) {
            $this->errors->writeln(\sprintf('WARNING: could not read the phpstan/turbo-ext %s release; %s left as it is.', $pharVersion, TurboManifest::PATH));

            return true;
        }

        try {
            $manifest = TurboManifest::fromReleaseApi($json);
        } catch (UnexpectedValueException $unexpectedValueException) {
            $this->error($unexpectedValueException->getMessage() . '.');

            return false;
        }

        if ($manifest->phpstanVersion !== $pharVersion) {
            $this->error(\sprintf('asked phpstan/turbo-ext for %s but the release is %s; %s left as it is.', $pharVersion, $manifest->phpstanVersion, TurboManifest::PATH));

            return false;
        }

        $json = $manifest->toJson();
        if (is_file($manifestPath) && \Safe\file_get_contents($manifestPath) === $json) {
            return true;
        }

        \Safe\file_put_contents($manifestPath, $json);
        $this->output->writeln(\sprintf('IMPORTANT: %s now pins the PHPStan Turbo %s binaries. Commit %s.', TurboManifest::PATH, $pharVersion, TurboManifest::PATH));

        return true;
    }

    private function installedStamp(?string $binary): ?string
    {
        if (null === $binary || !is_file($binary) || !is_file($binary . self::STAMP_SUFFIX)) {
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

    private function fetch(string $binary, string $version, string $asset, string $digest, string $message): int
    {
        $this->output->writeln($message);

        $bytes = $this->source->asset($version, $asset);
        if (null === $bytes) {
            // Not fatal: PHPStan runs without Turbo, and the next install tries again.
            $this->errors->writeln(\sprintf('WARNING: could not download %s; PHPStan runs without Turbo until the next composer install.', $asset));

            return 0;
        }

        $actual = hash('sha256', $bytes);
        if (!hash_equals($digest, $actual)) {
            $this->error(\sprintf('%s has SHA-256 %s, but %s pins %s. Nothing was installed.', $asset, $actual, TurboManifest::PATH, $digest));

            return 1;
        }

        // Staged beside the binary: a running PHPStan has the old one mapped, and only a
        // same-filesystem rename replaces it without rewriting the pages under that process.
        $staging = TemporaryDirectory::besides($binary, '.phpqa-turbo');

        try {
            $archive = $staging->path . '/' . $asset;
            \Safe\file_put_contents($archive, $bytes);
            new PharData($archive)->extractTo($staging->path, self::ZIP_ENTRY, true);

            $extracted = $staging->path . '/' . self::ZIP_ENTRY;
            // The zip entry carries no usable mode, and the extracted file comes out world-writable.
            \Safe\chmod($extracted, 0o644);
            \Safe\rename($extracted, $binary);
            \Safe\file_put_contents($binary . self::STAMP_SUFFIX, TurboInstallDecider::stamp($asset, $digest) . "\n");
        } catch (Throwable $throwable) {
            $staging->remove();
            $this->error(\sprintf('could not install %s: %s', $asset, $throwable->getMessage()));

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
