<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * Symfony only: coding standards for Twig templates, which PHP CS Fixer does
 * not read. Runs the shipped twig-cs-fixer PHAR over the configured twig
 * directories — check-only in a read-only run, where a pending fix fails the
 * gate, and `--fix` in a writable one.
 *
 * `--fix` still exits 1 when a violation it cannot fix remains, so a writable
 * run distinguishes "fixed everything" from "fixed what it could": the former
 * passes, the latter fails with the remainder on screen. Exit 2 means a config
 * error or an unhandled throwable, which is a crash rather than a finding.
 *
 * @internal
 */
final readonly class TwigCsFixerTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.twigCsFixer';

    public const string CONFIG = '.twig-cs-fixer.php';

    private const string PHAR = 'twig-cs-fixer.phar';

    private const int EXIT_CRASH = 2;

    public function name(): string
    {
        return 'twigCsFixer';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $config = $context->config;
        if (PlatformEnum::Symfony !== $config->platform) {
            return ToolResultDto::skipped('not a Symfony project');
        }

        $directories = $this->existingDirectories($context);
        if ([] === $directories) {
            $context->writeln('No twig directories present, nothing to do');

            return ToolResultDto::skipped('no twig directories');
        }

        $args = ['lint', '--config=' . $context->configPath(self::CONFIG), ...$directories];
        if (!$config->readOnly) {
            $args[] = '--fix';
        }

        $result = $context->php->withoutXdebug(
            $config->paths->pharDir . '/' . self::PHAR,
            $args,
            $config->paths->projectRoot,
        );

        if ($result->succeeded()) {
            return ToolResultDto::passed();
        }

        if (self::EXIT_CRASH <= $result->exitCode) {
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::crashed(\sprintf('twig-cs-fixer could not run (exit %d)', $result->exitCode));
        }

        if ($config->readOnly) {
            ReadOnlyGuidance::wouldModify($context, 'Twig CS Fixer', 'twigcs');
        } else {
            $this->unfixableGuidance($context);
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed('Twig CS Fixer reported violations');
    }

    /**
     * The configured directories are absolute. A directory that does not exist
     * is dropped rather than passed through, because twig-cs-fixer treats an
     * unreadable path as a fatal error and Symfony's own defaults name paths a
     * given project may simply not have.
     *
     * @return list<string>
     */
    private function existingDirectories(ToolContext $context): array
    {
        return array_values(array_filter($context->config->twigDirectories, is_dir(...)));
    }

    private function unfixableGuidance(ToolContext $context): void
    {
        $context->writeln('');
        $context->writeln('Twig CS Fixer applied every fix it could and violations remain, so these');
        $context->writeln('need a human. It exits non-zero whenever anything is left, including after');
        $context->writeln('a successful --fix run.');
        $context->writeln('');
        $context->writeln('TO FIX: correct the templates listed above by hand, then re-run:');
        $context->writeln('');
        $context->writeln('    vendor/bin/qa -t twigcs');
        $context->writeln('');
        $context->writeln('To change which rules apply, copy the shipped config to');
        $context->writeln('qaConfig/' . self::CONFIG . ' and edit the ruleset there.');
    }
}
