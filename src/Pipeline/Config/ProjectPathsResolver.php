<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config;

use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;
use LTS\PHPQA\Pipeline\Config\Exception\ProjectLayoutException;

/**
 * Discovers the project's directories once. A project must have a `src/`
 * and a `tests/` (or `test/`) directory; the bin directory is Composer's
 * `config.bin-dir` (default vendor/bin), read from composer.json rather than
 * by spawning composer.
 *
 * @internal
 */
final readonly class ProjectPathsResolver
{
    public function resolve(string $projectRoot, string $libraryRoot): ProjectPathsDto
    {
        $projectRoot = rtrim($projectRoot, '/');
        $libraryRoot = rtrim($libraryRoot, '/');

        $srcDir = $projectRoot . '/src';
        if (!is_dir($srcDir)) {
            throw ProjectLayoutException::missingDirectory('src', $projectRoot);
        }

        $testsDir = $this->testsDir($projectRoot);
        $varDir   = $projectRoot . '/var/qa';

        return new ProjectPathsDto(
            projectRoot: $projectRoot,
            libraryRoot: $libraryRoot,
            binDir: $projectRoot . '/' . $this->composerBinDir($projectRoot),
            srcDir: $srcDir,
            testsDir: $testsDir,
            projectConfigDir: $projectRoot . '/qaConfig',
            varDir: $varDir,
            cacheDir: $varDir . '/cache',
            pharDir: $libraryRoot . '/vendor-phar',
            configDefaultsDir: $libraryRoot . '/configDefaults',
        );
    }

    private function testsDir(string $projectRoot): string
    {
        foreach (['tests', 'test'] as $candidate) {
            if (is_dir($projectRoot . '/' . $candidate)) {
                return $projectRoot . '/' . $candidate;
            }
        }

        throw ProjectLayoutException::missingDirectory('tests', $projectRoot);
    }

    private function composerBinDir(string $projectRoot): string
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

        return 'vendor/bin';
    }
}
