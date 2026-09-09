<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * The other direction of the dependency check: composerRequireChecker finds
 * symbols with no declared package, this finds declared packages with no
 * symbols, plus shadow dependencies and dev packages used in production code.
 * Runs the Composer-installed binary with the resolved
 * composer-dependency-analyser.php.
 *
 * The tool exits 1 both for findings and for its own errors, so the lane
 * cannot tell them apart and reports every non-zero exit as a failure; the
 * guidance says how to recognise the difference in the output.
 *
 * @internal
 */
final readonly class ComposerDependencyAnalyserTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.composerDependencyAnalyser';

    public const string CONFIG = 'composer-dependency-analyser.php';

    private const string BINARY = 'composer-dependency-analyser';

    public function name(): string
    {
        return 'composerDependencyAnalyser';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $paths  = $context->config->paths;
        $binary = $paths->binDir . '/' . self::BINARY;
        if (!is_file($binary)) {
            $this->notInstalledGuidance($context, $binary);
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::failed(self::BINARY . ' is not installed');
        }

        $result = $context->php->withoutXdebug(
            $binary,
            ['--config=' . $context->configPath(self::CONFIG), '--composer-json=' . $paths->projectRoot . '/composer.json'],
            $paths->projectRoot,
        );

        if ($result->succeeded()) {
            return ToolResultDto::passed();
        }

        $this->guidance($context);
        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed(\sprintf('composer-dependency-analyser reported problems (exit %d)', $result->exitCode));
    }

    private function notInstalledGuidance(ToolContext $context, string $binary): void
    {
        $context->writeln('');
        $context->writeln('ERROR: composer-dependency-analyser was not found at ' . $binary);
        $context->writeln('');
        $context->writeln('It is a "require" dependency of php-qa-ci, so it should always be present.');
        $context->writeln('A missing binary means the install is incomplete rather than the project');
        $context->writeln('being misconfigured.');
        $context->writeln('');
        $context->writeln('TO FIX: composer install');
    }

    private function guidance(ToolContext $context): void
    {
        $context->writeln('');
        $context->writeln('');
        $context->writeln('HOW TO FIX');
        $context->writeln('----------');
        $context->writeln('The tool exits 1 for findings AND for its own errors, so read the output above');
        $context->writeln('first: a red "Error:" line means it could not run (a bad config path, an');
        $context->writeln('unreadable composer.json) and nothing was analysed.');
        $context->writeln('');
        $context->writeln('Otherwise each finding has its own fix:');
        $context->writeln('');
        $context->writeln('  UNUSED DEPENDENCY — declared in composer.json, used nowhere in the scanned');
        $context->writeln('  paths. Remove it. If it is used at run time in a way no static scan can see');
        $context->writeln('  (a plugin, a binary you invoke, an extension a tool needs), keep it and say so');
        $context->writeln('  with ->ignoreErrorsOnPackage() in your config, which records the reason in');
        $context->writeln('  version control instead of leaving the package unexplained.');
        $context->writeln('');
        $context->writeln('  SHADOW DEPENDENCY — used in your code but only installed because something');
        $context->writeln('  else depends on it. Require it explicitly; the package that pulls it in today');
        $context->writeln('  is free to drop it tomorrow.');
        $context->writeln('');
        $context->writeln('  DEV DEPENDENCY IN PROD — production code uses a require-dev package. A');
        $context->writeln('  --no-dev install will not have it. Move the package to require, or move the');
        $context->writeln('  usage out of production code.');
        $context->writeln('');
        $context->writeln('  PROD DEPENDENCY ONLY IN DEV — required for production but only ever used by');
        $context->writeln('  tests. Move it to require-dev so a production install stops carrying it.');
        $context->writeln('');
        $context->writeln('  UNKNOWN CLASS / FUNCTION — the symbol could not be autoloaded, so its package');
        $context->writeln('  could not be determined and it was NOT checked. Classes supplied by a PHAR');
        $context->writeln('  (PHPStan, PHPArkitect) land here legitimately: list them with');
        $context->writeln('  ->ignoreUnknownClassesRegex() rather than leaving the check blind.');
        $context->writeln('');
        $context->writeln('Configuration goes in qaConfig/' . self::CONFIG . ', which replaces the');
        $context->writeln('shipped default entirely.');
    }
}
