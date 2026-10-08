<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\Turbo\TurboReleaseSourceInterface;

/**
 * Serves a fixed release response and fixed asset bytes, counting what was asked for, so the
 * Turbo installer's tests run without the network.
 */
final class FakeTurboReleaseSource implements TurboReleaseSourceInterface
{
    /** How many release lookups were made. */
    public int $releaseLookups = 0;

    /** How many assets were downloaded. */
    public int $downloads = 0;

    /** @param array<string, string> $assets asset name => bytes */
    public function __construct(
        private readonly ?string $release,
        private readonly array $assets,
    ) {
    }

    public function release(string $version): ?string
    {
        ++$this->releaseLookups;

        return $this->release;
    }

    public function asset(string $version, string $asset): ?string
    {
        ++$this->downloads;

        return $this->assets[$asset] ?? null;
    }
}
