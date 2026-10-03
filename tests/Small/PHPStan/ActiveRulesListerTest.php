<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan;

use LTS\PHPQA\PHPStan\ActiveRulesLister;
use LTS\PHPQA\Pipeline\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Defence for the class "a project's active defences can only be learned by
 * violating them one at a time". ActiveRulesLister must derive its listing
 * from the resolved PHPStan configuration — following includes, collecting
 * both `rules:` classes and `phpstan.rules.rule`-tagged services, resolving
 * identifiers via RuleDocResolver where a rule declares one — and must also
 * enumerate the project record (ignoreErrors) with any preceding comment
 * recovered as justification.
 *
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ActiveRulesLister::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\Dto\ActiveDefencesListingDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\Dto\ActiveRuleEntryDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\Dto\PipelineLaneDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\Dto\ProjectRecordEntryDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\Dto\RuleDocEntryDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\RuleDocResolver::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\NeonIncludeChain::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\InstalledPhpstanExtensions::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonIncludeChainDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonRecordFileDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\InfectionConfig\InfectionConfigSourceDirectoriesCheck::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\IgnoreErrorsJustificationCheck::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\DefectRecord\DefectRecordCheck::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\DefectRecord\DefectRecordReader::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\DefectRecord\Dto\DefectRecordDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\DefectRecord\Dto\DeferredDefectDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\DefectRecord\Dto\NoPatternConclusionDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PackageType\ExplicitPackageTypeCheck::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\BranchNamePolicyTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\ComposerChecksTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\ComposerRequireCheckerTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\ConfigTemplateIgnoreListTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\InfectionConfigSourceDirsTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\InfectionTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\MarkdownLinksTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\DocsProseTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\OpcacheTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\PackageTypeTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\DeadCodeTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpArkitectTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpCsFixerTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpLintTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpStrictTypesTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpstanIgnoreJustificationTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpstanTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpunitTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\Psr4ValidateTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\RectorTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\SensitiveParameterUsageTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\ShellCheckTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\AnalysedPathsTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\ChangelogTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\TwigLintTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\ComposerDependencyAnalyserTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpcpdTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\TwigCsFixerTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\VersionPinsTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\YamlLintTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Tool\ShippedTools::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(ToolRegistry::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\PhaseDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Tool\PhaseEnum::class)]
#[\PHPUnit\Framework\Attributes\Small]
final class ActiveRulesListerTest extends TestCase
{
    private const string QA_CI_ROOT = __DIR__ . '/../../..';

    private const string FIXTURE_PROJECT = __DIR__ . '/../../assets/ActiveRulesLister/projectFixture';

    private const string LEGACY_IGNORED_IDENTIFIER = 'class.notFound';

    public function testTextOutputListsRulesLanesAndProjectRecordExactly(): void
    {
        $lister  = new ActiveRulesLister(self::QA_CI_ROOT);
        $listing = $lister->list(self::FIXTURE_PROJECT);

        self::assertSame(self::FIXTURE_PROJECT . '/qaConfig/phpstan.neon', $listing->configPath);

        self::assertCount(2, $listing->rules);

        $withIdentifier = $listing->rules[0];
        self::assertSame(\LTS\PHPQA\PHPStan\Rules\ForbidDangerousFunctionsRule::IDENTIFIER, $withIdentifier->identifier);
        self::assertSame(\LTS\PHPQA\PHPStan\Rules\ForbidDangerousFunctionsRule::class, $withIdentifier->ruleClass);
        self::assertSame('No exec/eval/unserialize and similar', $withIdentifier->summary);
        self::assertSame(
            \Safe\realpath(self::QA_CI_ROOT . '/docs/phpstan-rules/forbid-dangerous-functions.md'),
            $withIdentifier->docPath,
        );

        $withoutIdentifier = $listing->rules[1];
        self::assertNull($withoutIdentifier->identifier);
        self::assertSame(
            \LTS\PHPQA\Tests\Assets\ActiveRulesLister\FixtureProjectRule::class,
            $withoutIdentifier->ruleClass,
        );
        self::assertNull($withoutIdentifier->summary);
        self::assertNull($withoutIdentifier->docPath);

        self::assertNotEmpty($listing->pipelineLanes);
        $laneNames = array_map(static fn (\LTS\PHPQA\PHPStan\Dto\PipelineLaneDto $lane): string => $lane->name, $listing->pipelineLanes);
        self::assertContains('branchNamePolicy', $laneNames);
        self::assertContains('sensitiveParameterUsage', $laneNames);
        self::assertContains('packageType', $laneNames);
        self::assertContains('phpArkitect', $laneNames);
        self::assertContains('psr4Validate', $laneNames);
        self::assertContains('phpStrictTypes', $laneNames);
        self::assertContains('composerRequireChecker', $laneNames);
        self::assertContains('markdownLinks', $laneNames);
        self::assertContains('rector', $laneNames);
        self::assertContains('phpCsFixer', $laneNames);
        self::assertContains('phpunit', $laneNames);
        self::assertContains('infection', $laneNames);
        self::assertNotContains('phpstan', $laneNames);

        $lanesByName = [];
        foreach ($listing->pipelineLanes as $lane) {
            $lanesByName[$lane->name] = $lane;
        }

        self::assertSame('linting', $lanesByName['psr4Validate']->phase);
        self::assertSame('staticAnalysis', $lanesByName['phpArkitect']->phase);
        self::assertSame('testing', $lanesByName['phpunit']->phase);
        self::assertNull($lanesByName['uniterate']->phase);

        self::assertSame('useInfection', $lanesByName['infection']->optInVariable);
        self::assertSame('useArkitect', $lanesByName['phpArkitect']->optInVariable);
        self::assertNull($lanesByName['psr4Validate']->optInVariable);

        self::assertCount(1, $listing->projectRecord);
        $record = $listing->projectRecord[0];
        self::assertSame(self::LEGACY_IGNORED_IDENTIFIER, $record->identifier);
        self::assertSame('some/legacy/path.php', $record->path);
        self::assertSame(2, $record->count);
        self::assertSame(
            'Justification: legacy generated code, tracked for removal in TICKET-123.',
            $record->justification,
        );

        $text = $lister->renderText($listing);
        self::assertStringContainsString(\LTS\PHPQA\PHPStan\Rules\ForbidDangerousFunctionsRule::IDENTIFIER, $text);
        self::assertStringContainsString('No exec/eval/unserialize and similar', $text);
        self::assertStringContainsString('FixtureProjectRule', $text);
        self::assertStringContainsString('not declared', $text);
        self::assertStringContainsString('Pipeline lanes', $text);
        self::assertStringContainsString('branchNamePolicy', $text);
        self::assertStringContainsString('Project record', $text);
        self::assertStringContainsString(self::LEGACY_IGNORED_IDENTIFIER, $text);
        self::assertStringContainsString(
            'Justification: legacy generated code, tracked for removal in TICKET-123.',
            $text,
        );
    }

    /**
     * Toolchain specification 5.1: being able to name a defence is only half of it —
     * a reader who finds a lane in the listing must be able to get from there to the
     * page that states the correct construction. The listing carried name, identifier,
     * summary and phase, so a lane could be named but not read about.
     */
    public function testEveryListedLaneOffersADocumentationRoute(): void
    {
        $lister  = new ActiveRulesLister(self::QA_CI_ROOT);
        $listing = $lister->list(self::FIXTURE_PROJECT);

        $lanesByName = [];
        foreach ($listing->pipelineLanes as $lane) {
            $lanesByName[$lane->name] = $lane;
        }

        self::assertSame(
            \Safe\realpath(self::QA_CI_ROOT . '/docs/tools/markdownLinks.md'),
            $lanesByName['markdownLinks']->docPath,
            'a lane with a remediation page must resolve to it',
        );
        self::assertSame(
            \Safe\realpath(self::QA_CI_ROOT . '/docs/tools/docsProse.md'),
            $lanesByName['docsProse']->docPath,
        );

        // A phase runner is not a defence and documents nothing of its own.
        self::assertNull($lanesByName['allLintingTools']->docPath);

        $text = $lister->renderText($listing);
        self::assertStringContainsString('docs/tools/markdownLinks.md', $text, 'the text listing must print the route');
        self::assertStringContainsString('phpqaci.markdownLinks', $text, 'the text listing must print the identifier');
    }

    /**
     * Toolchain specification 8.1: every identifier php-qa-ci can print must reach a
     * page stating the correct construction. A lane that names itself on failure and
     * then resolves to nothing leaves the reader exactly where they started.
     *
     * This is the defence for the class "an index row whose documentation link does not
     * resolve, silently". It is silent by construction: the row is present and looks
     * right, `bin/rule-doc` still prints the summary, and nothing anywhere fails — so
     * only an assertion over the resolved path can see it.
     */
    public function testEveryLaneWithAnIdentifierResolvesToAnExistingPage(): void
    {
        $listing = new ActiveRulesLister(self::QA_CI_ROOT)->list(self::FIXTURE_PROJECT);

        $unresolved = [];
        foreach ($listing->pipelineLanes as $lane) {
            if (null === $lane->identifier) {
                continue;
            }

            if (null === $lane->docPath || !is_file($lane->docPath)) {
                $unresolved[] = \sprintf('%s (%s)', $lane->name, $lane->identifier);
            }
        }

        self::assertSame([], $unresolved, \sprintf(
            "every lane that can print an identifier must resolve to a page; these do not:\n  %s",
            implode("\n  ", $unresolved),
        ));
    }

    public function testJsonOutputIsValidAndStructured(): void
    {
        $lister  = new ActiveRulesLister(self::QA_CI_ROOT);
        $listing = $lister->list(self::FIXTURE_PROJECT);

        $json    = $lister->renderJson($listing);
        $decoded = \Safe\json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame(self::FIXTURE_PROJECT . '/qaConfig/phpstan.neon', $decoded['configPath']);

        $rules = $decoded['rules'];
        self::assertIsArray($rules);
        self::assertCount(2, $rules);
        $firstRule = $rules[0];
        self::assertIsArray($firstRule);
        self::assertSame(\LTS\PHPQA\PHPStan\Rules\ForbidDangerousFunctionsRule::IDENTIFIER, $firstRule['identifier']);
        $secondRule = $rules[1];
        self::assertIsArray($secondRule);
        self::assertNull($secondRule['identifier']);

        $projectRecord = $decoded['projectRecord'];
        self::assertIsArray($projectRecord);
        self::assertCount(1, $projectRecord);
        $firstRecord = $projectRecord[0];
        self::assertIsArray($firstRecord);
        self::assertSame(self::LEGACY_IGNORED_IDENTIFIER, $firstRecord['identifier']);

        self::assertNotEmpty($decoded['pipelineLanes']);

        self::assertSame(
            [
                'path'      => self::FIXTURE_PROJECT . '/qaConfig/defect-record.neon',
                'deferred'  => [
                    [
                        'defect'     => "The fixture's cache key omits the locale.",
                        'class'      => 'A cache key built from a subset of the inputs the value depends on.',
                        'found'      => 'src/Cache/KeyBuilder.php',
                        'deferredBy' => 'Owner',
                    ],
                ],
                'noPattern' => [
                    [
                        'defect'     => "The fixture's invoice total was rounded twice.",
                        'found'      => 'src/Invoice/Total.php',
                        'conclusion' => 'No pattern exists; neither technique found a second double rounding.',
                        'techniques' => ['a text search for round( over src/', 'reading every caller of Money::round()'],
                    ],
                ],
            ],
            $decoded['defectRecord'],
        );
    }

    /**
     * Method specification section 2 (1.1.0): a deferred Defect is recorded where the
     * project's decisions are enumerable. A record the listing cannot read is an error,
     * as an include it cannot follow is, rather than an enumeration that quietly omits it.
     */
    public function testAMalformedDefectRecordIsAnError(): void
    {
        $project = \LTS\PHPQA\Tests\Support\TempDir::create('phpqa-lister-defect-record');

        try {
            $project->write('qaConfig/defect-record.neon', "deferred:\n    -\n        defect: x\n");
            new ActiveRulesLister(self::QA_CI_ROOT)->list($project->path);
            self::fail('expected the malformed defect record to be an error');
        } catch (RuntimeException $runtimeException) {
            self::assertSame(
                'Cannot read the defect record: qaConfig/defect-record.neon: deferred #1 "found" must be a non-empty string; '
                . 'qaConfig/defect-record.neon: deferred #1 "deferredBy" must be a non-empty string',
                $runtimeException->getMessage(),
            );
        } finally {
            $project->remove();
        }
    }

    public function testProjectWithNoQaConfigFallsBackToTheShippedDefault(): void
    {
        $lister  = new ActiveRulesLister(self::QA_CI_ROOT);
        $listing = $lister->list(sys_get_temp_dir());

        self::assertSame(
            self::QA_CI_ROOT . '/configDefaults/generic/phpstan.neon',
            $listing->configPath,
        );
        self::assertSame([], $listing->rules);
        self::assertSame([], $listing->projectRecord);
    }

    /**
     * The listing walks includes with the same NeonIncludeChain the justification lane
     * uses: a cycle is read once rather than recursing until the stack runs out, and
     * PHPStan's own %rootDir% configuration is not a project file to open.
     */
    public function testACyclicIncludeAndPhpstansOwnConfigurationAreWalkedOnce(): void
    {
        $listing = new ActiveRulesLister(self::QA_CI_ROOT)->list(__DIR__ . '/../../assets/ActiveRulesLister/cyclicProject');

        self::assertSame(
            [\LTS\PHPQA\PHPStan\Rules\ForbidDangerousFunctionsRule::class, \LTS\PHPQA\Tests\Assets\ActiveRulesLister\FixtureProjectRule::class],
            array_map(static fn (\LTS\PHPQA\PHPStan\Dto\ActiveRuleEntryDto $rule): string => $rule->ruleClass, $listing->rules),
        );
    }

    /**
     * In a consuming project the bundled tiers arrive through phpstan/extension-installer,
     * not through an include in its phpstan.neon. A listing that read only the neon tree
     * listed none of them there. A file reached both ways is read once.
     */
    public function testRulesTheExtensionInstallerDeliversAreListedWithTheirPackage(): void
    {
        $project = \LTS\PHPQA\Tests\Support\TempDir::create('phpqa-lister-installer');

        try {
            $project->write('vendor/acme/rules/rules.neon', "rules:\n    - LTS\\PHPQA\\PHPStan\\Rules\\ForbidDangerousFunctionsRule\n    - Acme\\Rules\\NoIdentifierRule\n");
            $project->write('qaConfig/phpstan.neon', "includes:\n    - ../vendor/acme/rules/rules.neon\n\nrules:\n    - LTS\\PHPQA\\Tests\\Assets\\ActiveRulesLister\\FixtureProjectRule\n");
            $project->write(
                'vendor/phpstan/extension-installer/src/GeneratedConfig.php',
                "<?php\nnamespace PHPStan\\ExtensionInstaller;\nfinal class GeneratedConfig\n{\n    public const EXTENSIONS = ['acme/rules' => ['relative_install_path' => '../../../acme/rules', 'extra' => ['includes' => ['rules.neon', 'more.neon']]]];\n}\n",
            );
            $project->write('vendor/acme/rules/more.neon', "conditionalTags:\n    Acme\\Rules\\OtherRule:\n        phpstan.rules.rule: %acme.otherRule%\n    Acme\\Rules\\NotARule:\n        phpstan.broker.dynamicMethodReturnTypeExtension: true\n");

            $listing = new ActiveRulesLister(self::QA_CI_ROOT)->list($project->path);
        } finally {
            $project->remove();
        }

        self::assertSame(
            [
                \LTS\PHPQA\PHPStan\Rules\ForbidDangerousFunctionsRule::class . ' ',
                'Acme\Rules\NoIdentifierRule ',
                \LTS\PHPQA\Tests\Assets\ActiveRulesLister\FixtureProjectRule::class . ' ',
                'Acme\Rules\OtherRule acme/rules',
            ],
            array_map(static fn (\LTS\PHPQA\PHPStan\Dto\ActiveRuleEntryDto $rule): string => $rule->ruleClass . ' ' . $rule->package, $listing->rules),
        );
    }

    public function testAnIncludeThatCannotBeFollowedIsAnError(): void
    {
        try {
            new ActiveRulesLister(self::QA_CI_ROOT)->list(__DIR__ . '/../../assets/ActiveRulesLister/missingIncludeProject');
            self::fail('expected the missing include to be an error');
        } catch (RuntimeException $runtimeException) {
            self::assertSame(
                'Cannot read the PHPStan configuration in full: qaConfig/phpstan.neon includes missing.neon, which does not exist',
                $runtimeException->getMessage(),
            );
        }
    }

    public function testUnparseableNeonIsAnError(): void
    {
        $lister = new ActiveRulesLister(self::QA_CI_ROOT);

        $this->expectException(RuntimeException::class);
        $lister->list(__DIR__ . '/../../assets/ActiveRulesLister/unparseableProject');
    }

    /**
     * Defence against toolchain-spec clause 7.1/7.2 drift: a lane added
     * ANYWHERE in the shipped ToolRegistry, whatever its phase, must appear in
     * ActiveRulesLister's output without any source change here. The expected
     * set is re-derived from the registry's leaf definitions (minus phpstan,
     * which is covered by the rule listing, not the lane listing).
     */
    public function testEveryRegistryToolNameAppearsAsAPipelineLane(): void
    {
        $expectedLaneNames = [];
        foreach (ToolRegistry::shipped()->all() as $definition) {
            if ('phpstan' === $definition->name) {
                continue;
            }

            $expectedLaneNames[] = $definition->name;
        }

        self::assertNotEmpty($expectedLaneNames, 'Expected at least one registered tool in the tool registry.');

        $lister    = new ActiveRulesLister(self::QA_CI_ROOT);
        $listing   = $lister->list(self::FIXTURE_PROJECT);
        $laneNames = array_map(
            static fn (\LTS\PHPQA\PHPStan\Dto\PipelineLaneDto $lane): string => $lane->name,
            $listing->pipelineLanes,
        );

        foreach ($expectedLaneNames as $expectedLaneName) {
            self::assertContains(
                $expectedLaneName,
                $laneNames,
                \sprintf(
                    'Tool "%s" is registered in ToolRegistry but ActiveRulesLister did not list it as a pipeline lane.',
                    $expectedLaneName,
                ),
            );
        }
    }
}
