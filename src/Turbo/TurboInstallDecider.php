<?php

declare(strict_types=1);

namespace LTS\PHPQA\Turbo;

use LTS\PHPQA\Turbo\Dto\TurboDecisionDto;

/**
 * Whether an install should leave this host's Turbo binary alone, fetch it, or report that it
 * cannot. Pure: it is handed the manifest, the phar's version, the host and the stamp of what is
 * installed, and decides nothing else.
 *
 * A manifest written for another phar version is broken rather than fetched from: a binary from
 * another release does not load, and PHPStan then runs without Turbo without saying so.
 *
 * @internal
 */
final readonly class TurboInstallDecider
{
    /** What an install records beside the binary, so the next install knows where it came from. */
    public static function stamp(string $asset, string $digest): string
    {
        return $asset . ' ' . $digest;
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

        $asset = $platform->assetName($pharVersion);
        if (null === $asset) {
            return TurboDecisionDto::unsupported(\sprintf('PHPStan Turbo is not built for %s; PHPStan runs without it.', $platform->describe()));
        }

        $digest = $manifest->digestFor($asset);
        if (null === $digest) {
            return TurboDecisionDto::unsupported(\sprintf('phpstan/turbo-ext %s publishes no %s; PHPStan runs without Turbo on %s.', $pharVersion, $asset, $platform->describe()));
        }

        if (self::stamp($asset, $digest) === $installedStamp) {
            return TurboDecisionDto::ready(\sprintf('PHPStan Turbo %s present for %s.', $pharVersion, $platform->describe()));
        }

        return TurboDecisionDto::fetch($asset, $digest, \sprintf('Installing PHPStan Turbo %s for %s...', $pharVersion, $platform->describe()));
    }
}
