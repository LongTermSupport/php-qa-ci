<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\ConfigTemplateIgnoreList\ConfigTemplateIgnoreListCheck;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * php-qa-ci's own shipped configDefaults/generic/ templates must all be
 * covered by psr4-validate-ignore-list.txt, or the documented copy-override
 * fails psr4Validate in a consuming project.
 *
 * @internal
 */
final readonly class ConfigTemplateIgnoreListTool implements ToolInterface
{
    public function name(): string
    {
        return 'configTemplateIgnoreList';
    }

    public function identifier(): string
    {
        return ConfigTemplateIgnoreListCheck::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $exit = OutputCapture::run(static fn (): int => ConfigTemplateIgnoreListCheck::main(), $context->output);

        return 0 === $exit ? ToolResultDto::passed() : ToolResultDto::failed('a shipped config template is not covered by the PSR-4 ignore list');
    }
}
