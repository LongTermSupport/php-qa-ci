<?php

declare(strict_types=1);

namespace LTS\PHPQA\PhpstanDocs;

/**
 * PHPStan's error-identifier catalogue, carried offline under
 * `vendor-docs/phpstan/`: one page per identifier, verbatim from
 * phpstan/phpstan's `website/errors/`, phpstan/phpstan's licence, and a
 * version file whose first line is the shipped phpstan.phar the pages were
 * taken alongside and whose second is the upstream commit they came from. The
 * same pages phpstan.org renders, covering PHPStan and its first-party
 * extensions.
 *
 * @internal
 */
final readonly class PhpstanDocsCatalogue
{
    /** The catalogue, relative to the library root. */
    public const string DIRECTORY = 'vendor-docs/phpstan';

    /** One `<identifier>.md` per identifier. */
    public const string PAGES = self::DIRECTORY . '/errors';

    /** The phar version the pages were taken alongside, then their upstream commit. */
    public const string VERSION_FILE = self::DIRECTORY . '/version';

    /** phpstan/phpstan's licence, which the pages are distributed under. */
    public const string LICENSE_FILE = self::DIRECTORY . '/LICENSE';

    /** An identifier as PHPStan prints one. Anything else cannot name a page, so it never names a path. */
    public const string IDENTIFIER_PATTERN = '/^[A-Za-z][A-Za-z0-9]*(?:\.[A-Za-z0-9]+)+$/';

    /** The shipped phpstan.phar's resolved version, as PHIVE records it. */
    private const string INSTALLED_PATTERN = '/<phar\s+name="phpstan"[^>]*\sinstalled="([^"]+)"/';

    public function __construct(private string $libraryRoot)
    {
    }

    public function pagePath(string $identifier): ?string
    {
        if (1 !== \Safe\preg_match(self::IDENTIFIER_PATTERN, $identifier)) {
            return null;
        }

        $page = $this->libraryRoot . '/' . self::PAGES . '/' . $identifier . '.md';

        return is_file($page) ? \Safe\realpath($page) : null;
    }

    /** The phpstan.phar version the carried pages were taken alongside, or null when none are carried. */
    public function carriedVersion(): ?string
    {
        return $this->versionLine(0);
    }

    /** Where the carried pages came from, as `phpstan/phpstan <branch>@<commit>`, or null when unrecorded. */
    public function carriedSource(): ?string
    {
        return $this->versionLine(1);
    }

    /** The version of the shipped phpstan.phar, or null when phive.xml pins none. */
    public function installedPhpstanVersion(): ?string
    {
        $phive = $this->libraryRoot . '/phive.xml';
        if (!is_file($phive) || 1 !== \Safe\preg_match(self::INSTALLED_PATTERN, \Safe\file_get_contents($phive), $match) || !isset($match[1])) {
            return null;
        }

        return $match[1];
    }

    private function versionLine(int $index): ?string
    {
        $file = $this->libraryRoot . '/' . self::VERSION_FILE;
        if (!is_file($file)) {
            return null;
        }

        $lines = explode("\n", \Safe\file_get_contents($file));
        if (!isset($lines[$index])) {
            return null;
        }

        $line = trim($lines[$index]);

        return '' === $line ? null : $line;
    }
}
