<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\Turbo\TurboReleaseSourceInterface;

/**
 * Serves a fixed repository tree and fixed file bytes, counting what was asked for, so the Turbo
 * installer's tests run without the network.
 */
final class FakeTurboReleaseSource implements TurboReleaseSourceInterface
{
    public int $treeLookups = 0;

    public int $downloads = 0;

    /** @param array<string, string> $files path under turbo-ext/ => bytes */
    public function __construct(
        private readonly ?string $tree,
        private readonly array $files,
    ) {
    }

    public function tree(string $version): ?string
    {
        ++$this->treeLookups;

        return $this->tree;
    }

    public function file(string $version, string $path): ?string
    {
        ++$this->downloads;

        return $this->files[$path] ?? null;
    }
}
