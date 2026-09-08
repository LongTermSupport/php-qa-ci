<?php

declare(strict_types=1);

namespace LTS\PHPQA\VersionPins;

/**
 * Pure decision: every thecodingmachine/safe entry in composer-require-checker's
 * `scan-files` must name the generated file safe actually loads on the PHP
 * version QA runs under.
 *
 * safe ships one dispatcher per extension (generated/<ext>.php) that requires
 * a version-specific file (generated/<x.y>/<ext>.php) chosen by PHP_VERSION.
 * The version directory is NOT simply the running PHP version: 8.5 loads
 * 8.4/array.php but 8.2/exec.php. A scan-files entry naming any other
 * directory whitelists a function set that is not the one in force, so a
 * \Safe\* call can be reported as undeclared — or an undeclared one can slip
 * through — with nothing pointing at the stale entry.
 *
 * @internal
 */
final readonly class SafeScanFilesDetector
{
    private const string ENTRY_PATTERN = '#^(.*?thecodingmachine/safe/generated/)(\d+\.\d+)/([A-Za-z0-9_]+\.php)$#';

    /**
     * @param list<string> $scanFiles     the `scan-files` entries, project-relative
     * @param string       $projectRoot   directory the entries are relative to
     * @param string       $phpMajorMinor the PHP version QA runs under, e.g. "8.5"
     *
     * @return list<string> one message per safe entry that is not the file
     *                      safe loads on that PHP version; empty when every
     *                      safe entry is the loaded one (non-safe entries and
     *                      entries whose dispatcher is absent are not judged)
     */
    public function check(array $scanFiles, string $projectRoot, string $phpMajorMinor): array
    {
        $problems = [];
        foreach ($scanFiles as $entry) {
            if (1 !== \Safe\preg_match(self::ENTRY_PATTERN, $entry, $matches) || !isset($matches[1], $matches[2], $matches[3])) {
                continue;
            }

            [, $generatedDir, $listedVersion, $file] = $matches;

            $dispatcher = $projectRoot . '/' . $generatedDir . $file;
            if (!is_file($dispatcher)) {
                continue;
            }

            $loadedVersion = $this->loadedVersionDir(\Safe\file_get_contents($dispatcher), $phpMajorMinor);
            if (null === $loadedVersion) {
                $problems[] = \sprintf(
                    '"%s": safe has no %s branch for PHP %s, so this file is never loaded on the PHP QA runs under; remove the entry',
                    $entry,
                    $file,
                    $phpMajorMinor,
                );

                continue;
            }

            if ($loadedVersion !== $listedVersion) {
                $problems[] = \sprintf(
                    '"%s": on PHP %s safe loads generated/%s/%s, not the %s one; replace the entry with "%s%s/%s"',
                    $entry,
                    $phpMajorMinor,
                    $loadedVersion,
                    $file,
                    $listedVersion,
                    $generatedDir,
                    $loadedVersion,
                    $file,
                );
            }
        }

        return $problems;
    }

    /**
     * Reads the version directory a safe dispatcher requires for a PHP
     * major.minor, from the dispatcher's own source:
     *
     *   if (str_starts_with(PHP_VERSION, "8.5.")) {
     *       require_once __DIR__ . '/8.4/array.php';
     *   }
     */
    public function loadedVersionDir(string $dispatcherSource, string $phpMajorMinor): ?string
    {
        $pattern = '#PHP_VERSION,\s*"' . preg_quote($phpMajorMinor, '#') . '\."\)\)\s*\{\s*require_once\s+__DIR__\s*\.\s*\'/(\d+\.\d+)/#';
        if (1 !== \Safe\preg_match($pattern, $dispatcherSource, $matches) || !isset($matches[1])) {
            return null;
        }

        return $matches[1];
    }
}
