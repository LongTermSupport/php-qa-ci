<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Markdown\QaCommandTokenFinding;
use LTS\PHPQA\Markdown\QaCommandTokenScanner;
use LTS\PHPQA\Pipeline\Config\PipelineConfigLoader;
use LTS\PHPQA\Pipeline\Lane\DocumentedQaCommandsTool;
use LTS\PHPQA\Pipeline\Tool\PipelineBuilder;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Pipeline\Tool\ToolRegistry;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 */
#[CoversClass(DocumentedQaCommandsTool::class)]
#[UsesClass(QaCommandTokenScanner::class)]
#[UsesClass(QaCommandTokenFinding::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\PipelineConfigLoader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\PipelineBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ShippedTools::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolRegistry::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\PhaseDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\PipelineDefinitionDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\PhaseEnum::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(ToolOutcomeEnum::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[UsesClass(\LTS\PHPQA\HooksDaemon\HooksDaemonCliLocator::class)]
#[UsesClass(\LTS\PHPQA\InfectionConfig\InfectionConfigSourceDirectoriesCheck::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\IgnoreErrorsJustificationCheck::class)]
#[UsesClass(\LTS\PHPQA\PackageType\ExplicitPackageTypeCheck::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\AnalysedPathsTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\BranchNamePolicyTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ChangelogTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ComposerChecksTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ComposerDependencyAnalyserTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ComposerRequireCheckerTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ConfigTemplateIgnoreListTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\DeadCodeTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\DocsProseTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\InfectionConfigSourceDirsTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\InfectionTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\Infection\InfectionDiffFilter::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\MarkdownFormatTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\MarkdownLinksTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\OpcacheTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PackageTypeTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpArkitectTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpCsFixerTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpLintTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpStrictTypesTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpcpdTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpstanIgnoreJustificationTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpstanTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\Phpstan\DiagnoseTurboProbe::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpunitTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\Psr4ValidateTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\RectorTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\SensitiveParameterUsageTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ShellCheckTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\TwigCsFixerTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\TwigLintTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\VersionPinsTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\YamlLintTool::class)]
#[Small]
final class DocumentedQaCommandsToolTest extends TestCase
{
    private const string AGENT = '.claude/agents/fixer.md';

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function documentedCommandsTheRegistryAcceptsPass(): void
    {
        $this->factory->project->write(self::AGENT, "Run `{bin}/qa -t allCS`, then `vendor/bin/qa -t stan -p src`.\n");

        $result = new DocumentedQaCommandsTool(ToolRegistry::shipped())->run($this->factory->context());

        self::assertTrue($result->isSuccess());
    }

    #[Test]
    public function aRejectedTokenFailsAndNamesThePlaceTheTokenAndTheFix(): void
    {
        $this->factory->project->write(self::AGENT, "# Fixer\n\nRun `{bin}/qa -t allCs -p [changed-files]`\n");

        $result = new DocumentedQaCommandsTool(ToolRegistry::shipped())->run($this->factory->context());
        $output = $this->factory->output->fetch();

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString(self::AGENT . ':3', $output);
        self::assertStringContainsString("'allCs' is not a tool bin/qa accepts", $output);
        self::assertStringContainsString('did you mean -t allCS', $output);
        self::assertStringContainsString(DocumentedQaCommandsTool::IDENTIFIER, $output);
    }

    /** The project's pipeline is applied once, as bin/qa applies it, and the build hands the lane its registry. */
    #[Test]
    public function aTokenTheProjectsOwnPipelineAddsIsAccepted(): void
    {
        $this->factory->project->write('qaConfig/pipeline.php', <<<'PHP_WRAP'
            <?php
        
            declare(strict_types=1);
        
            use LTS\PHPQA\Pipeline\Tool\Dto\PhaseDto;
            use LTS\PHPQA\Pipeline\Tool\PipelineBuilder;
        
            return static fn (PipelineBuilder $pipeline): PipelineBuilder => $pipeline
                ->withPhase(new PhaseDto('security', 'Running All Security Tools', 'allSecurityTools', ['allSec'], 'all security tools'));
            PHP_WRAP);
        $this->factory->project->write('README.md', "vendor/bin/qa -t allSec\n");

        $built  = new PipelineConfigLoader(new NullOutput())->apply(PipelineBuilder::defaults(), $this->factory->paths()->projectConfigDir)->build();
        $result = $built->tools['documentedQaCommands']->run($this->factory->context());

        self::assertTrue($result->isSuccess(), $this->factory->output->fetch());
    }

    #[Test]
    public function itIsNamedAndIdentifiedForTheRegistry(): void
    {
        $tool = new DocumentedQaCommandsTool(ToolRegistry::shipped());

        self::assertSame('documentedQaCommands', $tool->name());
        self::assertSame('phpqaci.documentedQaCommands', $tool->identifier());
    }
}
