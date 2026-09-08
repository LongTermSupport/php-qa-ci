<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * Architectural rules (class naming, namespace layering, dependency
 * direction) via the shipped phparkitect.phar. The paths to scan live inside
 * the entry config, so the resolved rule tiers, the detected source dir and
 * any project-declared exclude paths are handed to it through the
 * environment rather than argv. Exit 1 means rule violations; anything above
 * is a crash (bad config, unparseable file) that retrying cannot fix.
 *
 * @internal
 */
final readonly class PhpArkitectTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.phpArkitect';

    public const string LOG_DIR = 'phparkitect_logs';

    public const string LOG_FILE = 'phparkitect.log';

    public function name(): string
    {
        return 'phpArkitect';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $config = $context->config;
        if (!$config->useArkitect) {
            $context->writeln('PHPArkitect: disabled (useArkitect=0) — skipping.');

            return ToolResultDto::skipped('useArkitect=0');
        }

        $entryConfig = $context->configPath('phparkitect.php');
        if (!is_file($entryConfig)) {
            $context->writeln(\sprintf('PHPArkitect: no entry config resolved at %s — skipping.', $entryConfig));

            return ToolResultDto::skipped('no entry config');
        }

        $context->writeln('PHPArkitect: using config ' . $entryConfig);

        $paths  = $config->paths;
        $logDir = $context->logDir(self::LOG_DIR);
        $result = $context->php->withoutXdebug(
            $paths->pharDir . '/phparkitect.phar',
            [
                'check',
                '--config=' . $entryConfig,
                '--autoload=' . $paths->projectRoot . '/vendor/autoload.php',
                '--no-interaction',
            ],
            $paths->projectRoot,
            $this->environment($context),
        );

        \Safe\file_put_contents($logDir . '/' . self::LOG_FILE, $result->output);
        $context->logs->archive('PHPArkitect', $logDir, self::LOG_FILE, null !== $config->specifiedPath, $config->pathsToCheck);

        if ($result->succeeded()) {
            return ToolResultDto::passed();
        }

        if ($result->exitCode > 1) {
            $context->writeln('');
            $context->writeln(\sprintf('PHPArkitect crashed (exit %d) — this is an ERROR, not a rule violation.', $result->exitCode));
            $context->writeln('Likely causes: an invalid phparkitect config, an unparseable source file, or a bad');
            $context->writeln('ClassSet path. Inspect the output above; retrying will not help.');
            $context->writeln("NOTE: an INCOMPLETE autoloader does NOT crash — instead the optional/symfony tiers'");
            $context->writeln('ancestry rules (IsA) silently match nothing (a false green). If those rules seem to');
            $context->writeln("find no violations, make your classes autoloadable: run 'composer dump-autoload'.");
            $context->writeln('');

            return ToolResultDto::crashed(\sprintf('PHPArkitect crashed (exit %d)', $result->exitCode));
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed('PHPArkitect found rule violations');
    }

    /**
     * What the entry config reads: the detected source dir, the resolved rule
     * tiers (each honouring a qaConfig/ override), the consumer API-boundary
     * factory and the project's extra exclude paths, newline-delimited.
     *
     * @return array<string, string>
     */
    private function environment(ToolContext $context): array
    {
        return [
            'PHPQACI_ARKITECT_SRC_DIR'                => $context->config->paths->srcDir,
            'PHPQACI_ARKITECT_RULES_DEFAULT'          => $context->configPath('phparkitect-rules-default.php'),
            'PHPQACI_ARKITECT_RULES_OPTIONAL'         => $context->configPath('phparkitect-rules-optional.php'),
            'PHPQACI_ARKITECT_RULES_OPTIONAL_SYMFONY' => $context->configPath('phparkitect-rules-optional-symfony.php'),
            'PHPQACI_ARKITECT_CONSUMER_API_BOUNDARY'  => $context->configPath('phparkitect-consumer-api-boundary.php'),
            'PHPQACI_ARKITECT_EXCLUDE_PATHS'          => implode("\n", $context->config->arkitectExcludePaths),
        ];
    }
}
