<?php

declare(strict_types=1);

namespace LTS\PHPQA\Turbo;

/**
 * Whether the Turbo binary and core installed under `vendor-phar/turbo-ext/` are the files the
 * committed `vendor-phar/turbo-ext.json` pins for this host. The directory is untracked and
 * installed per checkout, so a PHPStan update leaves a checkout holding the previous release's
 * binary: PHPStan then prints a NOTE and runs without Turbo, and Rector's workers, which load
 * Turbo only from that directory, run without it silently. The install's own stamp cannot show
 * this, since it records what the install fetched, not what the manifest pins now.
 *
 * Silent where nothing is expected: a host the manifest pins no build for, or no manifest at all
 * (a broken or absent one is another check's finding).
 *
 * @internal
 */
final readonly class TurboFreshness
{
    /** What fixes a stale or missing binary. */
    private const string REFRESH_COMMAND = 'php bin/turbo-install';

    /** null asks the running PHP, the one the binary is fetched for. */
    public function __construct(private ?TurboPlatform $platform = null)
    {
    }

    /**
     * @param string $pharDir the directory holding turbo-ext.json and the turbo-ext/ directory
     *
     * @return string|null one plain line saying what is wrong and how to fix it, null when nothing is
     */
    public function problem(string $pharDir): ?string
    {
        $manifestPath = $pharDir . '/' . basename(TurboManifest::PATH);
        if (!is_file($manifestPath)) {
            return null;
        }

        $manifest = TurboManifest::fromJson(\Safe\file_get_contents($manifestPath));
        $platform = $this->platform ?? TurboPlatform::fromRuntime();

        /** @var array<array-key, string> $pins path under turbo-ext/ => pinned SHA-256 */
        $pins = [];
        foreach ([$platform->binaryPath(), $platform->corePath()] as $path) {
            $digest = null === $path ? null : $manifest->digestFor($path);
            if (null === $path || null === $digest) {
                return null;
            }

            $pins[$path] = $digest;
        }

        foreach ($pins as $key => $pinned) {
            $path      = (string)$key;
            $installed = $pharDir . '/' . basename(TurboInstaller::TURBO_DIR) . '/' . $path;
            if (!is_file($installed)) {
                return $this->line($path, 'is missing.');
            }

            if (!hash_equals($pinned, \Safe\hash_file('sha256', $installed))) {
                return $this->line($path, 'is stale, its SHA-256 differs from the one ' . TurboManifest::PATH . ' pins.');
            }
        }

        return null;
    }

    private function line(string $path, string $what): string
    {
        return \sprintf(
            'PHPStan Turbo: %s/%s %s PHPStan and Rector run without Turbo, and slower. Refresh it with: %s',
            TurboInstaller::TURBO_DIR,
            $path,
            $what,
            self::REFRESH_COMMAND,
        );
    }
}
