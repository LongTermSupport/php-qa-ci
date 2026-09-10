<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * Rector: automated refactoring in up to four passes, in order: the Safe
 * function conversion over the checked paths, the PHPUnit upgrade over the
 * tests directory, then either every project rector.php (root or qaConfig/)
 * or, when the project has none, the shipped PHP 8.5 config. Rector mutates
 * code; a read-only run passes --dry-run and a pending change fails the lane
 * with remediation guidance, while a writable run applies it. The first
 * failing pass ends the lane.
 *
 * @internal
 */
final readonly class RectorTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.rector';

    private const string RULE      = '------------------------------------------------------------------------------';

    private const int EXIT_PENDING_CHANGES = 2;

    public function name(): string
    {
        return 'rector';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $paths = $context->config->paths;
        $phar  = $paths->pharDir . '/rector.phar';
        if (!is_file($phar)) {
            $context->writeln('ERROR: Rector PHAR not found at ' . $phar);
            $context->writeln('It should be committed under vendor-phar/. Reinstall php-qa-ci, or');
            $context->writeln('(maintainer) rebuild it: scripts/build-rector-phar.bash');
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::crashed('Rector PHAR not found at ' . $phar);
        }

        $checked = $context->config->pathsToCheck;

        $result = $this->pass($context, $phar, 'Safe', $context->configPath('rector-safe.php'), ...$checked);
        if (!$result->isSuccess()) {
            return $result;
        }

        $context->writeln('Running PHPUnit Rector on ' . $paths->testsDir);
        $result = $this->pass($context, $phar, 'PHPUnit', $context->configPath('rector-phpunit.php'), $paths->testsDir);
        if (!$result->isSuccess()) {
            return $result;
        }

        $projectRectorFound = false;
        foreach ([$paths->projectRoot . '/rector.php', $paths->projectRoot . '/qaConfig/rector.php'] as $projectConfig) {
            if (!is_file($projectConfig)) {
                continue;
            }

            $projectRectorFound = true;
            $context->writeln('Running Project Specific Rector as configured in ' . $projectConfig);
            $result = $this->pass($context, $phar, 'Project Specific', $projectConfig, ...$checked);
            if (!$result->isSuccess()) {
                return $result;
            }
        }

        if ($projectRectorFound) {
            $context->writeln('Skipping standard PHP 8.5 Rector as we assume its handled in project rector');
        } else {
            $result = $this->pass($context, $phar, 'PHP 8.5', $context->configPath('rector-php85.php'), ...$checked);
            if (!$result->isSuccess()) {
                return $result;
            }
        }

        if (!$context->config->readOnly) {
            $this->writableAdvisory($context);
        }

        return ToolResultDto::passed();
    }

    /** One Rector config over one or more paths, honouring read-only mode. */
    private function pass(ToolContext $context, string $phar, string $label, string $configFile, string ...$paths): ToolResultDto
    {
        $readOnly    = $context->config->readOnly;
        $projectRoot = $context->config->paths->projectRoot;
        $args        = [
            'process',
            '--autoload-file',
            $projectRoot . '/vendor/autoload.php',
            '--config',
            $configFile,
            '--clear-cache',
        ];
        if ($readOnly) {
            $args[] = '--dry-run';
        }

        // Rector caches under sys_get_temp_dir(), which two projects on one host
        // share; with --clear-cache, concurrent runs delete each other's files.
        // TMPDIR moves it under this project's own cache directory.
        $tmpDir = $context->config->paths->cacheDir . '/rector';
        if (!is_dir($tmpDir)) {
            \Safe\mkdir($tmpDir, 0o777, true);
        }

        $env = ['rectorIgnorePaths' => implode("\n", $context->config->pathsToIgnore), 'TMPDIR' => $tmpDir];

        $context->writeln($readOnly
            ? \sprintf("Running Rector ('%s') in read-only check mode", $label)
            : \sprintf("Running Rector ('%s')", $label));

        $result = $context->php->withoutXdebug($phar, [...$args, ...array_values($paths)], $projectRoot, $env);
        if ($result->succeeded()) {
            return ToolResultDto::passed();
        }

        $toolName = \sprintf("Rector ('%s')", $label);
        if (!$readOnly) {
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::failed(\sprintf('%s failed (exit %d)', $toolName, $result->exitCode));
        }

        if (self::EXIT_PENDING_CHANGES === $result->exitCode) {
            ReadOnlyGuidance::wouldModify($context, $toolName, 'rector');
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::failed(\sprintf('%s has pending changes in a read-only run', $toolName));
        }

        $context->writeln(\sprintf(
            '%s failed with exit code %d (a genuine error, not a pending-change diff) — see output above.',
            $toolName,
            $result->exitCode,
        ));
        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::crashed(\sprintf('%s crashed (exit %d)', $toolName, $result->exitCode));
    }

    private function writableAdvisory(ToolContext $context): void
    {
        $context->writeln('');
        $context->writeln(self::RULE);
        $context->writeln('Rector ran in WRITABLE mode and may have rewritten files. DO NOT FIGHT IT.');
        $context->writeln(self::RULE);
        $context->writeln('Rector is a DETERMINISTIC, CONFIG-DRIVEN autofixer. Every edit it just made is');
        $context->writeln('intentional and part of the standard — treat it as authoritative, not as a');
        $context->writeln('suggestion to negotiate:');
        $context->writeln('');
        $context->writeln('  - Do NOT revert, undo, partially back out, or hand-"tidy" a Rector edit to');
        $context->writeln('    keep a smaller or "cleaner" diff. The change is reproducible from the config,');
        $context->writeln('    so a local revert is simply re-applied on the next run — reverting Rector is');
        $context->writeln('    wasted effort and is forbidden.');
        $context->writeln('  - KEEP every change. When a Rector edit surfaces a DOWNSTREAM error (most');
        $context->writeln('    commonly a PHPStan type error from a newly-precise \Safe\* return type, or');
        $context->writeln('    mutation debt dragged in when a touched file enters the diff-MSI gate), fix');
        $context->writeln('    the CAUSE the honest way — narrow the type, write the missing test — NEVER by');
        $context->writeln('    reverting the Rector edit or suppressing the follow-on check.');
        $context->writeln('  - COMMIT what Rector produced. Prefer an ATOMIC commit of just the autofix');
        $context->writeln('    output (e.g. "style: apply Rector autofixes"), kept separate from your');
        $context->writeln('    feature/bugfix change so the mechanical rewrite is trivial to review.');
        $context->writeln('');
        $context->writeln('Roll with Rector. It is part of the gate, not an obstacle to it.');
        $context->writeln(self::RULE);
        $context->writeln('');
    }
}
