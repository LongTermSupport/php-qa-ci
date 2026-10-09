<?php

declare(strict_types=1);

namespace LTS\PHPQA\Turbo;

use LTS\PHPQA\Turbo\Dto\TurboDecisionDto;

/**
 * Whether an install should leave this host's Turbo binary and core alone, fetch them, or report
 * that it cannot. Pure: it is handed the manifest, the phar's version, the host and the stamp of what is
 * installed, and decides nothing else.
 *
 * A manifest written for another phar version is broken rather than fetched from: a binary from
 * another release does not load, and PHPStan then runs without Turbo without saying so.
 *
 * @internal
 */
final readonly class TurboInstallDecider
{
    /**
     * What an install records beside the binary, so the next install knows which files it came from.
     *
     * @param array<string, string> $files path under `turbo-ext/` => SHA-256
     */
    public static function stamp(array $files): string
    {
        $parts = [];
        foreach ($files as $path => $digest) {
            $parts[] = $path . ' ' . $digest;
        }

        return implode(' ', $parts);
    }

    public function decide(?TurboManifest $manifest, ?string $pharVersion, TurboPlatform $platform, ?string $installedStamp): TurboDecisionDto
    {
        if (!$manifest instanceof TurboManifest) {
            return TurboDecisionDto::broken(\sprintf('%s is missing, but it is committed to the repository', TurboManifest::PATH));
        }

        if (null === $pharVersion) {
            return TurboDecisionDto::broken('phive.xml records no installed phpstan phar, so there is no version to match a Turbo binary to');
        }

        if ($manifest->phpstanVersion !== $pharVersion) {
            return TurboDecisionDto::broken(\sprintf(
                '%s is for PHPStan %s but the shipped phpstan.phar is %s; a binary from another release does not load. Run scripts/tool-install.bash update',
                TurboManifest::PATH,
                $manifest->phpstanVersion,
                $pharVersion,
            ));
        }

        $core   = $platform->corePath();
        $binary = $platform->binaryPath();
        if (null === $core || null === $binary) {
            return TurboDecisionDto::unsupported(\sprintf('PHPStan Turbo is not built for %s; PHPStan runs without it.', $platform->describe()));
        }

        // The core goes first: the phar loads a binary only when its core is beside it.
        $files = [];
        foreach ([$core, $binary] as $path) {
            $digest = $manifest->digestFor($path);
            if (null === $digest) {
                return TurboDecisionDto::unsupported(\sprintf('phpstan/phpstan %s publishes no turbo-ext/%s; PHPStan runs without Turbo on %s.', $pharVersion, $path, $platform->describe()));
            }

            $files[$path] = $digest;
        }

        if (self::stamp($files) === $installedStamp) {
            return TurboDecisionDto::ready(\sprintf('PHPStan Turbo %s present for %s.', $pharVersion, $platform->describe()));
        }

        return TurboDecisionDto::fetch($files, \sprintf('Installing PHPStan Turbo %s for %s...', $pharVersion, $platform->describe()));
    }
}
