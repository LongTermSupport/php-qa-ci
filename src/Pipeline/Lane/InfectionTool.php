<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use Closure;
use FilesystemIterator;
use JsonException;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Config\IgnoredPaths;
use LTS\PHPQA\Pipeline\Lane\Infection\Dto\VacuousKillDto;
use LTS\PHPQA\Pipeline\Lane\Infection\IgnoredPathsInfectionConfig;
use LTS\PHPQA\Pipeline\Lane\Infection\InfectionArguments;
use LTS\PHPQA\Pipeline\Lane\Infection\InfectionDiffBaseResolver;
use LTS\PHPQA\Pipeline\Lane\Infection\InfectionDiffFilter;
use LTS\PHPQA\Pipeline\Lane\Infection\InfectionFullRunTriggers;
use LTS\PHPQA\Pipeline\Lane\Infection\VacuousKillDetector;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Mutation testing: does the test suite kill injected faults? Self-contained
 * and safe for a kernel-booting suite:
 *
 *   1. Coverage is REUSED when the phpunit lane produced it this run (a full
 *      pipeline run with a non-empty coverage-xml dir) and GENERATED otherwise
 *      (`-t infection`, or a clean checkout) with one Xdebug coverage run. That
 *      run also proves the suite green, which is what lets Infection skip its
 *      own initial run.
 *   2. Infection always gets --skip-initial-tests, so the suite is never
 *      executed a redundant second time. That skipped run is Infection's
 *      only check that the suite starts under its wrapper, so the lane makes
 *      its own: it reads every killed mutant's test output from Infection's
 *      JSON log and crashes when no test ran for any of them.
 *
 * Without Xdebug there is no coverage and the lane skips. What is mutated
 * is decided first (InfectionDiffBaseResolver) and printed in one line: by
 * default a branch is scoped to the files committed since its merge base
 * with the default branch, and the default branch, or a clone with no merge
 * base, runs in full. In diff mode the lane scopes mutation to the PHP files
 * changed since the base plus the sources the changed tests are named after,
 * uncovered code included, and holds both MSI figures to the diff floor; an
 * empty diff skips before any coverage is generated. A change to a file
 * Infection or PHPUnit reads to decide what is mutated and whether a mutant
 * is killed (InfectionFullRunTriggers) runs in full; composer.json,
 * composer.lock and the rest of qaConfig/ do not. A file whose change is only
 * comments, docblocks or whitespace is named and not mutated. Uncommitted
 * work refuses an explicit base; in auto mode it is mutated as it is on disk
 * and named in a warning. The phar runs at low CPU priority.
 *
 * @internal
 */
final readonly class InfectionTool implements ToolInterface
{
    /** The stable identifier a failing run prints, resolved by `bin/rule-doc`. */
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.infection';

    /** The infection.json Infection runs with, a copy of the resolved one under var/qa/. */
    public const string DERIVED_CONFIG = 'infection-config/infection.json';

    /** The JSON log the lane reads each mutant's test output from, under var/qa/, unless the project's config names one. */
    public const string JSON_LOG = 'infection/infection-log.json';

    /** How many unmapped test files, or vacuously killed mutants, the lane names before summarising the rest. */
    private const int UNMAPPED_SHOWN = 10;

    public function __construct(
        private InfectionArguments $arguments = new InfectionArguments(),
        private InfectionDiffFilter $diffFilter = new InfectionDiffFilter(),
        private IgnoredPathsInfectionConfig $ignoredPathsConfig = new IgnoredPathsInfectionConfig(),
        private InfectionDiffBaseResolver $diffBaseResolver = new InfectionDiffBaseResolver(),
        private ?EnvironmentReader $environment = null,
        private InfectionFullRunTriggers $fullRunTriggers = new InfectionFullRunTriggers(),
        private VacuousKillDetector $vacuousKills = new VacuousKillDetector(),
    ) {
    }

    public function name(): string
    {
        return 'infection';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $config  = $context->config;
        $paths   = $config->paths;
        $options = $config->infection;

        if (!$config->xdebugEnabled) {
            $context->writeln('Infection: Xdebug is not enabled — cannot generate coverage, so mutation testing cannot run. SKIPPING.');

            return ToolResultDto::skipped('Xdebug not enabled');
        }

        $runConfig = $this->runConfig($context);
        if ($runConfig instanceof ToolResultDto) {
            return $runConfig;
        }

        [$configPath, $jsonLog] = $runConfig;

        $base = $this->diffBaseResolver->resolve(
            $options,
            $context->processes,
            $paths->projectRoot,
            $this->environment ?? new EnvironmentReader(EnvironmentReader::fromProcess()),
        );
        $context->writeln($base->description);

        $positionalPaths = [];
        if (null !== $base->ref) {
            $scope = $this->resolveDiffScope($context, $base->ref, $base->strict);
            if ($scope instanceof ToolResultDto) {
                return $scope;
            }

            $positionalPaths = $scope;
        }

        $logsDir        = $paths->varDir . '/phpunit_logs';
        $coverageXmlDir = $logsDir . '/coverage-xml';
        $coverage       = $this->ensureCoverage($context, $coverageXmlDir, $logsDir);
        if ($coverage instanceof ToolResultDto) {
            return $coverage;
        }

        $args = [] === $positionalPaths
            ? $this->arguments->full($options, $logsDir, $configPath)
            : $this->arguments->diff($options, $logsDir, $configPath, ...$positionalPaths);

        $this->clearDirectory($paths->varDir . '/infection');
        if (is_file($jsonLog)) {
            \Safe\unlink($jsonLog);
        }

        $result = $context->processes->run($context->php->specWithoutXdebug(
            $paths->pharDir . '/infection.phar',
            $args,
            $paths->projectRoot,
            [],
            true,
            lowPriority: true,
        ));

        $kills = $this->judgeKills($context, $jsonLog, $result);
        if ($kills instanceof ToolResultDto) {
            return $kills;
        }

        if ($result->succeeded()) {
            return ToolResultDto::passed();
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed(\sprintf('Infection failed (exit %d)', $result->exitCode));
    }

    /**
     * The config Infection runs with and the JSON log it writes: always a copy
     * of the resolved infection.json under var/qa/, with every path made
     * absolute, the withIgnoredPaths() entries under its source directories
     * excluded, and a `logs.json` (the project's own, else JSON_LOG) for
     * judgeKills() to read. Returns the lane's result instead when the config
     * cannot be read or every source directory is ignored.
     *
     * @return array{string, string}|ToolResultDto the config path and the JSON log path
     */
    private function runConfig(ToolContext $context): array|ToolResultDto
    {
        $resolved = $context->configPath('infection.json');

        try {
            $derived = $this->ignoredPathsConfig->derive($resolved, IgnoredPaths::of($context->config));
            $config  = $derived ?? $this->ignoredPathsConfig->relocated($resolved);
        } catch (JsonException $jsonException) {
            $context->writeln(\sprintf('Infection: %s could not be read as JSON (%s), so the lane cannot derive the config Infection runs with.', $resolved, $jsonException->getMessage()));
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::crashed('infection.json could not be read');
        }

        $source = $config['source'] ?? null;
        if (!\is_array($source) || [] === ($source['directories'] ?? [])) {
            $context->writeln(\sprintf('Infection: every source directory in %s is an ignored path (withIgnoredPaths in qaConfig/qa.php), so there is nothing to mutate. SKIPPING.', $resolved));

            return ToolResultDto::skipped('every source directory is ignored');
        }

        // An empty `logs` object reaches here as a stdClass, which holds nothing to keep.
        $logs    = \is_array($config['logs'] ?? null) ? $config['logs'] : [];
        $jsonLog = $context->config->paths->varDir . '/' . self::JSON_LOG;
        if (\is_string($logs['json'] ?? null) && str_starts_with($logs['json'], IgnoredPathsInfectionConfig::PHP_STREAM)) {
            // logs.json takes one target, and a stream cannot be read back.
            $context->writeln(\sprintf('Infection: logs.json in %s is %s, which the lane cannot read back, so this run writes the JSON log to %s instead.', $resolved, $logs['json'], $jsonLog));
        } elseif (\is_string($logs['json'] ?? null)) {
            $jsonLog = $logs['json'];
        }

        $logs['json']   = $jsonLog;
        $config['logs'] = $logs;

        $context->logDir(\dirname(self::DERIVED_CONFIG));
        $path = $context->config->paths->varDir . '/' . self::DERIVED_CONFIG;
        \Safe\file_put_contents($path, \Safe\json_encode($config, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");
        if (null !== $derived) {
            $context->writeln('Infection: the ignored paths under its source directories are excluded through ' . $path);
        }

        return [$path, $jsonLog];
    }

    /**
     * Infection counts a mutant as killed whenever the test process exits
     * non-zero, so a suite that cannot start under Infection's wrapper "kills"
     * every mutant, and --skip-initial-tests leaves nothing else to notice.
     * Returns a crash when Infection's JSON log shows that no test ran for any
     * killed mutant (VacuousKillDetector), when the log cannot be read, or when
     * a passing run wrote none. Otherwise null: when some kills were made by a
     * test the suite starts, so a mutant that stopped it before any test ran
     * broke the code its bootstrap runs, a genuine kill the run names.
     */
    private function judgeKills(ToolContext $context, string $jsonLog, ProcessResultDto $result): ?ToolResultDto
    {
        if (!is_file($jsonLog)) {
            if (!$result->succeeded()) {
                return null;
            }

            $context->writeln(\sprintf('Infection: the run passed but wrote no JSON log at %s, so the lane cannot check that its kills were made by tests.', $jsonLog));
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::crashed('Infection wrote no JSON log to verify its kills');
        }

        try {
            $judgement = $this->vacuousKills->find(\Safe\file_get_contents($jsonLog));
        } catch (JsonException $jsonException) {
            $context->writeln(\sprintf('Infection: its JSON log %s could not be read (%s), so the lane cannot check that its kills were made by tests.', $jsonLog, $jsonException->getMessage()));
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::crashed('the Infection JSON log could not be read');
        }

        $vacuous = $judgement->vacuous;
        if ([] === $vacuous) {
            return null;
        }

        if (!$judgement->suiteNeverStarted()) {
            $context->writeln(\sprintf('Infection: %d of %d killed mutant(s) stopped the test suite before any test ran, the first %s.', \count($vacuous), $judgement->judged, $vacuous[0]->mutant));
            $context->writeln('           The other kills ran tests, so the suite starts under Infection: these mutants broke code the test bootstrap runs, and count as killed.');
            $context->writeln('           Every mutant, with its test output: ' . $jsonLog);

            return null;
        }

        $context->writeln(\sprintf('Infection: no test ran for any of the %d mutant(s) Infection counted as killed, so the scores above are not a measurement.', $judgement->judged));
        $context->writeln('           The test suite does not start under Infection (its generated PHPUnit config and bootstrap): make it start, as docs/tools/infection.md describes.');
        $context->writeln('           Or every mutant in this run is in code the test bootstrap itself runs, and stopped it: a full run (infectionDiffBase=full) tells the two apart.');
        $context->writeln('           ' . implode(',', array_map(static fn (VacuousKillDto $kill): string => $kill->mutant, \array_slice($vacuous, 0, self::UNMAPPED_SHOWN))) . (\count($vacuous) > self::UNMAPPED_SHOWN ? \sprintf(' and %d more', \count($vacuous) - self::UNMAPPED_SHOWN) : ''));
        $context->writeln('           The test output of the first:');
        foreach (explode("\n", $vacuous[0]->output) as $line) {
            $context->writeln('             ' . $line);
        }

        $context->writeln('           Every mutant, with its test output: ' . $jsonLog);
        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::crashed(\sprintf('No test ran for any of the %d mutant(s) Infection counted as killed', $judgement->judged));
    }

    /**
     * Everything diff mode decides before coverage is paid for: the working
     * tree, the changed-file list, and whether a changed configuration file
     * forces the full run. Returns the lane's result when it ends here
     * (refused, git failed, nothing to mutate), an empty list for the full
     * run, otherwise the positional paths Infection mutates.
     *
     * @return list<string>|ToolResultDto
     */
    private function resolveDiffScope(ToolContext $context, string $diffBase, bool $strict): array|ToolResultDto
    {
        $paths    = $context->config->paths;
        $triggers = $this->fullRunTriggers->paths(
            $paths->projectRoot,
            $paths->projectConfigDir,
            $context->configPath('infection.json'),
            $context->configPath('phpunit.xml'),
        );
        $uncommitted = $this->uncommittedChanges($context, $strict, ...$triggers);
        if ($uncommitted instanceof ToolResultDto) {
            return $uncommitted;
        }

        $diff = $this->git($context, ...$this->diffFilter->gitDiffArguments($diffBase, $paths->srcDir, $paths->testsDir, ...$triggers));
        if (!$diff->succeeded()) {
            $context->writeln(\sprintf("Infection: 'git diff' against base '%s' failed (exit %d) — cannot determine the changed files for diff mode.", $diffBase, $diff->exitCode));
            $context->writeln("           Check that the base ref exists and shares history with HEAD (e.g. 'git fetch origin' first).");
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::failed('Infection diff mode: git diff failed');
        }

        $filter = $this->diffFilter->fromGitDiffOutput(
            $diff->stdout . $uncommitted,
            $paths->projectRoot,
            $paths->srcDir,
            $paths->testsDir,
            IgnoredPaths::of($context->config),
            $this->baseContent($context, $diffBase),
            ...$triggers,
        );
        if ([] !== $filter->configChanges) {
            $context->writeln(\sprintf(
                'Infection: full run — the change touches configuration every mutant depends on (%s), which a diff run cannot judge.',
                implode(',', array_unique($filter->configChanges)),
            ));

            return [];
        }

        if ([] !== $filter->commentOnly) {
            $context->writeln('Infection: diff mode — comment-only change, not mutated: ' . implode(',', $filter->commentOnly));
        }

        $this->reportUnmappedTests($context, ...$filter->unmappedTests);
        if ($filter->isEmpty()) {
            $context->writeln(\sprintf("Infection: diff mode — no PHP source change, and no changed test named after a source file, against '%s'; there are no new mutants to check. SKIPPING.", $diffBase));
            $context->writeln('           Nothing to mutate, so no coverage run is started.');

            return ToolResultDto::skipped('no PHP source changes to mutate');
        }

        foreach ($filter->mirrored as $source => $test) {
            $context->writeln(\sprintf('Infection: diff mode — %s is in scope because its test %s changed.', $source, $test));
        }

        $context->writeln(\sprintf('Infection: diff mode — mutating %d file(s): %s', \count($filter->positionalPaths), $filter->display()));

        return $filter->positionalPaths;
    }

    /**
     * A changed file's content at the merge base the three-dot diff compares
     * against, by project-relative path; null when git cannot produce it, so
     * the file is mutated rather than assumed unchanged. The merge base is
     * resolved once, on the first file that needs it.
     *
     * @return Closure(string): ?string
     */
    private function baseContent(ToolContext $context, string $diffBase): Closure
    {
        $mergeBase = null;

        return function (string $path) use ($context, $diffBase, &$mergeBase): ?string {
            if (null === $mergeBase) {
                $resolved  = $this->git($context, 'merge-base', $diffBase, 'HEAD');
                $mergeBase = $resolved->succeeded() ? trim($resolved->stdout) : '';
            }

            if ('' === $mergeBase) {
                return null;
            }

            $show = $this->git($context, 'show', $mergeBase . ':./' . $path);

            return $show->succeeded() ? $show->stdout : null;
        };
    }

    /** Changed test-directory files that name no source: what they cover is not mutated, and the run says so. */
    private function reportUnmappedTests(ToolContext $context, string ...$unmapped): void
    {
        if ([] === $unmapped) {
            return;
        }

        $shown = \array_slice($unmapped, 0, self::UNMAPPED_SHOWN);
        $more  = \count($unmapped) - \count($shown);
        $context->writeln(\sprintf(
            'Infection: diff mode — %d changed test-directory file(s) are named after no source file, so the source they cover is NOT mutated by this run: %s%s. infectionDiffBase=full mutates everything.',
            \count($unmapped),
            implode(',', $shown),
            $more > 0 ? \sprintf(' and %d more', $more) : '',
        ));
    }

    /**
     * Uncommitted work under the source, tests and configuration paths, as
     * `-z --name-status` records to append to the committed diff. An explicit
     * base refuses it: that verdict must be reproducible from history. Auto
     * mode, the default, must neither fail a local run nor silently leave the
     * work out, so it mutates the files as they are on disk and names every one
     * in a warning. Dirt elsewhere cannot affect mutation results and is ignored.
     */
    private function uncommittedChanges(ToolContext $context, bool $strict, string ...$configPaths): string|ToolResultDto
    {
        $paths  = $context->config->paths;
        $status = $this->git($context, 'status', '--porcelain=v1', '-z', '--untracked-files=all', '--', $paths->srcDir, $paths->testsDir, ...array_values($configPaths));
        if (!$status->succeeded()) {
            $context->writeln("Infection: diff mode — 'git status' failed; cannot verify the working tree is clean.");
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::failed('Infection diff mode: git status failed');
        }

        if ([] === $this->statusPaths($status->stdout)) {
            return '';
        }

        $prefix = $this->git($context, 'rev-parse', '--show-prefix');
        if (!$prefix->succeeded()) {
            $context->writeln("Infection: diff mode — 'git rev-parse --show-prefix' failed; cannot place the uncommitted files relative to the project.");
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::failed('Infection diff mode: git rev-parse failed');
        }

        $projectStatus = $this->diffFilter->withoutPrefix($status->stdout, rtrim($prefix->stdout, "\n"));
        $dirty         = $this->statusPaths($projectStatus);
        if (!$strict) {
            $context->writeln('Infection: auto diff mode — WARNING: uncommitted edits are in scope and mutated as they are on disk, so this verdict is not reproducible from committed history: ' . implode(',', $dirty));
            $context->writeln('           Commit them (a WIP commit is fine) for a result that matches what CI will see.');

            return $this->diffFilter->nameStatusFromGitStatus($projectStatus);
        }

        $context->writeln('Infection: diff mode REFUSED — uncommitted changes under the source, tests or configuration paths:');
        $context->writeln('           ' . implode(',', $dirty));
        $context->writeln('           A dirty-tree diff run can silently under-report (mutants for the uncommitted');
        $context->writeln('           code may never be generated), producing a false green. Commit the work first');
        $context->writeln('           (a WIP commit is fine), then re-run the diff lane against the committed state.');
        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed('Infection diff mode refused: dirty src/tests tree');
    }

    /**
     * Reuse the coverage the phpunit lane produced this run, or generate it
     * fresh (single-tool run, or nothing on disk). Returns the failure result
     * when generation fails, null when coverage is ready.
     */
    private function ensureCoverage(ToolContext $context, string $coverageXmlDir, string $logsDir): ?ToolResultDto
    {
        $config = $context->config;
        if (null === $config->singleTool && is_dir($coverageXmlDir) && !$this->isEmptyDirectory($coverageXmlDir)) {
            $context->writeln(\sprintf('Infection: reusing the coverage produced by the phpunit step this run (%s).', $coverageXmlDir));

            return null;
        }

        $context->writeln('Infection: generating fresh coverage (xdebug) before mutation testing.');
        $context->writeln('           (this also proves the suite is green, which lets Infection skip its initial run)');
        if (is_dir($coverageXmlDir)) {
            $this->clearDirectory($coverageXmlDir);
            \Safe\rmdir($coverageXmlDir);
        }

        if (!is_dir($logsDir)) {
            \Safe\mkdir($logsDir, 0o777, true);
        }

        $paths  = $config->paths;
        $result = $context->php->withXdebug(
            $paths->binDir . '/phpunit',
            ['-c', $context->configPath('phpunit.xml'), '--coverage-xml', $coverageXmlDir, '--log-junit', $logsDir . '/phpunit.junit.xml'],
            $paths->projectRoot,
            ['XDEBUG_MODE' => 'coverage'],
        );
        if ($result->succeeded()) {
            return null;
        }

        $context->writeln(\sprintf('Infection: coverage generation FAILED (phpunit exit %d) — the test suite is not green, so mutation testing cannot proceed.', $result->exitCode));
        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed(\sprintf('Infection (coverage generation) failed (phpunit exit %d)', $result->exitCode));
    }

    /**
     * The paths `git status --porcelain=v1 -z` lists, a rename or copy by its
     * new path (the record after it is the old one).
     *
     * @return list<string>
     */
    private function statusPaths(string $gitStatusOutput): array
    {
        $tokens = explode("\0", $gitStatusOutput);
        $count  = \count($tokens);
        $found  = [];
        for ($index = 0; $index < $count; ++$index) {
            if (\strlen($tokens[$index]) < 4) {
                continue;
            }

            $found[] = substr($tokens[$index], 3);
            if (str_contains('RC', ltrim(substr($tokens[$index], 0, 2))[0])) {
                ++$index;
            }
        }

        return $found;
    }

    private function git(ToolContext $context, string ...$args): ProcessResultDto
    {
        return $context->processes->run(new ProcessSpecDto(['git', ...array_values($args)], $context->config->paths->projectRoot, streamOutput: false));
    }

    private function isEmptyDirectory(string $dir): bool
    {
        return array_all(\Safe\scandir($dir), static fn ($entry): bool => !(\is_string($entry) && '.' !== $entry && '..' !== $entry));
    }

    /** Remove everything under $dir, keeping $dir itself (the `rm -rf dir/*` of the Bash fragment). */
    private function clearDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                \Safe\rmdir($item->getPathname());
            } else {
                \Safe\unlink($item->getPathname());
            }
        }
    }
}
