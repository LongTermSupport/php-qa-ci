<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * Fast parallel syntax check of every checked path via the project's
 * parallel-lint binary. Each ignored path is passed as an --exclude, resolved
 * against the project root. Any non-zero exit is a lint failure.
 *
 * @internal
 */
final readonly class PhpLintTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.phpLint';

    public function name(): string
    {
        return 'phpLint';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $paths = $context->config->paths;
        $args  = [];
        foreach ($context->config->pathsToIgnore as $ignore) {
            $args[] = '--exclude';
            $args[] = $paths->projectRoot . '/' . $ignore;
        }

        $result = $context->php->withoutXdebug(
            $paths->binDir . '/parallel-lint',
            [...$args, ...$context->config->pathsToCheck],
            $paths->projectRoot,
        );

        if ($result->succeeded()) {
            return ToolResultDto::passed();
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed(\sprintf('PHP Lint failed (exit %d)', $result->exitCode));
    }
}
