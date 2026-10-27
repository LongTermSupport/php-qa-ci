<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Phpunit;

use LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto;

/**
 * The argv the PHPUnit lane hands to phpunit (or paratest), assembled in the
 * order the historic Bash fragment used: the paratest delegate, the config,
 * the always-on strictness flags, the PHPUnit 10+ display flags, then the
 * MODE flags and finally the explicit paths of a `-p` run. The paratest
 * delegate (`--phpunit`) is only passed to a paratest that accepts it, and
 * paratest is given at most one path.
 *
 * Modes, in precedence order:
 *   - iterative: order by defects, stop at the first defect, no coverage, time limits
 *   - no coverage: no coverage, time limits
 *   - coverage in CI: nothing extra (a full run, no time limits)
 *   - coverage interactively: time limits
 *
 * @internal
 */
final readonly class PhpunitArguments
{
    private const string ENFORCE_TIME_LIMIT = '--enforce-time-limit';

    /**
     * @param int                       $majorVersion  the probed PHPUnit major; 10+ gets the --display-* flags
     * @param ParatestCapabilities|null $paratest      what the installed paratest accepts, or null when not using paratest
     * @param string                    $phpunitBinary the project's phpunit; handed to a paratest that selects it with `--phpunit`
     * @param string                    ...$paths      the paths of a `-p` run; none for a full-suite run
     *
     * @return list<string>
     *
     * @throws ParatestIncompatibleException when paratest cannot be given these arguments
     */
    public function build(
        PhpUnitOptionsDto $options,
        bool $ci,
        int $majorVersion,
        string $configPath,
        string $junitLogFile,
        ?ParatestCapabilities $paratest,
        string $phpunitBinary,
        string ...$paths,
    ): array {
        $args = [];
        if (null !== $paratest) {
            if (!$paratest->isUsable()) {
                throw ParatestIncompatibleException::unusableOptions();
            }

            if (\count($paths) > 1) {
                throw ParatestIncompatibleException::severalPaths(\count($paths));
            }

            if ($paratest->acceptsPhpunitOption) {
                $args[] = '--phpunit';
                $args[] = $phpunitBinary;
            }
        }

        $args[] = '-c';
        $args[] = $configPath;

        $args[] = '--strict-global-state';
        $args[] = '--fail-on-risky';
        $args[] = '--fail-on-warning';
        $args[] = '--log-junit';
        $args[] = $junitLogFile;

        if ($majorVersion >= 10) {
            $args = [
                ...$args,
                '--colors=always',
                '--display-incomplete',
                '--display-skipped',
                '--display-deprecations',
                '--display-phpunit-deprecations',
                '--display-errors',
                '--display-notices',
                '--display-phpunit-notices',
                '--display-warnings',
            ];
        }

        return [...$args, ...$this->modeFlags($options, $ci), ...array_values($paths)];
    }

    /** @return list<string> */
    private function modeFlags(PhpUnitOptionsDto $options, bool $ci): array
    {
        if ($options->iterativeMode) {
            return [
                '--order-by=depends,defects',
                '--stop-on-failure',
                '--stop-on-error',
                '--stop-on-defect',
                '--stop-on-warning',
                '--no-coverage',
                self::ENFORCE_TIME_LIMIT,
            ];
        }

        if (!$options->coverage) {
            return ['--no-coverage', self::ENFORCE_TIME_LIMIT];
        }

        if ($ci) {
            return [];
        }

        return [self::ENFORCE_TIME_LIMIT];
    }
}
