<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Opcache;

use LTS\PHPQA\Pipeline\Config\OpcacheDefects;
use LTS\PHPQA\Pipeline\Lane\Opcache\DumpParser;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Defence for the class "the declared affected range drifts from the PHP in use".
 *
 * `OpcacheDefects::FIRST_FIXED` is a claim about upstream: that no release before it
 * carries the fix for GH-23644. Nothing in the repository can know when that stops
 * being true — the release happens elsewhere — so left to a reminder, the lane and
 * the start-up advisory would keep firing on a fixed PHP until somebody remembered.
 *
 * This test asks the running PHP instead of the constant. It compiles the exact
 * shape filed upstream through the real optimizer (compile only, never executed:
 * on an affected PHP executing it segfaults), and requires the defect's fingerprint
 * to be present exactly when the constants say this PHP is affected:
 *
 *   - constants say affected, fingerprint absent  → FIRST_FIXED is stale; lower it
 *   - constants say fixed, fingerprint present     → FIRST_FIXED is wrong; raise it
 *
 * Both are failures, because both mean the advisory is lying to a host.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class OpcacheDefectRangeTest extends TestCase
{
    /** The package's own dump script and fixture, so this measures what the lane sees. */
    private const string REPO_ROOT = __DIR__ . '/../../..';

    /** The six lines filed as GH-23644, compiled here and never run. */
    private const string FIXTURE = self::REPO_ROOT . '/tests/assets/opcache/gh23644-shape.php';

    /** bin/opcache-optimizer-dump's exit when OPcache is not loaded: a skip, not a verdict. */
    private const int EXIT_NO_OPCACHE = 2;

    #[Test]
    public function theDeclaredRangeAgreesWithWhatTheRunningPhpActuallyDoes(): void
    {
        // The same ini the opcache lane compiles with, so this measures what the lane sees.
        $process = new Process([
            \PHP_BINARY,
            '-d', 'opcache.enable_cli=1',
            '-d', \sprintf('opcache.optimization_level=0x%X', OpcacheDefects::DEFAULT_LEVEL),
            '-d', 'opcache.opt_debug_level=0x20000',
            '-d', 'opcache.file_update_protection=0',
            '-f', self::REPO_ROOT . '/bin/opcache-optimizer-dump',
            '--',
            self::FIXTURE,
        ], self::REPO_ROOT, ['XDEBUG_MODE' => 'off'], null, 60);
        $process->run();

        if (self::EXIT_NO_OPCACHE === $process->getExitCode()) {
            self::markTestSkipped('OPcache is not loadable for this CLI, so the optimizer cannot be asked');
        }

        self::assertTrue($process->isSuccessful(), 'the optimizer dump must succeed: ' . $process->getErrorOutput());

        $findings = new DumpParser()->parse($process->getErrorOutput());
        $affected = OpcacheDefects::affects(\PHP_VERSION);

        if ($affected) {
            self::assertNotSame([], $findings, \sprintf(
                'OpcacheDefects says PHP %s is affected, but its optimizer no longer leaves the GH-23644 shape '
                . 'with two constant operands. The fix has shipped: set FIRST_FIXED to the first release that '
                . 'carries https://github.com/php/php-src/pull/23648 (at most %1$s), or the opcache lane and '
                . 'the start-up advisory keep firing on a PHP that is fine.',
                \PHP_VERSION,
            ));

            return;
        }

        self::assertSame([], $findings, \sprintf(
            'OpcacheDefects says PHP %s is fixed, but its optimizer still leaves the GH-23644 shape with two '
            . 'constant operands. FIRST_FIXED is set too low: this PHP is exposed and the advisory is silent.',
            \PHP_VERSION,
        ));
    }
}
