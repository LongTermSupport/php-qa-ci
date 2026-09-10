<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PackageType\ProjectComposerTypeReader;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
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
 * the PHPStan gate. Opt-in per project; the tests excluder is always on
 * (a member reached only from tests is dead in production) and the project's
 * PHP entry-point scripts are analysed alongside src/ and tests/.
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

    private const string PHAR = 'dead-code-detector.phar';

    private const string COMPOSER_JSON = 'composer.json';

    private const string API_TAG = '@api';

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

        $logDir  = $context->logDir(self::LOG_DIR);
        $wrapper = $this->writeWrapperNeon($context, $logDir);
        // The detector's classes must exist when PHPStan compiles its container,
        // which is before any bootstrapFiles run; --autoload-file is the hook for that.
        $args = ['analyse', '-c', $wrapper, '--autoload-file', $this->phar($context) . '/vendor/autoload.php'];
        if ($config->ci) {
            $args[] = '--no-progress';
        }

        $result = $context->php->withoutXdebug($config->paths->pharDir . '/phpstan.phar', $args, $config->paths->projectRoot);
        \Safe\file_put_contents($logDir . '/' . self::LOG_FILE, $result->output);
        $context->logs->archive('DeadCode', $logDir, self::LOG_FILE, false, $config->pathsToCheck);

        if ($result->succeeded()) {
            return ToolResultDto::passed();
        }

        if ($result->exitCode > 1) {
            return ToolResultDto::crashed(\sprintf('PHPStan crashed (exit %d)', $result->exitCode));
        }

        $this->findingsGuidance($context);
        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed('dead code found');
    }

    /**
     * The gate's resolved phpstan.neon (so excludes, stubs and level carry
     * over) plus the detector from its PHAR, the tests excluder, the entry
     * points and the same worker cap the PHPStan lane uses.
     */
    private function writeWrapperNeon(ToolContext $context, string $logDir): string
    {
        $config  = $context->config;
        $paths   = $config->paths;
        $wrapper = $logDir . '/' . self::WRAPPER_NEON;

        $neon = \sprintf(
            "includes:\n    - %s\n    - %s/vendor/shipmonk/dead-code-detector/rules.neon\n\nparameters:\n    parallel:\n        maximumNumberOfProcesses: %d\n    paths:\n",
            $context->configPath('phpstan.neon'),
            $this->phar($context),
            $config->halfCpuThreads,
        );
        foreach ([$paths->srcDir, $paths->testsDir, ...$config->deadCode->entryPoints] as $path) {
            $neon .= \sprintf("        - %s\n", $path);
        }

        $neon .= "    shipmonkDeadCode:\n        usageExcluders:\n            tests:\n                enabled: true\n";

        \Safe\file_put_contents($wrapper, $neon);

        return $wrapper;
    }

    /** The detector PHAR as a phar:// path, for neon includes and the autoloader. */
    private function phar(ToolContext $context): string
    {
        return 'phar://' . $context->config->paths->pharDir . '/' . self::PHAR;
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
