<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan;

use LTS\PHPQA\PHPStan\ActiveRulesLister;
use LTS\PHPQA\PHPStan\RuleDocResolver;
use PHPUnit\Framework\TestCase;

/**
 * Pins ActiveRulesLister's output to docs/phpstan-rules/README.md's index:
 * every identifier the index publishes for a rule registered in
 * rules-default.neon / rules-optional.neon must appear in the listing
 * produced from php-qa-ci's own qaConfig/phpstan.neon, so the listing and the
 * index cannot drift apart (method spec clause 7.3 run on the toolchain
 * itself; toolchain spec clause 6.1).
 *
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ActiveRulesLister::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\PhaseDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Tool\PhaseEnum::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\Dto\ActiveDefencesListingDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\Dto\ActiveRuleEntryDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\Dto\PipelineLaneDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\Dto\ProjectRecordEntryDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\Dto\RuleDocEntryDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(RuleDocResolver::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\InfectionConfig\InfectionConfigSourceDirectoriesCheck::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\IgnoreErrorsJustificationCheck::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PackageType\ExplicitPackageTypeCheck::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\BranchNamePolicyTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\ComposerChecksTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\ComposerRequireCheckerTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\ConfigTemplateIgnoreListTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\InfectionConfigSourceDirsTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\InfectionTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\MarkdownLinksTool::class)]
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
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\TwigLintTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\ComposerDependencyAnalyserTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpcpdTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\TwigCsFixerTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\VersionPinsTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Lane\YamlLintTool::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Tool\ShippedTools::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolRegistry::class)]
#[\PHPUnit\Framework\Attributes\Small]
final class ActiveRulesListerSelfCheckTest extends TestCase
{
    private const string QA_CI_ROOT = __DIR__ . '/../../..';

    public function testEveryIndexedRuleRegisteredInThisPackageAppearsInTheListing(): void
    {
        $lister  = new ActiveRulesLister(self::QA_CI_ROOT);
        $listing = $lister->list(self::QA_CI_ROOT);

        $listedByClass = [];
        foreach ($listing->rules as $rule) {
            $listedByClass[$rule->ruleClass] = $rule;
        }

        $resolver = new RuleDocResolver(self::QA_CI_ROOT);

        $missing = [];
        foreach ($resolver->identifiers() as $identifier) {
            $entry     = $resolver->resolve($identifier);
            $ruleClass = 'LTS\PHPQA\PHPStan\Rules\\' . $entry->ruleClass;

            if (!isset($listedByClass[$ruleClass])) {
                // Not every documented rule is necessarily registered in THIS
                // package's own self-check config (some ship for consumers
                // only) — only assert on rules the listing actually sees.
                continue;
            }

            if ($listedByClass[$ruleClass]->identifier !== $identifier) {
                $missing[] = $identifier;
            }
        }

        self::assertSame([], $missing, "Every rule registered in this package's own "
            . 'qaConfig/phpstan.neon must resolve to the SAME identifier docs/phpstan-rules/README.md publishes for it.');

        // The reverse direction: every rule class ActiveRulesLister itself
        // derives directly from rules-default.neon / rules-optional.neon (this
        // package's self-check includes both, per SelfCheckRunsBundledRulesTest)
        // must also appear in the listing derived from qaConfig/phpstan.neon —
        // proving the include-chain aggregation drops nothing.
        foreach (['rules-default.neon', 'rules-optional.neon'] as $bundle) {
            $bundlePath = self::QA_CI_ROOT . '/' . $bundle;
            self::assertFileExists($bundlePath);
            $bundleListing = $lister->list($this->fixtureProjectFor($bundlePath));
            foreach ($bundleListing->rules as $bundleRule) {
                self::assertArrayHasKey(
                    $bundleRule->ruleClass,
                    $listedByClass,
                    \sprintf('Rule class %s is registered in %s but ActiveRulesLister did not ', $bundleRule->ruleClass, $bundle)
                    . 'list it via qaConfig/phpstan.neon — the listing has drifted from the active configuration.',
                );
            }
        }
    }

    /**
     * Writes a throwaway qaConfig/phpstan.neon that includes exactly the one
     * bundle file, so ActiveRulesLister::list() can be pointed at it directly.
     */
    private function fixtureProjectFor(string $bundlePath): string
    {
        $tmpDir = sys_get_temp_dir() . '/active-rules-lister-selfcheck-' . bin2hex(random_bytes(8));
        \Safe\mkdir($tmpDir . '/qaConfig', 0o755, true);
        \Safe\file_put_contents(
            $tmpDir . '/qaConfig/phpstan.neon',
            "includes:\n    - " . $bundlePath . "\n",
        );

        return $tmpDir;
    }
}
