<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Composer hygiene, in four steps: `composer diagnose` (informational, never
 * fails), the ergebnis/composer-normalize plugin must be allowed, the
 * composer.json must be normalised (a read-only run dry-runs and fails on a
 * pending change; a writable run applies it), and the autoloader is dumped.
 * The dump only regenerates files under vendor/, so it runs in every mode.
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

        $allowed = $context->php->withoutXdebug($composer, ['config', 'allow-plugins.ergebnis/composer-normalize'], $root, streamOutput: false);
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
