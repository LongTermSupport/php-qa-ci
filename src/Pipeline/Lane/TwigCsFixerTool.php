<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Lane\TwigCsFixer\CacheFileConfig;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * Coding standards for Twig templates, which PHP CS Fixer does not read. Runs
 * the shipped twig-cs-fixer PHAR over the configured twig directories —
 * check-only in a read-only run, where a pending fix fails the gate, and
 * `--fix` in a writable one.
 *
 * A read-only run passes `--no-cache`, so the gate judges every template as CI does (#237),
 * and a writable run keeps its cache under var/qa/cache, not in the project root where
 * twig-cs-fixer would put it. One writable pass is enough: the fixer repeats its rules until
 * a pass changes nothing, and caches a file only when a lint of what it left is clean.
 *
 * Gated on Twig, not on Symfony. The PHAR is standalone and needs no framework,
 * so the lane belongs to any project that has `twig/twig` in its vendor tree —
 * Slim, Laravel, a static site generator, or a library that ships templates.
 * Twig Lint is the opposite case and is still a Symfony platform lane, because
 * it invokes `bin/console lint:twig`.
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

    public const string CACHED_CONFIG = 'twigCsFixer/' . self::CONFIG;

    private const string CACHE = 'twig-cs-fixer.cache';

    private const string NO_CACHE = '--no-cache';

    private const string PHAR = 'twig-cs-fixer.phar';

    private const string TWIG_PACKAGE = 'vendor/twig/twig';

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
        if (!is_dir($config->paths->projectRoot . '/' . self::TWIG_PACKAGE)) {
            $context->writeln('Twig is not installed, nothing to do');

            return ToolResultDto::skipped('twig/twig not installed');
        }

        $directories = array_values(array_filter(OwnTwigDirectories::declared($context), is_dir(...)));
        if ([] === $directories) {
            OwnTwigDirectories::announceSkip($context);

            return ToolResultDto::skipped(OwnTwigDirectories::SKIP_REASON);
        }

        $cache = FormatterCache::in($context, self::CACHE);
        $args  = $config->readOnly
            ? ['lint', '--config=' . $context->configPath(self::CONFIG), self::NO_CACHE, ...$directories]
            : ['lint', '--config=' . $this->cachedConfig($context, $cache), ...$directories, '--fix'];

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

            return ToolResultDto::crashed(\sprintf('twig-cs-fixer could not run (%s)', $result->describeExit()));
        }

        if ($config->readOnly) {
            $cache->forget();
            ReadOnlyGuidance::wouldModify($context, 'Twig CS Fixer', 'twigcs');
        } else {
            $this->unfixableGuidance($context);
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed('Twig CS Fixer reported violations');
    }

    /**
     * The config a writable run uses: the resolved .twig-cs-fixer.php, wrapped by a config
     * generated at CACHED_CONFIG under var/qa/ that keeps its cache there too
     * (CacheFileConfig says why it has to be the config).
     */
    private function cachedConfig(ToolContext $context, FormatterCache $cache): string
    {
        $context->logDir(\dirname(self::CACHED_CONFIG));
        $wrapper = $context->config->paths->varDir . '/' . self::CACHED_CONFIG;
        \Safe\file_put_contents($wrapper, new CacheFileConfig()->source($context->configPath(self::CONFIG), $cache->prepared()));

        return $wrapper;
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
