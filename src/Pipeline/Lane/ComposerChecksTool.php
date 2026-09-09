<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Composer hygiene, in five steps: `composer diagnose` (informational, never
 * fails), `composer audit` against the lock file (a known advisory fails the
 * lane; abandoned packages are reported; off with withComposerAudit(false)),
 * the ergebnis/composer-normalize plugin must be allowed, the composer.json
 * must be normalised (a read-only run dry-runs and fails on a pending change;
 * a writable run applies it), and the autoloader is dumped. The dump only
 * regenerates files under vendor/, so it runs in every mode.
 *
 * @internal
 */
final readonly class ComposerChecksTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.composerChecks';

    public function __construct(
        /** The composer executable; found on PATH when null. */
        private ?string $composerPath = null,
    ) {
    }

    public function name(): string
    {
        return 'composerChecks';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $root     = $context->config->paths->projectRoot;
        $composer = $this->composer();

        $context->writeln('');
        $context->writeln('');
        $context->writeln('Checking Composer Issues - Fix Any Red Stuff (wont fail the process)');
        $context->writeln('---------------------');
        $context->writeln('');
        if (!$context->php->withoutXdebug($composer, ['diagnose'], $root)->succeeded()) {
            $context->writeln('composer diagnose reported issues (shown above) — informational only, not failing the pipeline.');
        }

        $context->writeln('');
        $context->writeln('');
        $context->writeln('Auditing Dependencies For Known Advisories');
        $context->writeln('---------------------');
        $context->writeln('');
        if ($context->config->useComposerAudit) {
            $audit = $context->php->withoutXdebug($composer, ['audit', '--locked', '--abandoned=report', '--no-interaction'], $root);
            if (!$audit->succeeded()) {
                $this->auditFailedGuidance($context);
                $context->writeIdentifier(self::IDENTIFIER);

                return ToolResultDto::failed(\sprintf('composer audit found advisories or could not run (exit %d)', $audit->exitCode));
            }
        } else {
            $context->writeln('composer audit disabled for this project (withComposerAudit(false) / useComposerAudit=0) — skipping.');
        }

        $allowed =$context->php->withoutXdebug($composer, ['config', 'allow-plugins.ergebnis/composer-normalize'], $root, streamOutput: false);
        if ('true' !== trim($allowed->output)) {
            $this->pluginNotAllowedGuidance($context);
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::failed('the ergebnis/composer-normalize plugin is not allowed in composer.json');
        }

        if ($context->config->readOnly) {
            $context->writeln('');
            $context->writeln('');
            $context->writeln('Checking Composer Normalisation (read-only)');
            $context->writeln('---------------------');
            $context->writeln('');
            $normalize = $context->php->withoutXdebug($composer, ['normalize', '--dry-run'], $root);
            if (!$normalize->succeeded()) {
                ReadOnlyGuidance::wouldModify($context, 'Composer Normalize', 'com');
                $context->writeIdentifier(self::IDENTIFIER);

                return ToolResultDto::failed('composer.json is not normalised (read-only run)');
            }
        } else {
            $context->writeln('');
            $context->writeln('');
            $context->writeln('Running Composer Normalise');
            $context->writeln('---------------------');
            $context->writeln('');
            $normalize = $context->php->withoutXdebug($composer, ['normalize'], $root);
            if (!$normalize->succeeded()) {
                $context->writeIdentifier(self::IDENTIFIER);

                return ToolResultDto::failed(\sprintf('composer normalize failed (exit %d)', $normalize->exitCode));
            }
        }

        $context->writeln('');
        $context->writeln('');
        $context->writeln('Dumping Composer Autoloader');
        $context->writeln('---------------------');
        $context->writeln('');

        $dump = $context->php->withoutXdebug($composer, ['dump-autoload'], $root);
        if (!$dump->succeeded()) {
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::failed(\sprintf('composer dump-autoload failed (exit %d)', $dump->exitCode));
        }

        return ToolResultDto::passed();
    }

    private function composer(): string
    {
        if (null !== $this->composerPath) {
            return $this->composerPath;
        }

        $found = new ExecutableFinder()->find('composer', 'composer');

        return null === $found ? 'composer' : $found;
    }

    private function auditFailedGuidance(ToolContext $context): void
    {
        $context->writeln('');
        $context->writeln('composer audit did not pass (output above). Either a locked dependency has a');
        $context->writeln('published security advisory, or the advisory database could not be reached.');
        $context->writeln('');
        $context->writeln('TO FIX');
        $context->writeln('  - Update the affected package to a patched release and commit composer.lock:');
        $context->writeln('        composer update <vendor/package> --with-all-dependencies');
        $context->writeln('  - If no patched release exists yet, pin an explicit exception in composer.json');
        $context->writeln('    under config.audit.ignore with the advisory id and a comment saying why.');
        $context->writeln('  - An abandoned package is reported, not failed: plan its replacement.');
        $context->writeln('  - Offline builds: ->withComposerAudit(false) in qaConfig/qa.php, or');
        $context->writeln('    useComposerAudit=0 for one run.');
    }

    private function pluginNotAllowedGuidance(ToolContext $context): void
    {
        $context->writeln('');
        $context->writeln('ERROR: The ergebnis/composer-normalize plugin is not allowed in your composer.json');
        $context->writeln('');
        $context->writeln('To fix this, add the following to your composer.json config section:');
        $context->writeln('');
        $context->writeln('    "config": {');
        $context->writeln('        "allow-plugins": {');
        $context->writeln('            ...');
        $context->writeln('            "ergebnis/composer-normalize": true');
        $context->writeln('        }');
        $context->writeln('    }');
        $context->writeln('');
        $context->writeln('Then run: composer update nothing');
        $context->writeln('');
        $context->writeln('This is required for the PHP QA pipeline to run properly.');
    }
}
