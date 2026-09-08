<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Infection;

use LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto;

/**
 * The argv the Infection lane hands to infection.phar. Two lanes:
 *
 *   - FULL: the whole codebase against the SSoT floors (--min-msi and
 *     --min-covered-msi) with the verbose console report.
 *   - DIFF: only the changed files, passed as positional paths LAST, against
 *     the single covered-MSI diff floor and Infection's default verbosity (the
 *     file loggers in infection.json stay authoritative).
 *
 * Coverage is always reused (--skip-initial-tests): the lane guarantees it
 * exists before Infection runs, so the suite is never executed a second time.
 *
 * @internal
 */
final readonly class InfectionArguments
{
    /** @return list<string> */
    public function full(InfectionOptionsDto $options, string $coverageDir, string $configPath): array
    {
        return [
            ...$this->common($options, $coverageDir, $configPath),
            '--min-msi=' . $options->minMsi,
            '--min-covered-msi=' . $options->minCoveredMsi,
            '--log-verbosity=all',
        ];
    }

    /**
     * @param string ...$positionalPaths absolute changed-file paths; must be non-empty
     *
     * @return list<string>
     */
    public function diff(InfectionOptionsDto $options, string $coverageDir, string $configPath, string ...$positionalPaths): array
    {
        return [
            ...$this->common($options, $coverageDir, $configPath),
            '--min-covered-msi=' . $options->diffCoveredMsi,
            ...array_values($positionalPaths),
        ];
    }

    /** @return list<string> */
    private function common(InfectionOptionsDto $options, string $coverageDir, string $configPath): array
    {
        $args = [];
        if ($options->onlyCovered) {
            $args[] = '--only-covered';
        }

        return [
            ...$args,
            '--coverage=' . $coverageDir,
            '--skip-initial-tests',
            '--threads=' . $options->threads,
            '--configuration=' . $configPath,
        ];
    }
}
