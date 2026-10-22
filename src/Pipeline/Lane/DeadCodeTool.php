<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PackageType\ProjectComposerTypeReader;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Config\IgnoredPaths;
use LTS\PHPQA\Pipeline\Lane\DeadCode\AnalysedFiles;
use LTS\PHPQA\Pipeline\Lane\DeadCode\BinEntryPointScripts;
use LTS\PHPQA\Pipeline\Lane\DeadCode\DetectorUnpacker;
use LTS\PHPQA\Pipeline\Lane\DeadCode\Dto\BinScanDto;
use LTS\PHPQA\Pipeline\Lane\DeadCode\GitIgnoredFiles;
use LTS\PHPQA\Pipeline\Lane\Phpstan\Dto\PhpstanParametersDto;
use LTS\PHPQA\Pipeline\Lane\Phpstan\DumpParametersTmpDirProbe;
use LTS\PHPQA\Pipeline\Lane\Phpstan\ExcludePathsNeon;
use LTS\PHPQA\Pipeline\Lane\Phpstan\PhpstanCrash;
use LTS\PHPQA\Pipeline\Lane\Phpstan\TmpDirNeon;
use LTS\PHPQA\Pipeline\Lane\Phpstan\TmpDirProbeInterface;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Dead-code detection: shipmonk/dead-code-detector run through the shipped
 * phpstan.phar, loaded from vendor-phar/dead-code-detector.phar (its neon by
 * include, its classes by --autoload-file) rather than from any Composer package, so it is present only in this lane and never in
 * the PHPStan gate. PHPStan gets it unpacked into the QA cache (DetectorUnpacker),
 * never as a second PHAR its forked workers would share. Opt-in per project; the tests excluder is always on
 * (a member reached only from tests is dead in production) and the project's
 * PHP entry-point scripts are analysed alongside src/ and tests/. Every PHP
 * script under bin/ that this run would not otherwise read must be one of them
 * (BinEntryPointScripts), or the lane fails before analysing anything. What the
 * run reads is judged from PHPStan's own answer for the project's config, the
 * one dump-parameters run that also decides where the cache goes.
 *
 * A library's public surface is its `@api` classes, which the detector treats
 * as entry points. A library with none would have every public member
 * reported, so the lane fails fast on that before running anything.
 *
 * @internal
 */
final readonly class DeadCodeTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.deadCode';

    public const string LOG_DIR = 'deadCode';

    public const string LOG_FILE = 'dead-code.log';

    public const string WRAPPER_NEON = 'dead-code.neon';

    public const string CACHE_DIR = 'deadCode';

    private const string PHAR = 'dead-code-detector.phar';

    private const string COMPOSER_JSON = 'composer.json';

    private const string API_TAG = '@api';

    private const string LABEL = 'Dead code';

    private const string BIN = 'bin';

    public function __construct(private TmpDirProbeInterface $tmpDirProbe = new DumpParametersTmpDirProbe())
    {
    }

    public function name(): string
    {
        return 'deadCode';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $config = $context->config;
        if (!$config->deadCode->enabled) {
            return ToolResultDto::skipped('off; withDeadCodeDetection(true) in qaConfig/qa.php enables it');
        }

        if ($this->isLibrary($config->paths->projectRoot) && !$this->hasApiTag($config->paths->srcDir)) {
            $this->noApiGuidance($context);
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::failed('no @api tag in src/; a library needs its public surface declared before dead-code detection means anything');
        }

        $detector = new DetectorUnpacker($context->output)->unpack($config->paths->pharDir . '/' . self::PHAR, $config->paths->cacheDir);
        // One question to PHPStan answers both the bin/ check and where the cache goes.
        $parameters = $this->tmpDirProbe->parameters($context, $context->configPath('phpstan.neon'), $detector . '/' . DetectorUnpacker::AUTOLOAD);
        $scan       = $this->binScan($context, $parameters ?? PhpstanParametersDto::defaults());
        if (!$scan->isClean()) {
            $this->binGuidance($context, $scan);
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::failed($this->binSummary($scan));
        }

        $logDir  = $context->logDir(self::LOG_DIR);
        $wrapper = $this->writeWrapperNeon($context, $logDir, $detector, $parameters);
        // The detector's classes must exist when PHPStan compiles its container,
        // which is before any bootstrapFiles run; --autoload-file is the hook for that.
        $args = ['analyse', '-c', $wrapper, '--autoload-file', $detector . '/' . DetectorUnpacker::AUTOLOAD, PhpstanCrash::TABLE_FORMAT];
        if ($config->ci) {
            $args[] = '--no-progress';
        }

        $result = $context->php->withoutXdebug($config->paths->pharDir . '/phpstan.phar', $args, $config->paths->projectRoot);
        \Safe\file_put_contents($logDir . '/' . self::LOG_FILE, $result->output);
        $context->logs->archive('DeadCode', $logDir, self::LOG_FILE, false, $config->pathsToCheck);

        if ($result->succeeded()) {
            return ToolResultDto::passed();
        }

        $crash = PhpstanCrash::reason($result);
        if (null !== $crash) {
            return ToolResultDto::crashed($crash);
        }

        $this->findingsGuidance($context);
        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed('dead code found');
    }

    /**
     * The gate's resolved phpstan.neon (so excludes, stubs and level carry
     * over) plus the detector from its PHAR, the tests excluder, the entry
     * points, the same worker cap and ignored-path exclusions the PHPStan
     * lane writes, and a cache of the lane's own (TmpDirNeon). An ignored path is not analysed, so a member reached only
     * from there counts as unused, exactly as for an excludePaths entry in the
     * project's own phpstan.neon.
     */
    private function writeWrapperNeon(ToolContext $context, string $logDir, string $detector, ?PhpstanParametersDto $parameters): string
    {
        $config  = $context->config;
        $paths   = $config->paths;
        $wrapper = $logDir . '/' . self::WRAPPER_NEON;

        $neon = \sprintf(
            "includes:\n    - %s\n    - %s/%s\n\nparameters:\n    parallel:\n        maximumNumberOfProcesses: %d\n    paths:\n",
            $context->configPath('phpstan.neon'),
            $detector,
            DetectorUnpacker::RULES_NEON,
            $config->halfCpuThreads,
        );
        foreach ([$paths->srcDir, $paths->testsDir, ...$config->deadCode->entryPoints] as $path) {
            $neon .= \sprintf("        - %s\n", $path);
        }

        $neon .= new TmpDirNeon($this->tmpDirProbe)->forLaneFrom($context, self::LABEL, self::CACHE_DIR, $parameters);
        $neon .= new ExcludePathsNeon()->parameters(IgnoredPaths::of($config));
        $neon .= "    shipmonkDeadCode:\n        usageExcluders:\n            tests:\n                enabled: true\n";

        \Safe\file_put_contents($wrapper, $neon);

        return $wrapper;
    }

    private function isLibrary(string $projectRoot): bool
    {
        $composerJson = $projectRoot . '/' . self::COMPOSER_JSON;
        $decoded      = is_file($composerJson) ? \Safe\json_decode(\Safe\file_get_contents($composerJson), true) : null;

        return new ProjectComposerTypeReader(\is_array($decoded) ? $decoded : [])->enforcesApiSurface();
    }

    private function hasApiTag(string $srcDir): bool
    {
        if (!is_dir($srcDir)) {
            return false;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && 'php' === $file->getExtension() && str_contains(\Safe\file_get_contents($file->getPathname()), self::API_TAG)) {
                return true;
            }
        }

        return false;
    }

    private function noApiGuidance(ToolContext $context): void
    {
        $context->writeln('');
        $context->writeln('Dead-code detection needs a declared public surface: the detector treats every');
        $context->writeln('@api class as an entry point, and a library with none has every public member');
        $context->writeln('reported as dead. Classify every public class-like as @api or @internal first');
        $context->writeln('(RequireApiOrInternalTagRule enforces exactly that), then run this lane again.');
    }

    /**
     * The bin/ check over what this run analyses: the lane's own paths merged with the project's
     * phpstan.neon `paths`, `excludePaths` and `fileExtensions`, as PHPStan merges them.
     */
    private function binScan(ToolContext $context, PhpstanParametersDto $parameters): BinScanDto
    {
        $config = $context->config;
        $paths  = $config->paths;
        if (!is_dir($paths->projectRoot . '/' . self::BIN)) {
            return new BinScanDto([], []);
        }

        $analysed = new AnalysedFiles(
            [...$parameters->paths, $paths->srcDir, $paths->testsDir, ...$config->deadCode->entryPoints],
            $parameters->excludePaths,
            $parameters->fileExtensions,
        );

        return new BinEntryPointScripts(IgnoredPaths::of($config), $analysed, $this->gitIgnored($context))->scan($paths->projectRoot);
    }

    /** What git ignores in the project; nothing, said on the output, when git cannot answer. */
    private function gitIgnored(ToolContext $context): GitIgnoredFiles
    {
        $result = $context->processes->run(new ProcessSpecDto(GitIgnoredFiles::COMMAND, $context->config->paths->projectRoot, streamOutput: false));
        if (!$result->succeeded()) {
            $context->writeln(\sprintf('%s: git could not list the ignored files (%s), so no file under bin/ is skipped as git-ignored', self::LABEL, $result->describeExit()));

            return GitIgnoredFiles::none();
        }

        return GitIgnoredFiles::fromListing($result->stdout);
    }

    private function binSummary(BinScanDto $scan): string
    {
        $parts      = [];
        $undeclared = $scan->undeclared;
        if ([] !== $undeclared) {
            $parts[] = 1 === \count($undeclared)
                ? '1 PHP script under bin/ is not a declared entry point: ' . $undeclared[0]
                : \count($undeclared) . ' PHP scripts under bin/ are not declared entry points: ' . implode(', ', $undeclared);
        }

        $unreadable = $scan->unreadable;
        if ([] !== $unreadable) {
            $parts[] = 1 === \count($unreadable)
                ? '1 path under bin/ could not be read, so whether it holds an undeclared PHP script is unknown: ' . $unreadable[0]
                : \count($unreadable) . ' paths under bin/ could not be read, so whether they hold an undeclared PHP script is unknown: ' . implode(', ', $unreadable);
        }

        return implode('; ', $parts);
    }

    private function binGuidance(ToolContext $context, BinScanDto $scan): void
    {
        if ([] !== $scan->undeclared) {
            $context->writeln('');
            $context->writeln('PHP scripts under bin/ not declared as dead-code entry points:');
            foreach ($scan->undeclared as $script) {
                $context->writeln('  ' . $script);
            }

            $context->writeln('');
            $context->writeln('The detector reads a script under bin/ only when it is declared: one left off the');
            $context->writeln('list is analysed by nothing, and every member only it reaches is reported dead.');
            $context->writeln('List each with withDeadCodeEntryPoints() in qaConfig/qa.php. A declared script');
            $context->writeln("the project's phpstan.neon excludePaths removes is not read either.");
        }

        if ([] !== $scan->unreadable) {
            $context->writeln('');
            $context->writeln('Paths under bin/ the lane could not read:');
            foreach ($scan->unreadable as $path) {
                $context->writeln('  ' . $path);
            }

            $context->writeln('');
            $context->writeln('A path the lane cannot read may hold a PHP script analysed by nothing. Make it');
            $context->writeln("readable, or remove it if it is not the project's.");
        }
    }

    private function findingsGuidance(ToolContext $context): void
    {
        $context->writeln('');
        $context->writeln('HOW TO FIX');
        $context->writeln('----------');
        $context->writeln('Each report names a member nothing reaches. Delete it, or wire the caller the');
        $context->writeln('report shows is missing. Two shapes are not dead code and have their own fix:');
        $context->writeln('  ENTRY POINT — a method only a script or an external runtime calls. List the');
        $context->writeln('  script with withDeadCodeEntryPoints() in qaConfig/qa.php; a class an external');
        $context->writeln('  runtime drives (a Composer plugin, a console command) is @api.');
        $context->writeln('  TESTS ONLY — "all usages excluded by tests excluder": production never calls');
        $context->writeln('  it. Delete it with the tests that used it, or move it into the tests tree.');
        $context->writeln('Never suppress with an ignore comment or a baseline.');
    }
}
