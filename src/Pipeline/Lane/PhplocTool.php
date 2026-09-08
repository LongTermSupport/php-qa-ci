<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * Code-size statistics over the checked paths, printed after the gate has
 * passed. Purely informational: it is skipped when the project has no phploc
 * binary and it never fails the run, whatever phploc's exit code.
 *
 * @internal
 */
final readonly class PhplocTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.phploc';

    public function name(): string
    {
        return 'phploc';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $paths  = $context->config->paths;
        $phploc = $paths->binDir . '/phploc';
        if (!is_file($phploc)) {
            return ToolResultDto::skipped('phploc not installed');
        }

        $context->php->withoutXdebug($phploc, $context->config->pathsToCheck, $paths->projectRoot);

        return ToolResultDto::passed();
    }
}
