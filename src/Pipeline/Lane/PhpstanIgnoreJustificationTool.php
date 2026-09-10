<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\ProjectRecord\IgnoreErrorsJustificationCheck;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * Every ignoreErrors entry in qaConfig/phpstan.neon must carry a comment
 * naming the hazard accepted and its scope: the project record of its
 * exceptions, readable without re-running the audit.
 *
 * @internal
 */
final readonly class PhpstanIgnoreJustificationTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.phpstanIgnoreJustification';

    public function __construct(private IgnoreErrorsJustificationCheck $check = new IgnoreErrorsJustificationCheck())
    {
    }

    public function name(): string
    {
        return 'phpstanIgnoreJustification';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $root = $context->config->paths->projectRoot;
        $exit = OutputCapture::run(fn (): int => $this->check->run($root), $context->output);
        if (0 === $exit) {
            return ToolResultDto::passed();
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed('an ignoreErrors entry has no usable justification');
    }
}
