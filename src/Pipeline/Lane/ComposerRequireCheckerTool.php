<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * Every symbol production code uses must come from a package composer.json
 * declares in `require`. Runs the shipped composer-require-checker PHAR with
 * the resolved composerRequireChecker.json; a failure prints the HOW TO FIX
 * guidance, including why a dev-dependency symbol must never be whitelisted.
 *
 * @internal
 */
final readonly class ComposerRequireCheckerTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.composerRequireChecker';

    public function name(): string
    {
        return 'composerRequireChecker';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $paths  = $context->config->paths;
        $result = $context->php->withoutXdebug(
            $paths->pharDir . '/composer-require-checker.phar',
            ['check', '--config-file=' . $context->configPath('composerRequireChecker.json'), '--', $paths->projectRoot . '/composer.json'],
            $paths->projectRoot,
        );

        if ($result->succeeded()) {
            return ToolResultDto::passed();
        }

        $this->guidance($context);
        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed(\sprintf('Composer Require Checker failed (exit %d)', $result->exitCode));
    }

    private function guidance(ToolContext $context): void
    {
        $context->writeln('');
        $context->writeln('');
        $context->writeln("To fix these issues, you probably need to add things to your 'require' section in your projects composer.json");
        $context->writeln('');
        $context->writeln("You might do this by moving things from 'require-dev', or it could be things that are brought in by your dependencies that you need to add.");
        $context->writeln('');
        $context->writeln("The ones that say 'ext-json' or similar, you just need to add '\"ext-json\": \"*\"'");
        $context->writeln('');
        $context->writeln('Of course the other option is that you refactor your code and stop using your dev dependencies in your production code');
        $context->writeln('');
        $context->writeln('NOTE - Safe php - special case - you need to modify the scan-files section and add in files as required.');
        $context->writeln('');
        $context->writeln('');
        $context->writeln('HOW TO FIX');
        $context->writeln('----------');
        $context->writeln("1. Add the package to your 'require' section in composer.json (not require-dev).");
        $context->writeln('   If the package is currently in require-dev, either move it to require or stop');
        $context->writeln('   using it in production code (src/).');
        $context->writeln('');
        $context->writeln('2. For PHP extensions (ext-json, ext-mbstring, etc.):');
        $context->writeln('   composer require ext-json:"*"');
        $context->writeln('');
        $context->writeln('3. For Safe functions (thecodingmachine/safe):');
        $context->writeln("   Add the generated file paths to the 'scan-files' section in your");
        $context->writeln('   qaConfig/composerRequireChecker.json override.');
        $context->writeln('');
        $context->writeln('⚠️  WARNING — DO NOT ADD THESE SYMBOLS TO THE WHITELIST');
        $context->writeln('--------------------------------------------------------');
        $context->writeln('Never add symbols from dev-dependency packages to the symbol-whitelist.');
        $context->writeln('The whitelist exists for language built-ins and unavoidable edge cases only.');
        $context->writeln('');
        $context->writeln("If a symbol's package is in require-dev but your production code (src/) uses it,");
        $context->writeln('adding it to the whitelist silently hides a real problem:');
        $context->writeln('');
        $context->writeln('  • In a production (non-dev) install, Composer does NOT install require-dev packages.');
        $context->writeln('  • Transitive dependencies of require-dev packages are also absent.');
        $context->writeln('  • Your application will crash at runtime with class-not-found errors.');
        $context->writeln('');
        $context->writeln('The correct fix is always one of:');
        $context->writeln('  a) Move the package from require-dev to require   (it IS a runtime dependency)');
        $context->writeln('  b) Remove the usage from production code          (it should NOT be a runtime dependency)');
        $context->writeln('');
        $context->writeln('There is no scenario where whitelisting a dev-dependency symbol is the right answer.');
    }
}
