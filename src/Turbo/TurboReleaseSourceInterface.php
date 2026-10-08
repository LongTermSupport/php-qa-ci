<?php

declare(strict_types=1);

namespace LTS\PHPQA\Turbo;

/**
 * Where the Turbo installer reads a phpstan/turbo-ext release from: the release record, for the
 * digests the manifest pins, and one asset's bytes. A null is a failed request, which the
 * installer decides about; the source only reports it.
 *
 * @internal
 */
interface TurboReleaseSourceInterface
{
    /** The GitHub release API response for the tag, or null when it could not be read. */
    public function release(string $version): ?string;

    /** The asset's bytes, or null when it could not be downloaded. */
    public function asset(string $version, string $asset): ?string;
}
