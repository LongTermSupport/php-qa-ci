<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config;

use LTS\PHPQA\Pipeline\Config\Exception\ProjectLayoutException;

/**
 * The project's Composer `config.bin-dir`, relative to the project root
 * (default `vendor/bin`), read from composer.json rather than by spawning
 * composer.
 *
 * @internal
 */
final readonly class ComposerBinDirReader
{
    private const string DEFAULT_BIN_DIR = 'vendor/bin';

    public function read(string $projectRoot): string
    {
        $composerJson = $projectRoot . '/composer.json';
        if (!is_file($composerJson)) {
            throw ProjectLayoutException::missingComposerJson($projectRoot);
        }

        $composer = \Safe\json_decode(\Safe\file_get_contents($composerJson), true);
        if (!\is_array($composer)) {
            throw ProjectLayoutException::missingComposerJson($projectRoot);
        }

        $config = $composer['config'] ?? null;
        $binDir = \is_array($config) ? ($config['bin-dir'] ?? null) : null;
        if (\is_string($binDir) && '' !== $binDir) {
            return trim($binDir, '/');
        }

        return self::DEFAULT_BIN_DIR;
    }
}
