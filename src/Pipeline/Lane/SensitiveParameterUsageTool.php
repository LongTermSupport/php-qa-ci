<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use LTS\PHPQA\SensitiveParameter\SensitiveParameterUsageScanner;

/**
 * #[\SensitiveParameter] must be used somewhere in src/: the estate-wide
 * baseline a PHPStan rule cannot give, because rules are opt-in. A project
 * that genuinely handles no secrets opts out with useSensitiveParameterCheck=0
 * (or QaConfigBuilder::withSensitiveParameterCheck(false)).
 *
 * @internal
 */
final readonly class SensitiveParameterUsageTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.sensitiveParameterUsage';

    public function name(): string
    {
        return 'sensitiveParameterUsage';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $root    = $context->config->paths->projectRoot;
        $enabled = $context->config->useSensitiveParameterCheck;
        $exit    = OutputCapture::run(static fn (): int => SensitiveParameterUsageScanner::main($root, $enabled), $context->output);
        if (!$enabled) {
            return ToolResultDto::skipped('SensitiveParameter usage check disabled for this project');
        }

        if (0 === $exit) {
            return ToolResultDto::passed();
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed('#[\SensitiveParameter] is used nowhere in src/');
    }
}
