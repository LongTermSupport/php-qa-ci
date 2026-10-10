<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Infection;

use JsonException;
use LTS\PHPQA\Pipeline\Lane\Infection\Dto\KillJudgementDto;
use LTS\PHPQA\Pipeline\Lane\Infection\Dto\VacuousKillDto;

/**
 * Finds the mutants Infection counted as killed although no test ran.
 *
 * Infection scores a mutant as killed whenever the test process exits with a
 * code from 1 to 100, whatever stopped it. A suite that cannot start under
 * Infection's wrapper (its generated PHPUnit config and bootstrap) therefore
 * "kills" every mutant, and the lane, which skips Infection's initial test run,
 * would report the resulting MSI as a pass. This reads Infection's JSON log
 * (`logs.json`) and judges each killed mutant's test output. No test ran when
 * PHPUnit says so ("No tests executed!", printed when the run counted zero
 * tests), or when PHPUnit stopped before the suite started: its version banner
 * with no `Runtime:` line after it, which is how PHPUnit reports a bootstrap
 * script or configuration it cannot load. Output that started the suite and
 * then printed no summary is not judged: a test ran into the mutated code and
 * the process ended, which is a kill. Another framework's output is not judged.
 * Whether the vacuous kills mean the suite never started is decided against
 * the number judged (KillJudgementDto::suiteNeverStarted()).
 *
 * @internal
 */
final readonly class VacuousKillDetector
{
    /** PHPUnit's line for a run that counted zero tests, alone on its line, colour codes allowed around it. */
    private const string NO_TESTS_EXECUTED = '/^(?:\s|\e\[[\d;]*m)*No tests executed!(?:\s|\e\[[\d;]*m)*$/m';

    /** PHPUnit's version banner, printed first in every run, including one that stops before the suite. */
    private const string PHPUNIT_BANNER = '/^(?:\s|\e\[[\d;]*m)*PHPUnit \S+ by Sebastian Bergmann/m';

    /** PHPUnit's runtime line, printed once the bootstrap and configuration have loaded. */
    private const string SUITE_STARTED = '/^(?:\s|\e\[[\d;]*m)*Runtime:\s/m';

    /** The key of a mutant's test process output in Infection's JSON log. */
    private const string PROCESS_OUTPUT = 'processOutput';

    /** Why a mixed judgement cannot be made from a log whose stats lack a count. */
    private const string NO_STATS = 'the Infection JSON log has no "stats" to work out the scores without the kills no test ran for';

    /** The counts in the log's stats Infection's scores are worked out from. */
    private const array COUNTS = [
        'totalMutantsCount',
        'killedCount',
        'killedByStaticAnalysisCount',
        'notCoveredCount',
        'errorCount',
        'syntaxErrorCount',
        'skippedCount',
        'ignoredCount',
        'timeOutCount',
    ];

    /**
     * @return KillJudgementDto how many killed mutants were judged, and every
     *                          one whose test output shows no test ran
     *
     * @throws JsonException when the log is not Infection's JSON log, or a killed
     *                       mutant carries no test output to judge
     */
    public function find(string $jsonLog): KillJudgementDto
    {
        $log = \Safe\json_decode($jsonLog, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($log) || !\is_array($log['killed'] ?? null)) {
            throw new JsonException('the Infection JSON log has no "killed" list');
        }

        $found = [];
        foreach ($log['killed'] as $killed) {
            $mutant = $this->describe($killed);
            if (!\is_array($killed) || !\is_string($killed[self::PROCESS_OUTPUT] ?? null)) {
                throw new JsonException(\sprintf('the killed mutant %s in the Infection JSON log has no %s', $mutant, self::PROCESS_OUTPUT));
            }

            if ($this->noTestRan($killed[self::PROCESS_OUTPUT])) {
                $found[] = new VacuousKillDto($mutant, $killed[self::PROCESS_OUTPUT]);
            }
        }

        $judged = \count($log['killed']);
        if ([] === $found || \count($found) === $judged) {
            return new KillJudgementDto($judged, $found);
        }

        return $this->withCounts($judged, $log['stats'] ?? null, ...$found);
    }

    /**
     * Some kills ran no test and some did, so the verdict may rest on the
     * vacuous ones: the judgement carries the counts Infection scores from.
     * Detected is every kill, error and timeout, unless the run counted
     * timeouts as escaped; Infection's own MSI says which, and counts that
     * give neither are refused rather than guessed at.
     *
     * @throws JsonException
     */
    private function withCounts(int $judged, mixed $stats, VacuousKillDto ...$found): KillJudgementDto
    {
        if (!\is_array($stats)) {
            throw new JsonException(self::NO_STATS);
        }

        $counts = [];
        foreach (self::COUNTS as $count) {
            $value = $stats[$count] ?? null;
            if (!\is_int($value)) {
                throw new JsonException(self::NO_STATS);
            }

            $counts[$count] = $value;
        }

        $msi = $stats['msi'] ?? null;
        if (!\is_int($msi) && !\is_float($msi)) {
            throw new JsonException(self::NO_STATS);
        }

        $found = array_values($found);

        $tested   = $counts['totalMutantsCount'] - $counts['skippedCount'] - $counts['ignoredCount'];
        $covered  = $tested                      - $counts['notCoveredCount'];
        $detected = $counts['killedCount'] + $counts['killedByStaticAnalysisCount'] + $counts['errorCount'] + $counts['syntaxErrorCount'];
        foreach ([$detected + $counts['timeOutCount'], $detected] as $candidate) {
            $judgement = new KillJudgementDto($judged, $found, $candidate, $tested, $covered);
            if (new KillJudgementDto($judged, [], $candidate, $tested, $covered)->msiWithoutVacuous() === (float)$msi) {
                return $judgement;
            }
        }

        throw new JsonException(\sprintf('the stats in the Infection JSON log do not add up to its MSI of %s%%', $msi));
    }

    private function noTestRan(string $output): bool
    {
        if (1 === \Safe\preg_match(self::NO_TESTS_EXECUTED, $output)) {
            return true;
        }

        return 1 === \Safe\preg_match(self::PHPUNIT_BANNER, $output) && 0 === \Safe\preg_match(self::SUITE_STARTED, $output);
    }

    /** `<file>:<line> <mutator>` as far as the entry says, for the message. */
    private function describe(mixed $killed): string
    {
        $mutator = \is_array($killed) && \is_array($killed['mutator'] ?? null) ? $killed['mutator'] : [];
        $file    = \is_string($mutator['originalFilePath'] ?? null) ? $mutator['originalFilePath'] : '?';
        $line    = \is_int($mutator['originalStartLine'] ?? null) ? $mutator['originalStartLine'] : '?';
        $name    = \is_string($mutator['mutatorName'] ?? null) ? $mutator['mutatorName'] : '?';

        return \sprintf('%s:%s %s', $file, $line, $name);
    }
}
