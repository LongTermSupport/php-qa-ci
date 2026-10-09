<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use FilesystemIterator;
use JsonException;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Config\IgnoredPaths;
use LTS\PHPQA\Pipeline\Lane\Infection\IgnoredPathsInfectionConfig;
use LTS\PHPQA\Pipeline\Lane\Infection\InfectionArguments;
use LTS\PHPQA\Pipeline\Lane\Infection\InfectionDiffBaseResolver;
use LTS\PHPQA\Pipeline\Lane\Infection\InfectionDiffFilter;
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
 *      executed a redundant second time.
 *
 * Without Xdebug there is no coverage and the lane skips. What is mutated
 * is decided first (InfectionDiffBaseResolver) and printed in one line: by
 * default a branch is scoped to the files committed since its merge base
 * with the default branch, and the default branch, or a clone with no merge
 * base, runs in full. In diff mode the lane scopes mutation to the PHP files
 * committed since the base plus the sources the changed tests are named
 * after, and enforces the diff covered-MSI floor; an empty diff skips before
 * any coverage is generated. Uncommitted work under src/tests refuses an
 * explicit base and is reported, but not mutated, in auto mode. The phar
 * runs at low CPU priority.
 *
 * @internal
 */
final readonly class InfectionTool implements ToolInterface
{
    /** The stable identifier a failing run prints, resolved by `bin/rule-doc`. */
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.infection';

    /** The derived infection.json under var/qa/, written when an ignored path lies under a source directory. */
    public const string DERIVED_CONFIG = 'infection-config/infection.json';

    /** How many unmapped test files the lane names before summarising the rest. */
    private const int UNMAPPED_SHOWN = 10;

    public function __construct(
        private InfectionArguments $arguments = new InfectionArguments(),
        private InfectionDiffFilter $diffFilter = new InfectionDiffFilter(),
        private IgnoredPathsInfectionConfig $ignoredPathsConfig = new IgnoredPathsInfectionConfig(),
        private InfectionDiffBaseResolver $diffBaseResolver = new InfectionDiffBaseResolver(),
        private ?EnvironmentReader $environment = null,
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

        $configPath = $this->configPath($context);
        if ($configPath instanceof ToolResultDto) {
            return $configPath;
        }

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

        $result = $context->processes->run($context->php->specWithoutXdebug(
            $paths->pharDir . '/infection.phar',
            $args,
            $paths->projectRoot,
            [],
            true,
            lowPriority: true,
        ));

        if ($result->succeeded()) {
            return ToolResultDto::passed();
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed(\sprintf('Infection failed (exit %d)', $result->exitCode));
    }

    /**
     * The config Infection runs with: the resolved infection.json, or, when a
     * withIgnoredPaths() entry lies under one of its source directories, the
     * copy IgnoredPathsInfectionConfig derives, written to var/qa/. Returns the
     * lane's result instead when the config cannot be read or every source
     * directory is ignored.
     */
    private function configPath(ToolContext $context): string|ToolResultDto
    {
        $resolved = $context->configPath('infection.json');

        try {
            $derived = $this->ignoredPathsConfig->derive($resolved, IgnoredPaths::of($context->config));
        } catch (JsonException $jsonException) {
            $context->writeln(\sprintf('Infection: %s could not be read as JSON (%s), so the ignored paths cannot be applied to it.', $resolved, $jsonException->getMessage()));

            return ToolResultDto::crashed('infection.json is not valid JSON');
        }

        if (null === $derived) {
            return $resolved;
        }

        $source = $derived['source'] ?? null;
        if (!\is_array($source) || [] === ($source['directories'] ?? [])) {
            $context->writeln(\sprintf('Infection: every source directory in %s is an ignored path (withIgnoredPaths in qaConfig/qa.php), so there is nothing to mutate. SKIPPING.', $resolved));

            return ToolResultDto::skipped('every source directory is ignored');
        }

        $context->logDir(\dirname(self::DERIVED_CONFIG));
        $path = $context->config->paths->varDir . '/' . self::DERIVED_CONFIG;
        \Safe\file_put_contents($path, \Safe\json_encode($derived, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");
        $context->writeln('Infection: the ignored paths under its source directories are excluded through ' . $path);

        return $path;
    }

    /**
     * Everything diff mode decides before coverage is paid for: the clean-tree
     * check and the changed-file list. Returns the lane's result when it ends
     * here (refused, git failed, nothing to mutate), otherwise the positional
     * paths Infection mutates.
     *
     * @return list<string>|ToolResultDto
     */
    private function resolveDiffScope(ToolContext $context, string $diffBase, bool $strict): array|ToolResultDto
    {
        $preflight = $this->checkTreeForDiffMode($context, $strict);
        if ($preflight instanceof ToolResultDto) {
            return $preflight;
        }

        $paths = $context->config->paths;
        $diff  = $this->git($context, ...$this->diffFilter->gitDiffArguments($diffBase, $paths->srcDir, $paths->testsDir));
        if (!$diff->succeeded()) {
            $context->writeln(\sprintf("Infection: 'git diff' against base '%s' failed (exit %d) — cannot determine the changed files for diff mode.", $diffBase, $diff->exitCode));
            $context->writeln("           Check that the base ref exists and shares history with HEAD (e.g. 'git fetch origin' first).");
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::failed('Infection diff mode: git diff failed');
        }

        $filter = $this->diffFilter->fromGitDiffOutput($diff->stdout, $paths->projectRoot, $paths->srcDir, $paths->testsDir, IgnoredPaths::of($context->config));
        $this->reportUnmappedTests($context, ...$filter->unmappedTests);
        if ($filter->isEmpty()) {
            $context->writeln(\sprintf("Infection: diff mode — no committed PHP source change, and no changed test named after a source file, against '%s'; there are no new mutants to check. SKIPPING.", $diffBase));
            $context->writeln('           Nothing to mutate, so no coverage run is started. A diff run does not check changes outside');
            $context->writeln('           src/ and tests/ (phpunit.xml, infection.json, composer.lock); infectionDiffBase=full does.');

            return ToolResultDto::skipped('no committed PHP source changes to mutate');
        }

        foreach ($filter->mirrored as $source => $test) {
            $context->writeln(\sprintf('Infection: diff mode — %s is in scope because its test %s changed.', $source, $test));
        }

        $context->writeln(\sprintf('Infection: diff mode — mutating %d file(s): %s', \count($filter->positionalPaths), $filter->display()));

        return $filter->positionalPaths;
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
     * Diff mode measures the COMMITTED change, so uncommitted work under
     * src/tests is never in its scope. An explicit base refuses it: the
     * verdict must be reproducible, and a dirty-tree run has been seen to
     * under-report. Auto mode, the default, must not fail a run over local
     * work in progress, so it names the uncommitted files as out of scope and
     * carries on. Dirt elsewhere cannot affect mutation results and is ignored.
     */
    private function checkTreeForDiffMode(ToolContext $context, bool $strict): ?ToolResultDto
    {
        $paths  = $context->config->paths;
        $status = $this->git($context, 'status', '--porcelain', '--', $paths->srcDir, $paths->testsDir);
        if (!$status->succeeded()) {
            $context->writeln("Infection: diff mode — 'git status' failed; cannot verify the working tree is clean.");
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::failed('Infection diff mode: git status failed');
        }

        $dirty = rtrim($status->stdout, "\n");
        if ('' === $dirty) {
            return null;
        }

        if (!$strict) {
            $context->writeln('Infection: auto diff mode — uncommitted changes under the source/tests directories are NOT mutated');
            $context->writeln('           by this run (it reads committed history only); commit them to bring them into scope:');
            $context->writeln($dirty);

            return null;
        }

        $context->writeln('Infection: diff mode REFUSED — uncommitted changes under the source/tests directories:');
        $context->writeln($dirty);
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
