<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Config\IgnoredPaths;
use LTS\PHPQA\Pipeline\Lane\PhpCsFixer\FilteredFinderConfig;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * PHP CS Fixer over the checked paths with the resolved php_cs.php config,
 * less the project's ignored paths and any file whose real path cannot be
 * resolved, each of which is named as skipped. The fixer mutates code; a read-only run
 * passes --dry-run and a pending fix fails the lane with remediation
 * guidance, while a writable run applies it.
 * The fixer is not idempotent on every input (#235), and its cache records
 * what a pass left as clean, so a read-only run never uses the cache and a
 * writable pass that changed a file is followed by an uncached one.
 * The output is also written to var/qa/php-cs-fixer-output.log. A file the
 * fixer could not process at all (a parse failure) is fatal in either mode.
 *
 * @internal
 */
final readonly class PhpCsFixerTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.phpCsFixer';

    public const string FINDER_CONFIG = 'phpCsFixer/php_cs.php';

    public const string UNRESOLVED_LIST = 'phpCsFixer/unresolved-paths.txt';

    private const int EXIT_PENDING_FIXES = 8;

    private const string LINT_ERROR_MARKER = 'Files that were not fixed due to errors';

    private const string NO_CACHE = '--using-cache=no';

    private const string NOTHING_FIXED = '/^Fixed 0 of \d+ files/m';

    public function name(): string
    {
        return 'phpCsFixer';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $paths    = $context->config->paths;
        $readOnly = $context->config->readOnly;
        $config   = $this->config($context);
        $this->forgetUnresolved($context);
        if ($readOnly) {
            $context->writeln('Running PHP CS Fixer in read-only check mode');
            $result = $this->fix($context, $config, self::NO_CACHE, '--dry-run');
            $output = $result->output;
        } else {
            $result = $this->fix($context, $config, '--cache-file=' . $this->cache($context)->path);
            $output = $result->output;
            if ($result->succeeded() && $this->fixedAny($result->output)) {
                $context->writeln('PHP CS Fixer changed files; running it again without the cache, which records what one pass left as clean even where the next pass changes it again');
                $result = $this->fix($context, $config, self::NO_CACHE);
                $output .= $result->output;
            }
        }

        if (!is_dir($paths->varDir)) {
            \Safe\mkdir($paths->varDir, 0o777, true);
        }

        \Safe\file_put_contents($paths->varDir . '/php-cs-fixer-output.log', $output);
        $this->reportUnresolved($context);

        if (str_contains($output, self::LINT_ERROR_MARKER)) {
            $context->writeln('');
            $context->writeln(\sprintf(
                'ERROR: PHP CS Fixer encountered linting errors that prevented %s files',
                $readOnly ? 'checking' : 'fixing',
            ));
            $context->writeln('These errors must be fixed before continuing');
            $context->writeln('');
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::crashed('PHP CS Fixer could not process some files (lint errors)');
        }

        if ($result->succeeded()) {
            return ToolResultDto::passed();
        }

        if (!$readOnly) {
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::failed(\sprintf('PHP CS Fixer failed (exit %d)', $result->exitCode));
        }

        if (self::EXIT_PENDING_FIXES === $result->exitCode) {
            // The cache may record the pending files as clean, and the writable run asked for below would skip them
            $this->cache($context)->forget();
            ReadOnlyGuidance::wouldModify($context, 'PHP CS Fixer', 'fixer');
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::failed('PHP CS Fixer has pending fixes in a read-only run');
        }

        $context->writeln(\sprintf(
            'PHP CS Fixer failed with exit code %d (a genuine error, not a pending-fix diff) — see output above.',
            $result->exitCode,
        ));
        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::crashed(\sprintf('PHP CS Fixer crashed (exit %d)', $result->exitCode));
    }

    private function cache(ToolContext $context): FormatterCache
    {
        return FormatterCache::in($context, 'php_cs.cache');
    }

    /** One fixer process over the checked paths, with the cache option and any trailing options given. */
    private function fix(ToolContext $context, string $config, string $cache, string ...$options): ProcessResultDto
    {
        $paths = $context->config->paths;

        return $context->php->withoutXdebug(
            $paths->pharDir . '/php-cs-fixer.phar',
            [
                '--config=' . $config,
                $cache,
                '--allow-risky=yes',
                '--show-progress=dots',
                '--path-mode=intersection',
                '-vvv',
                'fix',
                ...array_values($options),
                ...$context->config->pathsToCheck,
            ],
            $paths->projectRoot,
        );
    }

    /**
     * Whether a writable pass changed a file, from its `Fixed N of M files` summary. Output
     * with no summary counts as a change, so an unexpected format costs an extra pass, never
     * a missed one.
     */
    private function fixedAny(string $output): bool
    {
        return 1 !== \Safe\preg_match(self::NOTHING_FIXED, $output);
    }

    /**
     * The config the fixer runs: a wrapper generated at FINDER_CONFIG under
     * var/qa/ around the resolved php_cs.php, whose finder drops the project's
     * ignored paths and lists each file it drops for an unresolvable real path
     * at UNRESOLVED_LIST (FilteredFinderConfig says why it has to be the finder).
     */
    private function config(ToolContext $context): string
    {
        $context->logDir(\dirname(self::FINDER_CONFIG));
        $wrapper = $context->config->paths->varDir . '/' . self::FINDER_CONFIG;
        \Safe\file_put_contents($wrapper, new FilteredFinderConfig()->source(
            $context->configPath('php_cs.php'),
            IgnoredPaths::of($context->config),
            $this->unresolvedList($context),
        ));

        return $wrapper;
    }

    private function unresolvedList(ToolContext $context): string
    {
        return $context->config->paths->varDir . '/' . self::UNRESOLVED_LIST;
    }

    /** The list a previous run left would otherwise be reported again by this one. */
    private function forgetUnresolved(ToolContext $context): void
    {
        $list = $this->unresolvedList($context);
        if (is_file($list)) {
            \Safe\unlink($list);
        }
    }

    /**
     * One line per file the finder yielded that the fixer could not be given, named
     * relative to the project root, so a skipped file is never silently unchecked.
     */
    private function reportUnresolved(ToolContext $context): void
    {
        $list = $this->unresolvedList($context);
        if (!is_file($list)) {
            return;
        }

        $root  = \Safe\realpath($context->config->paths->projectRoot) . '/';
        $files = array_unique(array_filter(explode("\n", \Safe\file_get_contents($list)), static fn (string $line): bool => '' !== $line));
        foreach ($files as $file) {
            $context->writeln(\sprintf(
                'PHP CS Fixer skipped %s: its real path cannot be resolved (a symlink whose target does not exist), so it was not checked',
                str_starts_with($file, $root) ? substr($file, \strlen($root)) : $file,
            ));
        }
    }
}
