<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * Every YAML file under the configured yaml directories parses, via the
 * standalone `yaml-lint` script that symfony/yaml ships. `--parse-tags`
 * accepts the custom tags Symfony config relies on and is harmless elsewhere.
 *
 * Gated on the libraries, not on the platform: the script needs symfony/yaml
 * (its home) and symfony/console (its runner), so the lane belongs to any
 * project that has both and skips cleanly otherwise. yaml-lint errors on a
 * missing path, so the directory list is filtered first and the lane skips
 * when nothing is left. Any non-zero exit of the linter is a failure.
 *
 * Twig Lint is the contrasting case and stays a Symfony platform lane:
 * `lint:twig` needs the application's own Twig environment.
 *
 * @internal
 */
final readonly class YamlLintTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.yamlLint';

    public const string YAML_LINT = 'vendor/symfony/yaml/Resources/bin/yaml-lint';

    public const string CONSOLE_PACKAGE = 'vendor/symfony/console';

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
        $root = $context->config->paths->projectRoot;
        if (!is_file($root . '/' . self::YAML_LINT)) {
            $context->writeln('symfony/yaml is not installed, nothing to do');

            return ToolResultDto::skipped('symfony/yaml not installed');
        }

        if (!is_dir($root . '/' . self::CONSOLE_PACKAGE)) {
            $context->writeln('symfony/console is not installed and yaml-lint needs it, nothing to do');

            return ToolResultDto::skipped('symfony/console not installed');
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
            self::YAML_LINT,
            ['--parse-tags', ...$existing],
            $root,
        );

        if ($result->succeeded()) {
            return ToolResultDto::passed();
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed(\sprintf('Yaml Lint failed (exit %d)', $result->exitCode));
    }
}
