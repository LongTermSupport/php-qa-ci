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
    private const PHAR = __DIR__ . '/../../../vendor-phar/phparkitect.phar';

    private const ASSETS = __DIR__ . '/../../assets/arkitect';

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
     * @return array{0: int, 1: string}
     */
    private function runCheck(string $projectDir): array
    {
        $cmd = \sprintf(
            '%s -f %s -- check --config=%s --autoload=%s --no-interaction --no-ansi 2>&1',
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg(self::PHAR),
            \escapeshellarg($projectDir . '/phparkitect.php'),
            \escapeshellarg($projectDir . '/autoload.php'),
        );

        $output   = [];
        $exitCode = 0;
        \exec($cmd, $output, $exitCode);

        return [$exitCode, \implode("\n", $output)];
    }
}
