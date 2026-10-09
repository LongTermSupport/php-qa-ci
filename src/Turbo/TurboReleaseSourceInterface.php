<?php

declare(strict_types=1);

namespace LTS\PHPQA\Turbo;

/**
 * Where the Turbo installer reads PHPStan's Turbo files from: the phpstan/phpstan repository at
 * the tag of the shipped phar, whose `turbo-ext/` holds the binaries and cores the phar loads. The
 * tree lists them for the digests the manifest pins, and one file's bytes is what gets installed. A
 * null is a failed request, which the installer decides about; the source only reports it.
 *
 * @internal
 */
interface TurboReleaseSourceInterface
{
    /** The GitHub git-tree API response (recursive) for the tag, or null when it could not be read. */
    public function tree(string $version): ?string;

    /** The bytes of `turbo-ext/<path>` at the tag, or null when it could not be downloaded. */
    public function file(string $version, string $path): ?string;
}
