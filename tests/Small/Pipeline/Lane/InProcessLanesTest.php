<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\InfectionConfig\InfectionConfigSourceDirectoriesCheck;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Lane\ConfigTemplateIgnoreListTool;
use LTS\PHPQA\Pipeline\Lane\InfectionConfigSourceDirsTool;
use LTS\PHPQA\Pipeline\Lane\MarkdownLinksTool;
use LTS\PHPQA\Pipeline\Lane\OutputCapture;
use LTS\PHPQA\Pipeline\Lane\PackageTypeTool;
use LTS\PHPQA\Pipeline\Lane\PhpstanIgnoreJustificationTool;
use LTS\PHPQA\Pipeline\Lane\Psr4ValidateTool;
use LTS\PHPQA\Pipeline\Lane\SensitiveParameterUsageTool;
use LTS\PHPQA\Pipeline\Lane\VersionPinsTool;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto;
use LTS\PHPQA\Pipeline\Tool\PhaseEnum;
use LTS\PHPQA\Pipeline\Tool\ShippedTools;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Pipeline\Tool\ToolRegistry;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The lanes that call a check in-process. Each is driven over a throwaway
 * project in both its passing and failing state; the failing state must end
 * with the lane's identifier.
 *
 * @internal
 */
#[CoversClass(OutputCapture::class)]
#[CoversClass(Psr4ValidateTool::class)]
#[CoversClass(PackageTypeTool::class)]
#[CoversClass(ConfigTemplateIgnoreListTool::class)]
#[CoversClass(InfectionConfigSourceDirsTool::class)]
#[CoversClass(VersionPinsTool::class)]
#[CoversClass(PhpstanIgnoreJustificationTool::class)]
#[CoversClass(SensitiveParameterUsageTool::class)]
#[CoversClass(MarkdownLinksTool::class)]
#[CoversClass(ShippedTools::class)]
#[UsesClass(InfectionConfigSourceDirectoriesCheck::class)]
#[UsesClass(\LTS\PHPQA\InfectionConfig\InfectionConfigSourceDirectoriesDetector::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(ToolOutcomeEnum::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\Dto\JustificationFindingDto::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\IgnoreErrorsJustificationCheck::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\IgnoreErrorsJustificationDetector::class)]
#[UsesClass(\LTS\PHPQA\VersionPins\PhpUnitConfigDetector::class)]
#[UsesClass(\LTS\PHPQA\VersionPins\SafeScanFilesDetector::class)]
#[UsesClass(\LTS\PHPQA\VersionPins\VersionPinsCheck::class)]
#[UsesClass(\LTS\PHPQA\SensitiveParameter\SensitiveParameterUsageResult::class)]
#[UsesClass(\LTS\PHPQA\SensitiveParameter\SensitiveParameterUsageScanner::class)]
#[UsesClass(\LTS\PHPQA\Helper::class)]
#[UsesClass(\LTS\PHPQA\Psr4Validator::class)]
#[UsesClass(\LTS\PHPQA\PackageType\ExplicitPackageTypeCheck::class)]
#[UsesClass(\LTS\PHPQA\PackageType\ExplicitPackageTypeDetector::class)]
#[UsesClass(\LTS\PHPQA\ConfigTemplateIgnoreList\ConfigTemplateIgnoreListAuditor::class)]
#[UsesClass(\LTS\PHPQA\ConfigTemplateIgnoreList\ConfigTemplateIgnoreListCheck::class)]
#[UsesClass(\LTS\PHPQA\Markdown\LinksChecker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\BranchNamePolicyTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ComposerChecksTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ComposerRequireCheckerTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\InfectionTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpArkitectTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpCsFixerTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpLintTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpStrictTypesTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpstanTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpunitTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\RectorTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\TwigLintTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\YamlLintTool::class)]
#[UsesClass(ToolDefinitionDto::class)]
#[UsesClass(ToolRegistry::class)]
#[Small]
final class InProcessLanesTest extends TestCase
{
    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        $this->factory->project->write('composer.json', <<<'JSON'
            {
              "name": "fixture/lanes",
              "type": "project",
              "require": { "php": "^8.5" },
              "autoload": { "psr-4": { "Fixture\\": "src/" } }
            }
            JSON);
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function everyRegisteredLeafToolThatIsPortedIsShippedUnderItsRegistryName(): void
    {
        $shipped = ShippedTools::all();

        $platformLanes = [];
        foreach (PhaseEnum::cases() as $phase) {
            $platformLanes = [...$platformLanes, ...ToolRegistry::platformLanes(PlatformEnum::Symfony, $phase)];
        }

        $platformNames = array_map(static fn (ToolDefinitionDto $lane): string => $lane->name, $platformLanes);
        foreach ($shipped as $name => $tool) {
            self::assertSame($name, $tool->name());
            if (!\in_array($name, $platformNames, true)) {
                self::assertSame($name, ToolRegistry::shipped()->definition($name)->name, $name . ' is not a registry name');
            }

            self::assertStringStartsWith('phpqaci.', $tool->identifier());
        }
    }

    #[Test]
    public function psr4ValidatePassesOnAConformingTreeAndFailsWithTheIdentifierOnADriftedOne(): void
    {
        $this->factory->project->write('src/Good.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fixture;\n\nfinal class Good {}\n");
        $this->assertPasses(new Psr4ValidateTool(), 'No errors found');

        $this->factory->project->write('src/Bad.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Wrong\\Place;\n\nfinal class Bad {}\n");
        $this->assertFails(new Psr4ValidateTool(), 'PSR-4 Errors', Psr4ValidateTool::IDENTIFIER);
    }

    #[Test]
    public function psr4ValidateHonoursTheIgnoreListFromQaConfig(): void
    {
        $this->factory->project->write('src/Bad.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Wrong\\Place;\n\nfinal class Bad {}\n");
        $this->factory->project->write('qaConfig/psr4-validate-ignore-list.txt', "#src/Bad\\.php#\n\n");

        $this->assertPasses(new Psr4ValidateTool(), 'No errors found');
    }

    #[Test]
    public function packageTypePassesWithAnExplicitTypeAndFailsWithout(): void
    {
        $this->assertPasses(new PackageTypeTool(), '');

        $this->factory->project->write('composer.json', '{"name": "fixture/lanes"}');
        $this->assertFails(new PackageTypeTool(), 'type', PackageTypeTool::IDENTIFIER);
    }

    #[Test]
    public function configTemplateIgnoreListAuditsTheShippedTemplates(): void
    {
        $this->assertPasses(new ConfigTemplateIgnoreListTool(), 'covered by psr4-validate-ignore-list.txt');
    }

    #[Test]
    public function infectionConfigSourceDirsUsesTheResolvedInfectionJson(): void
    {
        $this->factory->project->write('qaConfig/infection.json', '{"source": {"directories": ["../src"]}}');
        $this->assertPasses(new InfectionConfigSourceDirsTool(), 'all resolve to real directories');

        $this->factory->project->write('qaConfig/infection.json', '{"source": {"directories": ["../nope"]}}');
        $this->assertFails(new InfectionConfigSourceDirsTool(), 'does not resolve', InfectionConfigSourceDirectoriesCheck::IDENTIFIER);
    }

    #[Test]
    public function versionPinsUsesTheResolvedConfigs(): void
    {
        $this->factory->project->write('qaConfig/phpunit.xml', '<phpunit xmlns:xsi="x" xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.2/phpunit.xsd"/>');
        $this->factory->project->write('qaConfig/composerRequireChecker.json', '{"scan-files": []}');

        $this->assertFails(new VersionPinsTool(), 'PHPUnit 10 schema', 'phpqaci.versionPins');
    }

    #[Test]
    public function phpstanIgnoreJustificationReadsTheProjectRecord(): void
    {
        $this->assertPasses(new PhpstanIgnoreJustificationTool(), 'nothing to check');

        $this->factory->project->write('qaConfig/phpstan.neon', "parameters:\n    ignoreErrors:\n        - '#unjustified#'\n");
        $this->assertFails(new PhpstanIgnoreJustificationTool(), 'without a usable justification', PhpstanIgnoreJustificationTool::IDENTIFIER);
    }

    #[Test]
    public function sensitiveParameterUsagePassesFailsAndSkipsWhenDisabled(): void
    {
        $this->factory->project->write('src/Login.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fixture;\n\nfinal class Login { public function __construct(#[\\SensitiveParameter] string \$password) {} }\n");
        $this->assertPasses(new SensitiveParameterUsageTool(), 'PASSED');

        $this->factory->project->write('src/Login.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fixture;\n\nfinal class Login {}\n");
        $this->assertFails(new SensitiveParameterUsageTool(), 'SensitiveParameter', SensitiveParameterUsageTool::IDENTIFIER);

        $context = $this->factory->context($this->factory->builder()->withSensitiveParameterCheck(false)->build());
        $result  = new SensitiveParameterUsageTool()->run($context);
        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString('disabled for this project', $this->factory->output->fetch());
    }

    #[Test]
    public function markdownLinksRequiresAReadmeThenChecksLinks(): void
    {
        $this->assertFails(new MarkdownLinksTool(), 'requires a README.md', MarkdownLinksTool::IDENTIFIER);

        $this->factory->project->write('README.md', "# Fixture\n\n[good](./composer.json)\n");
        $this->assertPasses(new MarkdownLinksTool(), '');

        $this->factory->project->write('README.md', "# Fixture\n\n[bad](./missing.md)\n");
        $this->assertFails(new MarkdownLinksTool(), 'missing.md', MarkdownLinksTool::IDENTIFIER);
    }

    #[Test]
    public function outputCaptureForwardsWhatACheckPrintedEvenWhenItThrows(): void
    {
        try {
            OutputCapture::run(static function (): int {
                echo 'partial';

                throw new RuntimeException('boom');
            }, $this->factory->output);
            self::fail('expected the exception to propagate');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('boom', $runtimeException->getMessage());
        }

        self::assertSame('partial', $this->factory->output->fetch());
    }

    private function assertPasses(ToolInterface $tool, string $expectedOutput): void
    {
        $result  = $tool->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertTrue($result->isSuccess(), $tool->name() . ' should pass; output: ' . $printed);
        self::assertStringContainsString($expectedOutput, $printed);
    }

    private function assertFails(ToolInterface $tool, string $expectedOutput, string $identifier): void
    {
        $result  = $tool->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome, $tool->name() . ' should fail; output: ' . $printed);
        self::assertStringContainsString($expectedOutput, $printed);
        self::assertStringContainsString('🪪  ' . $identifier . '  (vendor/bin/rule-doc ' . $identifier . ')', $printed);
        self::assertNotSame('', $result->summary);
    }
}
