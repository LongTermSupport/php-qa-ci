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
     * proving PHPQACI_ARKITECT_DEFAULT_RULES wiring works end-to-end.
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
     * @param array<string, string> $env extra environment for the phar process
     *
     * @return array{0: int, 1: string}
     */
    private function runCheck(string $projectDir, array $env = []): array
    {
        $envPrefix = '';
        foreach ($env as $name => $value) {
            $envPrefix .= $name . '=' . escapeshellarg($value) . ' ';
        }

        $cmd = $envPrefix . \sprintf(
            '%s -f %s -- check --config=%s --autoload=%s --no-interaction --no-ansi 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(self::PHAR),
            escapeshellarg($projectDir . '/phparkitect.php'),
            escapeshellarg($projectDir . '/autoload.php'),
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
