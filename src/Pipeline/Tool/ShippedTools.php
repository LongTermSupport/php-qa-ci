<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool;

use LTS\PHPQA\Pipeline\Lane\AnalysedPathsTool;
use LTS\PHPQA\Pipeline\Lane\BranchNamePolicyTool;
use LTS\PHPQA\Pipeline\Lane\ChangelogTool;
use LTS\PHPQA\Pipeline\Lane\ComposerChecksTool;
use LTS\PHPQA\Pipeline\Lane\ComposerDependencyAnalyserTool;
use LTS\PHPQA\Pipeline\Lane\ComposerRequireCheckerTool;
use LTS\PHPQA\Pipeline\Lane\ConfigTemplateIgnoreListTool;
use LTS\PHPQA\Pipeline\Lane\DeadCodeTool;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshnessTool;
use LTS\PHPQA\Pipeline\Lane\DocsProseTool;
use LTS\PHPQA\Pipeline\Lane\DocumentedQaCommandsTool;
use LTS\PHPQA\Pipeline\Lane\InfectionConfigSourceDirsTool;
use LTS\PHPQA\Pipeline\Lane\InfectionTool;
use LTS\PHPQA\Pipeline\Lane\MarkdownFormatTool;
use LTS\PHPQA\Pipeline\Lane\MarkdownLinksTool;
use LTS\PHPQA\Pipeline\Lane\OpcacheTool;
use LTS\PHPQA\Pipeline\Lane\PackageTypeTool;
use LTS\PHPQA\Pipeline\Lane\PhpArkitectTool;
use LTS\PHPQA\Pipeline\Lane\PhpcpdTool;
use LTS\PHPQA\Pipeline\Lane\PhpCsFixerTool;
use LTS\PHPQA\Pipeline\Lane\PhpLintTool;
use LTS\PHPQA\Pipeline\Lane\PhpstanIgnoreJustificationTool;
use LTS\PHPQA\Pipeline\Lane\PhpstanTool;
use LTS\PHPQA\Pipeline\Lane\PhpStrictTypesTool;
use LTS\PHPQA\Pipeline\Lane\PhpunitTool;
use LTS\PHPQA\Pipeline\Lane\Psr4ValidateTool;
use LTS\PHPQA\Pipeline\Lane\RectorTool;
use LTS\PHPQA\Pipeline\Lane\SensitiveParameterUsageTool;
use LTS\PHPQA\Pipeline\Lane\ShellCheckTool;
use LTS\PHPQA\Pipeline\Lane\TwigCsFixerTool;
use LTS\PHPQA\Pipeline\Lane\TwigLintTool;
use LTS\PHPQA\Pipeline\Lane\VersionPinsTool;
use LTS\PHPQA\Pipeline\Lane\YamlLintTool;

/**
 * The lanes php-qa-ci ships, keyed by canonical registry name. A lane is
 * added here as it is ported; the locator refuses a name that is not here.
 *
 * @api
 */
final readonly class ShippedTools
{
    /**
     * Each key is written out, not read from name(), so the map is visibly
     * string-keyed: no shipped name is all digits. EveryLaneNamesItselfTest
     * holds each key to its lane's name().
     *
     * @return array<string, ToolInterface> keyed by canonical name
     */
    public static function all(): array
    {
        return [
            'dependencyFreshness'        => new DependencyFreshnessTool(),
            'psr4Validate'               => new Psr4ValidateTool(),
            'packageType'                => new PackageTypeTool(),
            'configTemplateIgnoreList'   => new ConfigTemplateIgnoreListTool(),
            'infectionConfigSourceDirs'  => new InfectionConfigSourceDirsTool(),
            'analysedPaths'              => new AnalysedPathsTool(),
            'versionPins'                => new VersionPinsTool(),
            'changelog'                  => new ChangelogTool(),
            'phpstanIgnoreJustification' => new PhpstanIgnoreJustificationTool(),
            'sensitiveParameterUsage'    => new SensitiveParameterUsageTool(),
            'markdownLinks'              => new MarkdownLinksTool(),
            'docsProse'                  => new DocsProseTool(),
            'documentedQaCommands'       => new DocumentedQaCommandsTool(ToolRegistry::shipped()),
            'phpStrictTypes'             => new PhpStrictTypesTool(),
            'branchNamePolicy'           => new BranchNamePolicyTool(),
            'phpLint'                    => new PhpLintTool(),
            'opcache'                    => new OpcacheTool(),
            'composerChecks'             => new ComposerChecksTool(),
            'composerRequireChecker'     => new ComposerRequireCheckerTool(),
            'composerDependencyAnalyser' => new ComposerDependencyAnalyserTool(),
            'phpcpd'                     => new PhpcpdTool(),
            'twigCsFixer'                => new TwigCsFixerTool(),
            'markdownFormat'             => new MarkdownFormatTool(),
            'twigLint'                   => new TwigLintTool(),
            'yamlLint'                   => new YamlLintTool(),
            'shellCheck'                 => new ShellCheckTool(),
            'phpstan'                    => new PhpstanTool(),
            'deadCode'                   => new DeadCodeTool(),
            'phpArkitect'                => new PhpArkitectTool(),
            'rector'                     => new RectorTool(),
            'phpCsFixer'                 => new PhpCsFixerTool(),
            'phpunit'                    => new PhpunitTool(),
            'infection'                  => new InfectionTool(),
        ];
    }
}
