<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;

/**
 * The actions-qa-ci release php-qa-ci pins, where its bundle is installed, which hosts it runs on,
 * and how the version it reports is read. One place, because the actionsQa lane and
 * bin/actions-qa-install must agree on every answer.
 *
 * Nothing of the bundle is committed: it is tens of megabytes of binaries per CPU. What php-qa-ci
 * carries is the release version and the SHA-256 of that release's install.sh. The installer
 * refuses an install.sh with any other digest before running it, and install.sh in turn refuses a
 * bundle whose digest differs from the one written into it, so every step from this file to the
 * binaries that run is checked.
 *
 * @api
 */
final readonly class ActionsQaBinary
{
    /** The lane's stable identifier, printed on failure. */
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.actionsQa';

    /** The pinned release, as the bundle's `version --json` reports it under `toolchain`. */
    public const string VERSION = '0.2.0';

    /** The SHA-256 of the pinned release's install.sh, checked before it is run. */
    public const string INSTALL_SCRIPT_SHA256 = '90edd9648e0b083f4805ffd758c122b7b9105c59eaf90e21a8813c452efe03e6';

    /** Library-root-relative: the install prefix, which the package's .gitignore excludes. */
    public const string INSTALL_DIR = 'vendor-bin/actions-qa-ci';

    /** The release's name for the x86_64 Linux bundle. */
    public const string PLATFORM_AMD64 = 'linux_amd64';

    /** The release's name for the aarch64 Linux bundle. */
    public const string PLATFORM_ARM64 = 'linux_arm64';

    /** Where each release publishes its install.sh; the version fills the tag. */
    private const string INSTALL_SCRIPT_URL = 'https://github.com/LongTermSupport/actions-qa-ci/releases/download/v%s/install.sh';

    /** Install-prefix-relative: the program the lane runs. */
    private const string PROGRAM = 'bin/actions-qa';

    /** Library-root-relative: the entry point that fetches the pinned bundle. */
    private const string INSTALLER = 'bin/actions-qa-install';

    /** PHP_OS_FAMILY on the only OS the release publishes bundles for. */
    private const string LINUX = 'Linux';

    /** The key of `version --json` that carries the release version. */
    private const string TOOLCHAIN_KEY = 'toolchain';

    /** CPU names as `php_uname('m')` reports them, mapped to the release's platform names. */
    private const array MACHINES = [
        'x86_64'  => self::PLATFORM_AMD64,
        'amd64'   => self::PLATFORM_AMD64,
        'aarch64' => self::PLATFORM_ARM64,
        'arm64'   => self::PLATFORM_ARM64,
    ];

    /** The install prefix handed to install.sh. */
    public static function installDir(string $libraryRoot): string
    {
        return $libraryRoot . '/' . self::INSTALL_DIR;
    }

    /** The installed `actions-qa` program. */
    public static function path(string $libraryRoot): string
    {
        return self::installDir($libraryRoot) . '/' . self::PROGRAM;
    }

    /** The pinned release's install.sh. */
    public static function installScriptUrl(): string
    {
        return \sprintf(self::INSTALL_SCRIPT_URL, self::VERSION);
    }

    /** The one command that puts the pinned bundle in place, printed whenever it is missing or wrong. */
    public static function installCommand(string $libraryRoot): string
    {
        return 'php ' . $libraryRoot . '/' . self::INSTALLER;
    }

    /**
     * The release's platform name for an OS and CPU, or null when it publishes no bundle for it.
     *
     * @param string $os      PHP_OS_FAMILY
     * @param string $machine php_uname('m')
     */
    public static function platformFor(string $os, string $machine): ?string
    {
        if (self::LINUX !== $os) {
            return null;
        }

        return self::MACHINES[strtolower($machine)] ?? null;
    }

    /** The release version a `version --json` report names, or null when it names none. */
    public static function reportedVersion(string $report): ?string
    {
        if (!json_validate($report)) {
            return null;
        }

        $decoded = \Safe\json_decode($report, true);
        if (!\is_array($decoded)) {
            return null;
        }

        $version = $decoded[self::TOOLCHAIN_KEY] ?? null;

        return \is_string($version) && '' !== $version ? $version : null;
    }
}
