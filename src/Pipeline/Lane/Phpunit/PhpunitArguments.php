<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Phpunit;

use LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto;

/**
 * The argv the PHPUnit lane hands to phpunit (or paratest), assembled in the
 * order the historic Bash fragment used: the config, the always-on strictness flags, the PHPUnit 10+ display flags, then the
 * MODE flags and finally the explicit paths of a `-p` run. Under paratest every
 * flag of the argv must be one its `--help` declares, and it is given at most
 * one path.
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
     * @param int                       $majorVersion the probed PHPUnit major; 10+ gets the --display-* flags
     * @param ParatestCapabilities|null $paratest     what the installed paratest accepts, or null when not using paratest
     * @param string                    ...$paths     the paths of a `-p` run; none for a full-suite run
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
        string ...$paths,
    ): array {
        $args   = [];
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

        $args = [...$args, ...$this->modeFlags($options, $ci)];
        if ($paratest instanceof ParatestCapabilities) {
            $this->assertAccepted($paratest, \count($paths), ...$args);
        }

        return [...$args, ...array_values($paths)];
    }

    /**
     * Every flag in the argv is checked against what paratest declares; the
     * flags are read from the argv itself, so no list can drift from it.
     *
     * @throws ParatestIncompatibleException
     */
    private function assertAccepted(ParatestCapabilities $paratest, int $pathCount, string ...$args): void
    {
        $flags = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '-')) {
                $flags[] = explode('=', $arg, 2)[0];
            }
        }

        $lacking = $paratest->lacking(...$flags);
        if ([] !== $lacking) {
            throw ParatestIncompatibleException::missingOptions($paratest->label(), ...$lacking);
        }

        if ($pathCount > 1) {
            throw ParatestIncompatibleException::severalPaths($pathCount);
        }
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
