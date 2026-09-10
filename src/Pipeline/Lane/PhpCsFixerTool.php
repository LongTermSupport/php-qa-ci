<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * PHP CS Fixer over the checked paths with the resolved php_cs.php config.
 * The fixer mutates code; a read-only run passes --dry-run and a pending fix
 * fails the lane with remediation guidance, while a writable run applies it.
 * The output is also written to var/qa/php-cs-fixer-output.log. A file the
 * fixer could not process at all (a parse failure) is fatal in either mode.
 *
 * @internal
 */
final readonly class PhpCsFixerTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.phpCsFixer';

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
            '--config=' . $context->configPath('php_cs.php'),
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
}
