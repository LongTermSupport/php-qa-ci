<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\Helper;
use LTS\PHPQA\PackageType\ExplicitPackageTypeCheck;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * composer.json must declare an explicit `type`; Composer's silent default
 * of `library` decides app-versus-library without anyone choosing.
 *
 * @internal
 */
final readonly class PackageTypeTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.packageType';

    public function __construct(private ExplicitPackageTypeCheck $check = new ExplicitPackageTypeCheck())
    {
    }

    public function name(): string
    {
        return 'packageType';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $composer = Helper::getComposerJsonDecoded($context->config->paths->projectRoot . '/composer.json');
        $exit     = OutputCapture::run(fn (): int => $this->check->run($composer), $context->output);
        if (0 === $exit) {
            return ToolResultDto::passed();
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed('composer.json does not declare an explicit package type');
    }
}
