<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config;

/**
 * The extension that collects code coverage for the PHPUnit lane, and so for
 * Infection, which mutates against that coverage.
 *
 * pcov is chosen when it is loaded and enabled (`pcov.enabled=1`), the test
 * PHPUnit's own driver selection makes, so a host that disables pcov in its
 * ini gets Xdebug. pcov only collects line coverage and is several times
 * faster than Xdebug at it. Xdebug is chosen when pcov is not available and
 * Xdebug is loaded; with neither there is no coverage and Infection skips.
 *
 * With both loaded, the pcov run switches Xdebug off (`XDEBUG_MODE=off`), so
 * Xdebug collects nothing alongside it.
 *
 * @api
 */
enum CoverageDriverEnum: string
{
    case Pcov   = 'pcov';
    case Xdebug = 'xdebug';
    case None   = 'none';

    private const string XDEBUG_MODE = 'XDEBUG_MODE';

    public static function select(bool $pcovEnabled, bool $xdebugLoaded): self
    {
        if ($pcovEnabled) {
            return self::Pcov;
        }

        return $xdebugLoaded ? self::Xdebug : self::None;
    }

    public function generatesCoverage(): bool
    {
        return self::None !== $this;
    }

    /** The extension's own name, for messages. */
    public function label(): string
    {
        return match ($this) {
            self::Pcov   => 'pcov',
            self::Xdebug => 'Xdebug',
            self::None   => 'none',
        };
    }

    /**
     * The environment a coverage run with this driver needs. Xdebug is only
     * ever in coverage mode when it is the driver.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        return [self::XDEBUG_MODE => self::Xdebug === $this ? 'coverage' : 'off'];
    }

    /**
     * The `-d` ini a coverage run with this driver needs. pcov instruments the
     * files under one directory: left to itself it picks the first of `src`,
     * `lib` or `app` it finds, which silently loses coverage of source kept
     * anywhere else, so it is given the project root, less the project's own
     * vendor directory (anchored to that root, so a project that is itself
     * installed under another project's vendor/ is still instrumented).
     *
     * @return array<string, string>
     */
    public function phpIni(string $projectRoot): array
    {
        if (self::Pcov !== $this) {
            return [];
        }

        return [
            'pcov.enabled'   => '1',
            'pcov.directory' => $projectRoot,
            'pcov.exclude'   => '~^' . preg_quote($projectRoot . '/vendor/', '~') . '~',
        ];
    }
}
