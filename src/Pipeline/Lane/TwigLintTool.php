<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * Symfony only: `bin/console lint:twig` over the configured twig directories.
 * Skips when the project is not Symfony, when bin/console does not list a
 * lint:twig command, or when symfony/twig-bundle is not installed. Any
 * non-zero exit of the linter is a failure.
 *
 * @internal
 */
final readonly class TwigLintTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.twigLint';

    private const string CONSOLE = 'bin/console';

    public function name(): string
    {
        return 'twigLint';
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

        $root    = $context->config->paths->projectRoot;
        $listing = $context->php->withoutXdebug(self::CONSOLE, [], $root, streamOutput: false);
        if (!str_contains($listing->output, 'lint:twig')) {
            $context->writeln('Twig Lint not found in bin/console, skipping');

            return ToolResultDto::skipped('lint:twig not found in bin/console');
        }

        if (!is_dir($root . '/vendor/symfony/twig-bundle')) {
            $context->writeln('Twig Not Installed, nothing to do');

            return ToolResultDto::skipped('symfony/twig-bundle not installed');
        }

        $result = $context->php->withoutXdebug(
            self::CONSOLE,
            ['lint:twig', ...$context->config->twigDirectories],
            $root,
        );

        if ($result->succeeded()) {
            return ToolResultDto::passed();
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed(\sprintf('Twig Lint failed (exit %d)', $result->exitCode));
    }
}
