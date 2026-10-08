<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Runner;

use LTS\PHPQA\PhpstanDocs\PhpstanDocsCatalogue;
use LTS\PHPQA\Pipeline\Runner\Exception\MissingPharException;
use LTS\PHPQA\Turbo\TurboManifest;

/**
 * Every PHAR the pipeline runs is committed under the library's vendor-phar/:
 * the PHIVE-managed ones named in phive.xml, plus one self-built <tool>.phar
 * per build/<tool>/ manifest (scripts/build-phar.bash). A missing one means a
 * broken install, not something to fetch at run time. So does a Turbo manifest
 * (vendor-phar/turbo-ext.json) that is missing or names another PHPStan version.
 *
 * @internal
 */
final readonly class PharToolsVerifier
{
    private const string BUILD_DIR = 'build';

    public function verify(string $libraryRoot): void
    {
        $phiveXml = $libraryRoot . '/phive.xml';
        if (!is_file($phiveXml)) {
            throw MissingPharException::noPhiveXml($phiveXml);
        }

        $missing  = [];
        $required = [...$this->pharFiles(\Safe\file_get_contents($phiveXml)), ...$this->builtPharFiles($libraryRoot)];
        foreach ($required as $relative) {
            if (!is_file($libraryRoot . '/' . $relative)) {
                $missing[] = basename($relative, '.phar');
            }
        }

        if ([] !== $missing) {
            throw MissingPharException::missing($libraryRoot . '/vendor-phar', ...$missing);
        }

        $this->verifyTurboManifest($libraryRoot);
    }

    /**
     * The Turbo binaries are fetched against vendor-phar/turbo-ext.json, and one built for another
     * PHPStan release does not load, so the manifest must name the phar's own version.
     */
    private function verifyTurboManifest(string $libraryRoot): void
    {
        $pharVersion = new PhpstanDocsCatalogue($libraryRoot)->installedPhpstanVersion();
        if (null === $pharVersion) {
            return;
        }

        $manifest = $libraryRoot . '/' . TurboManifest::PATH;
        if (!is_file($manifest)) {
            throw MissingPharException::turboManifestMissing($manifest);
        }

        $manifestVersion = TurboManifest::fromJson(\Safe\file_get_contents($manifest))->phpstanVersion;
        if ($manifestVersion !== $pharVersion) {
            throw MissingPharException::turboManifestMismatch($manifest, $manifestVersion, $pharVersion);
        }
    }

    /**
     * The `location` of every phar entry, relative to the library root
     * (phive.xml writes them as ./vendor-phar/<file>.phar).
     *
     * @return list<string>
     */
    private function pharFiles(string $phiveXml): array
    {
        \Safe\preg_match_all('/<phar\s[^>]*\blocation="\.?\/?([^"]+)"/', $phiveXml, $matches);
        $locations = $matches[1] ?? [];

        return \is_array($locations) ? array_values(array_filter($locations, is_string(...))) : [];
    }

    /**
     * vendor-phar/<tool>.phar for every build/<tool>/composer.json manifest.
     *
     * @return list<string>
     */
    private function builtPharFiles(string $libraryRoot): array
    {
        $manifests = \Safe\glob($libraryRoot . '/' . self::BUILD_DIR . '/*/composer.json');

        return array_map(
            static fn (string $manifest): string => 'vendor-phar/' . basename(\dirname($manifest)) . '.phar',
            array_values(array_filter($manifests, is_string(...))),
        );
    }
}
