<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Config\IgnoredPaths;
use LTS\PHPQA\Pipeline\Lane\PhpCsFixer\IgnoredPathsConfig;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * PHP CS Fixer over the checked paths with the resolved php_cs.php config,
 * less the project's ignored paths. The fixer mutates code; a read-only run
 * passes --dry-run and a pending fix fails the lane with remediation
 * guidance, while a writable run applies it.
 * The output is also written to var/qa/php-cs-fixer-output.log. A file the
 * fixer could not process at all (a parse failure) is fatal in either mode.
 *
 * @internal
 */
final readonly class PhpCsFixerTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.phpCsFixer';

    public const string IGNORED_PATHS_CONFIG = 'phpCsFixer/php_cs.php';

    private const int EXIT_PENDING_FIXES = 8;

    private const string LINT_ERROR_MARKER = 'Files that were not fixed due to errors';

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
        $args     = [
            '--config=' . $this->config($context),
            '--cache-file=' . $paths->varDir . '/cache/php_cs.cache',
            '--allow-risky=yes',
            '--show-progress=dots',
            '--path-mode=intersection',
            '-vvv',
            'fix',
        ];
        if ($readOnly) {
            $context->writeln('Running PHP CS Fixer in read-only check mode');
            $args[] = '--dry-run';
        }

        $result = $context->php->withoutXdebug(
            $paths->pharDir . '/php-cs-fixer.phar',
            [...$args, ...$context->config->pathsToCheck],
            $paths->projectRoot,
        );

        if (!is_dir($paths->varDir)) {
            \Safe\mkdir($paths->varDir, 0o777, true);
        }

        \Safe\file_put_contents($paths->varDir . '/php-cs-fixer-output.log', $result->output);

        if (str_contains($result->output, self::LINT_ERROR_MARKER)) {
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

    /**
     * The config the fixer runs: the resolved php_cs.php itself, or, when the
     * project ignores paths, a wrapper generated at IGNORED_PATHS_CONFIG under
     * var/qa/ that takes them out of its finder (IgnoredPathsConfig says why
     * it has to be the finder).
     */
    private function config(ToolContext $context): string
    {
        $resolved = $context->configPath('php_cs.php');
        $ignored  = IgnoredPaths::of($context->config);
        if ($ignored->isEmpty()) {
            return $resolved;
        }

        $context->logDir(\dirname(self::IGNORED_PATHS_CONFIG));
        $wrapper = $context->config->paths->varDir . '/' . self::IGNORED_PATHS_CONFIG;
        \Safe\file_put_contents($wrapper, new IgnoredPathsConfig()->source($resolved, $ignored));

        return $wrapper;
    }
}
