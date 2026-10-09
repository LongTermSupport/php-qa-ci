<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Infection;

use LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto;

/**
 * The argv the Infection lane hands to infection.phar. Two lanes:
 *
 *   - FULL: the whole codebase against the SSoT floors (--min-msi and
 *     --min-covered-msi).
 *   - DIFF: only the changed files, passed as positional paths LAST, against
 *     the single covered-MSI diff floor. Changed files with no mutable code
 *     generate no mutant, which passes (--ignore-msi-with-no-mutations;
 *     Infection prints that nothing was generated) instead of scoring 0%.
 *
 * Both write the file loggers in infection.json (log.txt, summary-log.txt) at
 * full verbosity, so a project reading them gets the same files either way.
 * Infection mutates only covered code (no --with-uncovered), so the MSI and
 * the covered MSI of a run are the same number.
 *
 * Coverage is always reused (--skip-initial-tests): the lane guarantees it
 * exists before Infection runs, so the suite is never executed a second time.
 *
 * @internal
 */
final readonly class InfectionArguments
{
    private const string LOG_VERBOSITY = '--log-verbosity=all';

    /** @return list<string> */
    public function full(InfectionOptionsDto $options, string $coverageDir, string $configPath): array
    {
        return [
            ...$this->common($options, $coverageDir, $configPath),
            '--min-msi=' . $options->minMsi,
            '--min-covered-msi=' . $options->minCoveredMsi,
            self::LOG_VERBOSITY,
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
            '--ignore-msi-with-no-mutations',
            self::LOG_VERBOSITY,
            ...array_values($positionalPaths),
        ];
    }

    /**
     * `$options->onlyCovered` adds nothing: the bundled Infection mutates only covered code by
     * default and has no `--only-covered` option.
     *
     * @return list<string>
     */
    private function common(InfectionOptionsDto $options, string $coverageDir, string $configPath): array
    {
        return [
            '--coverage=' . $coverageDir,
            '--skip-initial-tests',
            '--threads=' . $options->threads,
            '--configuration=' . $configPath,
        ];
    }
}
