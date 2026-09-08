<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * Symfony only: `bin/console lint:yaml --parse-tags` over the configured yaml
 * directories that exist. lint:yaml errors on a missing path, so the list is
 * filtered first and the lane skips cleanly when nothing is left: a project
 * without a config/ dir has nothing to lint. Any non-zero exit of the linter
 * is a failure.
 *
 * @internal
 */
final readonly class YamlLintTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.yamlLint';

    private const string CONSOLE = 'bin/console';

    public function name(): string
    {
        return 'yamlLint';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        if (PlatformEnum::Symfony !== $context->config->platform) {
            return ToolResultDto::skipped('not a Symfony project');
        }

        $configured = $context->config->yamlDirectories;
        $existing   = array_values(array_filter($configured, is_dir(...)));
        if ([] === $existing) {
            $context->writeln(\sprintf(
                'Yaml Lint: none of the configured YAML directories exist (checked: %s) — skipping.',
                implode(' ', $configured),
            ));

            return ToolResultDto::skipped('no configured YAML directory exists');
        }

        $result = $context->php->withoutXdebug(
            self::CONSOLE,
            ['lint:yaml', '--parse-tags', ...$existing],
            $context->config->paths->projectRoot,
        );

        if ($result->succeeded()) {
            return ToolResultDto::passed();
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed(\sprintf('Yaml Lint failed (exit %d)', $result->exitCode));
    }
}
