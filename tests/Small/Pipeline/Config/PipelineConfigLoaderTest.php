<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Config;

use LTS\PHPQA\Pipeline\Config\PipelineConfigLoader;
use LTS\PHPQA\Pipeline\Tool\Dto\PhaseDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto;
use LTS\PHPQA\Pipeline\Tool\PhaseEnum;
use LTS\PHPQA\Pipeline\Tool\PipelineBuilder;
use LTS\PHPQA\Pipeline\Tool\ToolRegistry;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(PipelineConfigLoader::class)]
#[UsesClass(PipelineBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\PipelineDefinitionDto::class)]
#[UsesClass(PhaseDto::class)]
#[UsesClass(PhaseEnum::class)]
#[UsesClass(ToolDefinitionDto::class)]
#[UsesClass(ToolRegistry::class)]
#[Small]
final class PipelineConfigLoaderTest extends TestCase
{
    private const string QA_CONFIG = '/qaConfig';

    private const string PIPELINE_PHP = 'qaConfig/pipeline.php';

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
    public function withoutAPipelinePhpTheBuilderIsReturnedUntouched(): void
    {
        $builder = PipelineBuilder::empty();

        $result = new PipelineConfigLoader($this->factory->output)->apply($builder, $this->factory->project->path . self::QA_CONFIG);

        self::assertSame($builder, $result);
        self::assertSame('', $this->factory->output->fetch());
    }

    #[Test]
    public function aPipelinePhpClosureExtendsThePipeline(): void
    {
        $this->factory->project->write(self::PIPELINE_PHP, <<<'PHP_WRAP'
            <?php
            use LTS\PHPQA\Pipeline\Tool\Dto\PhaseDto;
            use LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto;
            use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
            use LTS\PHPQA\Pipeline\Tool\PipelineBuilder;
            use LTS\PHPQA\Pipeline\Tool\ToolContext;
            use LTS\PHPQA\Pipeline\Tool\ToolInterface;

            return static fn (PipelineBuilder $pipeline): PipelineBuilder => $pipeline
                ->withPhase(new PhaseDto('security', 'Running All Security Tools', 'allSecurityTools', ['allSec'], 'all security tools'))
                ->withTool(
                    new ToolDefinitionDto('securityAudit', ['sa'], 'security audit', 'security', false),
                    new class implements ToolInterface {
                        public function name(): string { return 'securityAudit'; }
                        public function identifier(): string { return 'project.securityAudit'; }
                        public function run(ToolContext $context): ToolResultDto { return ToolResultDto::passed(); }
                    },
                );
            PHP_WRAP);

        $built = new PipelineConfigLoader($this->factory->output)->apply(PipelineBuilder::empty(), $this->factory->project->path . self::QA_CONFIG)->build();

        self::assertSame(['security'], array_map(static fn (PhaseDto $phase): string => $phase->name, $built->registry->phases()));
        self::assertSame('securityAudit', $built->registry->resolve('sa')->name);
        self::assertSame('project.securityAudit', $built->tools['securityAudit']->identifier());
        self::assertStringContainsString('Project pipeline applied', $this->factory->output->fetch());
    }

    #[Test]
    public function aPipelinePhpThatReturnsSomethingElseIsRejected(): void
    {
        $this->factory->project->write(self::PIPELINE_PHP, '<?php return ["securityAudit"];');

        $this->expectException(RuntimeException::class);
        new PipelineConfigLoader($this->factory->output)->apply(PipelineBuilder::empty(), $this->factory->project->path . self::QA_CONFIG);
    }

    #[Test]
    public function aClosureThatDoesNotReturnTheBuilderIsRejected(): void
    {
        $this->factory->project->write(self::PIPELINE_PHP, '<?php return static function ($pipeline): void {};');

        $this->expectException(RuntimeException::class);
        new PipelineConfigLoader($this->factory->output)->apply(PipelineBuilder::empty(), $this->factory->project->path . self::QA_CONFIG);
    }
}
