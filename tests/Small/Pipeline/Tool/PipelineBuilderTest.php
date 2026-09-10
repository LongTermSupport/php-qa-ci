<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Tool;

use InvalidArgumentException;
use LTS\PHPQA\Pipeline\Tool\Dto\PhaseDto;
use LTS\PHPQA\Pipeline\Tool\Dto\PipelineDefinitionDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\Exception\UnknownPhaseException;
use LTS\PHPQA\Pipeline\Tool\PhaseEnum;
use LTS\PHPQA\Pipeline\Tool\PipelineBuilder;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use LTS\PHPQA\Pipeline\Tool\ToolRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PipelineBuilder::class)]
#[CoversClass(PipelineDefinitionDto::class)]
#[CoversClass(UnknownPhaseException::class)]
#[UsesClass(ToolRegistry::class)]
#[UsesClass(ToolDefinitionDto::class)]
#[UsesClass(PhaseDto::class)]
#[UsesClass(PhaseEnum::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ShippedTools::class)]
#[UsesClass(\LTS\PHPQA\InfectionConfig\InfectionConfigSourceDirectoriesCheck::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\IgnoreErrorsJustificationCheck::class)]
#[UsesClass(\LTS\PHPQA\PackageType\ExplicitPackageTypeCheck::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\BranchNamePolicyTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ComposerChecksTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ComposerDependencyAnalyserTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ComposerRequireCheckerTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ConfigTemplateIgnoreListTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\InfectionConfigSourceDirsTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\InfectionTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\MarkdownLinksTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\OpcacheTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PackageTypeTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\DeadCodeTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpArkitectTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpCsFixerTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpLintTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpStrictTypesTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpcpdTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpstanIgnoreJustificationTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpstanTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpunitTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\Psr4ValidateTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\RectorTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\SensitiveParameterUsageTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\TwigCsFixerTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\TwigLintTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\VersionPinsTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\YamlLintTool::class)]
#[Small]
final class PipelineBuilderTest extends TestCase
{
    private const string SECURITY = 'security';

    private const string SECURITY_AUDIT = 'securityAudit';

    private const string LINTING = 'linting';

    private const string PHPUNIT = 'phpunit';

    private const string PHP_LINT = 'phpLint';

    private const string CODING_STANDARDS = 'codingStandards';

    private const string STATIC_ANALYSIS = 'staticAnalysis';

    private const string TESTING = 'testing';

    #[Test]
    public function defaultsBuildTheShippedRegistryAndTheShippedTools(): void
    {
        $built   = PipelineBuilder::defaults()->build();
        $shipped = ToolRegistry::shipped();

        self::assertSame($this->names(...$shipped->all()), $this->names(...$built->registry->all()));
        self::assertSame($this->phaseNames($shipped), $this->phaseNames($built->registry));
        self::assertArrayHasKey(self::PHPUNIT, $built->tools);
        self::assertSame(self::PHPUNIT, $built->tools[self::PHPUNIT]->name());
    }

    #[Test]
    public function emptyStartsWithNoPhasesAndNoTools(): void
    {
        $built = PipelineBuilder::empty()->build();

        self::assertSame([], $built->registry->all());
        self::assertSame([], $built->registry->phases());
        self::assertSame([], $built->tools);
    }

    #[Test]
    public function aToolIsAddedToTheEndOfItsPhase(): void
    {
        $built = PipelineBuilder::defaults()
            ->withTool($this->definition(self::SECURITY_AUDIT, self::LINTING), $this->tool(self::SECURITY_AUDIT))
            ->build()
        ;

        $linting = $this->names(...$built->registry->toolsForPhase(self::LINTING));
        self::assertSame(self::SECURITY_AUDIT, end($linting));
        self::assertSame(self::SECURITY_AUDIT, $built->registry->resolve('sa')->name);
        self::assertSame(self::SECURITY_AUDIT, $built->tools[self::SECURITY_AUDIT]->name());
    }

    #[Test]
    public function aToolAddedUnderAnExistingNameReplacesItInPlace(): void
    {
        $before = $this->names(...ToolRegistry::shipped()->toolsForPhase(self::LINTING));
        $built  = PipelineBuilder::defaults()
            ->withTool($this->definition(self::PHP_LINT, self::LINTING), $this->tool(self::PHP_LINT))
            ->build()
        ;

        self::assertSame($before, $this->names(...$built->registry->toolsForPhase(self::LINTING)));
        self::assertSame(['sa'], $built->registry->definition(self::PHP_LINT)->aliases);
        self::assertSame('phpqaci.fake', $built->tools[self::PHP_LINT]->identifier());
    }

    #[Test]
    public function aToolMustBeDefinedUnderTheNameItAnswersTo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains("the tool defined as 'securityAudit' answers to 'phpLint'");

        PipelineBuilder::empty()->withTool($this->definition(self::SECURITY_AUDIT, null), $this->tool(self::PHP_LINT));
    }

    #[Test]
    public function aToolCannotJoinAPhaseThatDoesNotExist(): void
    {
        $this->expectException(UnknownPhaseException::class);
        $this->expectExceptionMessageIsOrContains('Unknown phase: security (known: codingStandards, linting, staticAnalysis, testing)');

        PipelineBuilder::defaults()->withTool($this->definition(self::SECURITY_AUDIT, self::SECURITY), $this->tool(self::SECURITY_AUDIT));
    }

    #[Test]
    public function aNewPhaseIsAppendedWithItsRunnerAndCanTakeTools(): void
    {
        $built = PipelineBuilder::defaults()
            ->withPhase($this->phase())
            ->withTool($this->definition(self::SECURITY_AUDIT, self::SECURITY), $this->tool(self::SECURITY_AUDIT))
            ->build()
        ;

        self::assertSame([self::CODING_STANDARDS, self::LINTING, self::STATIC_ANALYSIS, self::TESTING, self::SECURITY], $this->phaseNames($built->registry));
        self::assertSame([self::SECURITY_AUDIT], $this->names(...$built->registry->toolsForPhase(self::SECURITY)));
        $runner = $built->registry->resolve('allSec');
        self::assertSame('allSecurityTools', $runner->name);
        self::assertTrue($runner->isPhaseRunner);
        self::assertSame(self::SECURITY, $runner->phase);
        self::assertSame('Running All Security Tools', array_last($built->registry->phases())?->banner);
    }

    #[Test]
    public function aNewPhaseCanBeInsertedBeforeAnExistingOne(): void
    {
        $built = PipelineBuilder::defaults()->withPhase($this->phase(), before: self::TESTING)->build();

        self::assertSame([self::CODING_STANDARDS, self::LINTING, self::STATIC_ANALYSIS, self::SECURITY, self::TESTING], $this->phaseNames($built->registry));
    }

    #[Test]
    public function aPhaseCannotBeInsertedBeforeOneThatDoesNotExist(): void
    {
        $this->expectException(UnknownPhaseException::class);

        PipelineBuilder::defaults()->withPhase($this->phase(), before: 'deployment');
    }

    #[Test]
    public function aPhaseNameMustBeUnique(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains("phase 'linting' is already defined");

        PipelineBuilder::defaults()->withPhase(PhaseEnum::Linting->phase());
    }

    #[Test]
    public function thePhaseOrderIsSetByNamingEveryPhaseOnce(): void
    {
        $built = PipelineBuilder::defaults()
            ->withPhaseOrder(self::TESTING, self::STATIC_ANALYSIS, self::LINTING, self::CODING_STANDARDS)
            ->build()
        ;

        self::assertSame([self::TESTING, self::STATIC_ANALYSIS, self::LINTING, self::CODING_STANDARDS], $this->phaseNames($built->registry));
        // The runners keep their phase order too: they are derived from the phases.
        self::assertSame('allTestingTools', $built->registry->all()[0]->name);
    }

    #[Test]
    public function aPhaseOrderThatOmitsOrRepeatsAPhaseIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('every phase exactly once');

        PipelineBuilder::defaults()->withPhaseOrder(self::TESTING, self::LINTING);
    }

    #[Test]
    public function aPhaseOrderNamingAnUnknownPhaseIsRefused(): void
    {
        $this->expectException(UnknownPhaseException::class);

        PipelineBuilder::defaults()->withPhaseOrder(self::TESTING, self::STATIC_ANALYSIS, self::LINTING, self::SECURITY);
    }

    #[Test]
    public function theBuilderIsImmutable(): void
    {
        $defaults = PipelineBuilder::defaults();
        $defaults->withPhase($this->phase());
        $defaults->withTool($this->definition(self::SECURITY_AUDIT, self::LINTING), $this->tool(self::SECURITY_AUDIT));

        $built = $defaults->build();
        self::assertSame($this->phaseNames(ToolRegistry::shipped()), $this->phaseNames($built->registry));
        self::assertArrayNotHasKey(self::SECURITY_AUDIT, $built->tools);
    }

    private function phase(): PhaseDto
    {
        return new PhaseDto(self::SECURITY, 'Running All Security Tools', 'allSecurityTools', ['allSec'], 'all security tools');
    }

    private function definition(string $name, ?string $phase): ToolDefinitionDto
    {
        return new ToolDefinitionDto($name, ['sa'], 'security audit', $phase, false);
    }

    private function tool(string $name): ToolInterface
    {
        return new readonly class($name) implements ToolInterface {
            public function __construct(private string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function identifier(): string
            {
                return 'phpqaci.fake';
            }

            public function run(ToolContext $context): ToolResultDto
            {
                return ToolResultDto::passed();
            }
        };
    }

    /** @return list<string> */
    private function names(ToolDefinitionDto ...$definitions): array
    {
        return array_map(static fn (ToolDefinitionDto $definition): string => $definition->name, array_values($definitions));
    }

    /** @return list<string> */
    private function phaseNames(ToolRegistry $registry): array
    {
        return array_map(static fn (PhaseDto $phase): string => $phase->name, $registry->phases());
    }
}
