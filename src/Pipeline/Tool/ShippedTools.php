<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool;

use LTS\PHPQA\Pipeline\Lane\BranchNamePolicyTool;
use LTS\PHPQA\Pipeline\Lane\ConfigTemplateIgnoreListTool;
use LTS\PHPQA\Pipeline\Lane\InfectionConfigSourceDirsTool;
use LTS\PHPQA\Pipeline\Lane\MarkdownLinksTool;
use LTS\PHPQA\Pipeline\Lane\PackageTypeTool;
use LTS\PHPQA\Pipeline\Lane\PhpstanIgnoreJustificationTool;
use LTS\PHPQA\Pipeline\Lane\PhpStrictTypesTool;
use LTS\PHPQA\Pipeline\Lane\Psr4ValidateTool;
use LTS\PHPQA\Pipeline\Lane\SensitiveParameterUsageTool;
use LTS\PHPQA\Pipeline\Lane\VersionPinsTool;

/**
 * The lanes php-qa-ci ships, keyed by canonical registry name. A lane is
 * added here as it is ported; the locator refuses a name that is not here.
 *
 * @internal
 */
final readonly class ShippedTools
{
    /** @return array<string, ToolInterface> */
    public static function all(): array
    {
        $tools = [
            new Psr4ValidateTool(),
            new PackageTypeTool(),
            new ConfigTemplateIgnoreListTool(),
            new InfectionConfigSourceDirsTool(),
            new VersionPinsTool(),
            new PhpstanIgnoreJustificationTool(),
            new SensitiveParameterUsageTool(),
            new MarkdownLinksTool(),
            new PhpStrictTypesTool(),
            new BranchNamePolicyTool(),
        ];

        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->name()] = $tool;
        }

        return $byName;
    }
}
