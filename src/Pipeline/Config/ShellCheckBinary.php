<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use Safe\Exceptions\FilesystemException;

/**
 * Where the vendored ShellCheck lives, which release it is pinned to, and how
 * to talk about both when something is wrong. One place, because the lane and
 * scripts/tool-install.bash must agree on the answer: a check whose version
 * depends on the host is not the same check, which is the whole reason the
 * binary is committed rather than expected on PATH.
 *
 * The pin is a file rather than a constant so the maintainer updater can
 * rewrite it from Bash without rewriting PHP source.
 *
 * @api
 */
final readonly class ShellCheckBinary
{
    /** The lane's stable identifier, printed on failure. */
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.shellCheck';

    /** Library-root-relative: the committed static linux.x86_64 build. */
    public const string RELATIVE_PATH = 'vendor-bin/shellcheck';

    /** Library-root-relative: the release tag the committed binary was taken from. */
    public const string VERSION_FILE = 'vendor-bin/shellcheck.version';

    /** The severity the CI job asserted; matching today's behaviour is the point of the lane. */
    public const string SEVERITY = 'warning';

    /** The only build vendored: a static binary, so it needs nothing from the host. */
    public const string ARCHITECTURE = 'linux.x86_64';

    /** The line of the `--version` banner that carries the number. */
    private const string VERSION_PREFIX = 'version:';

    public static function path(string $libraryRoot): string
    {
        return $libraryRoot . '/' . self::RELATIVE_PATH;
    }

    /** The pinned release tag (`v0.11.0`), or null when the checkout has no pin to compare against. */
    public static function pinnedVersion(string $libraryRoot): ?string
    {
        $file = $libraryRoot . '/' . self::VERSION_FILE;
        if (!is_file($file)) {
            return null;
        }

        $pinned = trim(\Safe\file_get_contents($file));

        return '' === $pinned ? null : $pinned;
    }

    /**
     * The release tag a `shellcheck --version` banner describes. The banner
     * prints the bare number and the pin is the tag, so one of the two has to
     * be normalised before they can be compared.
     */
    public static function reportedVersion(string $banner): ?string
    {
        foreach (explode("\n", $banner) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, self::VERSION_PREFIX)) {
                continue;
            }

            $version = trim(substr($line, \strlen(self::VERSION_PREFIX)));
            if ('' !== $version) {
                return 'v' . $version;
            }
        }

        return null;
    }

    /**
     * Make the binary executable if it is not already; null when it now is,
     * otherwise why it could not be. Unlike the PHARs, which the pipeline
     * hands to `php`, this artefact is exec'd directly — and a consumer
     * installed from a Composer dist zip gets it without the mode bit,
     * because ZipArchive::extractTo does not restore permissions.
     */
    public static function ensureExecutable(string $path): ?string
    {
        if (is_executable($path)) {
            return null;
        }

        if (!is_file($path)) {
            return 'the binary is missing';
        }

        try {
            \Safe\chmod($path, 0o755);
        } catch (FilesystemException $filesystemException) {
            return 'the binary is not executable and could not be made so: ' . $filesystemException->getMessage();
        }

        return null;
    }

    /** The command that puts the pinned build back, printed when it is missing or wrong. */
    public static function fetchCommand(string $libraryRoot, string $version): string
    {
        $binary = self::path($libraryRoot);

        // mkdir first: the whole vendor-bin/ directory is what goes missing when
        // an install route drops it, and without this the redirect fails with a
        // "No such file or directory" that says nothing about the cause.
        return \sprintf(
            'mkdir -p %4$s'
            . ' && curl -fsSL https://github.com/koalaman/shellcheck/releases/download/%1$s/shellcheck-%1$s.%2$s.tar.xz'
            . ' | tar -xJO shellcheck-%1$s/shellcheck > %3$s'
            . ' && chmod 0755 %3$s',
            $version,
            self::ARCHITECTURE,
            $binary,
            \dirname($binary),
        );
    }
}
