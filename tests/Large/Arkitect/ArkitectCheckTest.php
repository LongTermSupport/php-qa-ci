<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Arkitect;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\TestCase;

/**
 * Proves the bundled PHPArkitect phar enforces architecture rules end-to-end:
 * a project that satisfies its rules exits 0, one that violates them exits 1
 * and names the offending class. This guards the tool integration (phar
 * present + runnable, config API stable, exit-code contract) that
 * includes/generic/phpArkitect.inc.bash relies on.
 *
 * @internal
 */
#[Large]
#[CoversNothing]
final class ArkitectCheckTest extends TestCase
{
    private const string PHAR = __DIR__ . '/../../../vendor-phar/phparkitect.phar';

    private const string ASSETS = __DIR__ . '/../../assets/arkitect';

    public function testValidProjectPasses(): void
    {
        [$exitCode, $output] = $this->runCheck(self::ASSETS . '/projectValid');

        self::assertSame(0, $exitCode, "Expected no violations, got:\n" . $output);
        self::assertStringContainsString('No violations detected', $output);
    }

    public function testInvalidProjectFailsAndNamesViolation(): void
    {
        [$exitCode, $output] = $this->runCheck(self::ASSETS . '/projectInvalid');

        self::assertSame(1, $exitCode, "Expected a violation, got:\n" . $output);
        // The offending class violates the *Controller naming rule.
        self::assertStringContainsString('Home', $output);
        self::assertStringContainsString('should have a name that matches', $output);
    }

    /**
     * A project that EXTENDS the shipped default rule set inherits the house
     * naming conventions: an interface not suffixed *Interface is rejected,
     * proving PHPQACI_ARKITECT_RULES_DEFAULT wiring works end-to-end.
     */
    public function testShippedDefaultRulesAreEnforcedWhenExtended(): void
    {
        $defaultRules = __DIR__ . '/../../../configDefaults/generic/phparkitect-rules-default.php';

        [$exitCode, $output] = $this->runCheck(
            self::ASSETS . '/projectExtendsDefaults',
            ['PHPQACI_ARKITECT_RULES_DEFAULT' => $defaultRules],
        );

        self::assertSame(1, $exitCode, "Expected a default-rule violation, got:\n" . $output);
        self::assertStringContainsString('Repository', $output);
        self::assertStringContainsString('*Interface', $output);
    }

    /**
     * A project that OPTS IN to the stricter optional tier inherits its rules:
     * an abstract class not prefixed Abstract* is rejected. Proves the
     * PHPQACI_ARKITECT_RULES_OPTIONAL wiring works end-to-end.
     */
    public function testOptionalTierIsEnforcedWhenOptedIn(): void
    {
        $optionalRules = __DIR__ . '/../../../configDefaults/generic/phparkitect-rules-optional.php';

        [$exitCode, $output] = $this->runCheck(
            self::ASSETS . '/projectOptional',
            ['PHPQACI_ARKITECT_RULES_OPTIONAL' => $optionalRules],
        );

        self::assertSame(1, $exitCode, "Expected an optional-tier violation, got:\n" . $output);
        self::assertStringContainsString('Base', $output);
        self::assertStringContainsString('Abstract*', $output);
    }

    /**
     * The migrated Throwable->*Exception convention (formerly accountsiq's
     * deleted ExceptionSuffixRule, now solely the optional tier's IsA rule) must
     * reject a \Throwable whose name does not end Exception — and must NOT flag a
     * correctly-suffixed one. This is the only behavioural coverage of that rule.
     */
    public function testOptionalTierEnforcesExceptionSuffix(): void
    {
        $optionalRules = __DIR__ . '/../../../configDefaults/generic/phparkitect-rules-optional.php';

        [$exitCode, $output] = $this->runCheck(
            self::ASSETS . '/projectOptionalException',
            ['PHPQACI_ARKITECT_RULES_OPTIONAL' => $optionalRules],
        );

        self::assertSame(1, $exitCode, "Expected an Exception-suffix violation, got:\n" . $output);
        self::assertStringContainsString('BadError', $output);
        self::assertStringContainsString('*Exception', $output);
        // The correctly-suffixed Throwable must not be reported (no false positive).
        self::assertStringNotContainsString('ValidPaymentException', $output);
    }

    /**
     * The default tier ships three node-kind rules (Interface/Enum/Trait). Beyond
     * the interface case above, prove the Enum and Trait suffix rules also fire —
     * all three were migrated from the deleted PHPStan RequireTypeSuffixRule.
     */
    public function testDefaultTierEnforcesEnumAndTraitSuffixes(): void
    {
        $defaultRules = __DIR__ . '/../../../configDefaults/generic/phparkitect-rules-default.php';

        [$exitCode, $output] = $this->runCheck(
            self::ASSETS . '/projectExtendsDefaults',
            ['PHPQACI_ARKITECT_RULES_DEFAULT' => $defaultRules],
        );

        self::assertSame(1, $exitCode, "Expected enum/trait violations, got:\n" . $output);
        self::assertStringContainsString('Status', $output);
        self::assertStringContainsString('*Enum', $output);
        self::assertStringContainsString('Helper', $output);
        self::assertStringContainsString('*Trait', $output);
    }

    /**
     * The production zero-config path: a project with NO qaConfig/phparkitect.php
     * still gets the default tier applied to its srcDir via the shipped entry
     * config, driven by PHPQACI_ARKITECT_SRC_DIR + PHPQACI_ARKITECT_RULES_DEFAULT.
     * Every other test uses a per-fixture config; this exercises the most-used
     * real path (the shipped configDefaults/generic/phparkitect.php).
     */
    public function testZeroConfigDefaultEntryAppliesDefaultTier(): void
    {
        $entryConfig  = __DIR__ . '/../../../configDefaults/generic/phparkitect.php';
        $defaultRules = __DIR__ . '/../../../configDefaults/generic/phparkitect-rules-default.php';

        [$exitCode, $output] = $this->runCheckConfig(
            $entryConfig,
            self::ASSETS . '/projectExtendsDefaults/autoload.php',
            [
                'PHPQACI_ARKITECT_SRC_DIR'       => self::ASSETS . '/projectExtendsDefaults/src',
                'PHPQACI_ARKITECT_RULES_DEFAULT' => $defaultRules,
            ],
        );

        self::assertSame(1, $exitCode, "Expected the shipped entry config to enforce the default tier, got:\n" . $output);
        self::assertStringContainsString('*Interface', $output);
    }

    /**
     * The shipped consumer API-boundary factory: a consumer that reaches the
     * library only through its public @api namespace passes. Proves the
     * PHPQACI_ARKITECT_CONSUMER_API_BOUNDARY factory wiring works end-to-end and
     * does not over-fire on legitimate facade-only usage.
     */
    public function testConsumerApiBoundaryAllowsPublicNamespaceOnly(): void
    {
        $factory = __DIR__ . '/../../../configDefaults/generic/phparkitect-consumer-api-boundary.php';

        [$exitCode, $output] = $this->runCheck(
            self::ASSETS . '/consumerBoundaryValid',
            ['PHPQACI_ARKITECT_CONSUMER_API_BOUNDARY' => $factory],
        );

        self::assertSame(0, $exitCode, "Expected facade-only usage to pass, got:\n" . $output);
        self::assertStringContainsString('No violations detected', $output);
    }

    /**
     * The same factory rejects a consumer that depends on the library's
     * @internal namespace, naming the offending class and explaining why.
     */
    public function testConsumerApiBoundaryRejectsInternalDependency(): void
    {
        $factory = __DIR__ . '/../../../configDefaults/generic/phparkitect-consumer-api-boundary.php';

        [$exitCode, $output] = $this->runCheck(
            self::ASSETS . '/consumerBoundaryInvalid',
            ['PHPQACI_ARKITECT_CONSUMER_API_BOUNDARY' => $factory],
        );

        self::assertSame(1, $exitCode, "Expected an internal-dependency violation, got:\n" . $output);
        self::assertStringContainsString('BadService', $output);
        self::assertStringContainsString('Acme\Widget\Internal', $output);
    }

    /**
     * @param array<string, string> $env extra environment for the phar process
     *
     * @return array{0: int, 1: string}
     */
    private function runCheck(string $projectDir, array $env = []): array
    {
        return $this->runCheckConfig($projectDir . '/phparkitect.php', $projectDir . '/autoload.php', $env);
    }

    /**
     * @param array<string, string> $env extra environment for the phar process
     *
     * @return array{0: int, 1: string}
     */
    private function runCheckConfig(string $configPath, string $autoloadPath, array $env = []): array
    {
        $envPrefix = '';
        foreach ($env as $name => $value) {
            $envPrefix .= $name . '=' . escapeshellarg($value) . ' ';
        }

        $cmd = $envPrefix . \sprintf(
            '%s -f %s -- check --config=%s --autoload=%s --no-interaction --no-ansi 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(self::PHAR),
            escapeshellarg($configPath),
            escapeshellarg($autoloadPath),
        );

        $output   = [];
        $exitCode = 0;
        // \Safe\exec's by-ref params are typed loosely by the stubs ($output as
        // untyped array, $exitCode nullable); it throws on failure, so on return
        // they are populated. Rebuild a string output with a type-safe guard
        // (exec lines are always strings) for a clean array{int, string} return.
        \Safe\exec($cmd, $output, $exitCode);

        $text = '';
        foreach ($output ?? [] as $line) {
            if (\is_string($line)) {
                $text .= $line . "\n";
            }
        }

        return [$exitCode ?? 1, $text];
    }
}
