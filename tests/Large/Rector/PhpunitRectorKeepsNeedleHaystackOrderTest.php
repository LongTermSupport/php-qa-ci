<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Rector;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Defence for the class "the shipped PHPUnit Rector configuration rewrites a
 * correct needle/haystack assertion".
 *
 * Rector's FlipAssertRector moves a constant or scalar argument to the left as
 * the "expected" value, which turns assertStringContainsString($needle,
 * self::HAYSTACK) into its inverse (issue #36). The shipped config skips that
 * rule, but a config edit or a Rector update adding a rule with the same effect
 * would bring the inversion back silently. This runs the shipped rector.phar
 * with the shipped rector-phpunit.php in dry-run, as the rector lane does, over
 * a fixture of correct needle/haystack assertions whose haystack is a constant
 * or literal, and requires that Rector proposes no change to it.
 *
 * A positive control sits in the same directory and must be the only file
 * changed: it proves the run loaded the configuration and reached the fixtures,
 * so an empty verdict cannot come from Rector skipping them.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class PhpunitRectorKeepsNeedleHaystackOrderTest extends TestCase
{
    /** The package's own checkout, whose shipped PHAR and config are under test. */
    private const string REPO_ROOT = __DIR__ . '/../../..';

    /** Repo-relative, because Rector reports changed files the way it was given them. */
    private const string FIXTURE_DIR = 'tests/assets/rectorPhpunit';

    /** Correct needle/haystack assertions; any change to this file is the defect. */
    private const string NEEDLE_HAYSTACK_FIXTURE = self::FIXTURE_DIR . '/NeedleHaystackAssertionsTest.php';

    /** The positive control, which the shipped config must rewrite. */
    private const string CONTROL_FIXTURE = self::FIXTURE_DIR . '/AnnotationControlTest.php';

    /** Rector's dry-run exit when it has changes to propose. */
    private const int EXIT_PENDING_CHANGES = 2;

    #[Test]
    public function theShippedPhpunitConfigLeavesCorrectNeedleHaystackAssertionsAlone(): void
    {
        $fixture = self::REPO_ROOT . '/' . self::NEEDLE_HAYSTACK_FIXTURE;
        $before  = \Safe\file_get_contents($fixture);

        // Rector caches under TMPDIR; give it a private one, as the lane does.
        $tmpDir = self::REPO_ROOT . '/var/qa/cache/rector-needle-haystack-test';
        if (!is_dir($tmpDir)) {
            \Safe\mkdir($tmpDir, 0o777, true);
        }

        $process = new Process([
            \PHP_BINARY,
            self::REPO_ROOT . '/vendor-phar/rector.phar',
            'process',
            '--autoload-file',
            self::REPO_ROOT . '/vendor/autoload.php',
            '--config',
            self::REPO_ROOT . '/configDefaults/generic/rector-phpunit.php',
            '--clear-cache',
            '--dry-run',
            '--no-progress-bar',
            '--output-format=json',
            self::FIXTURE_DIR,
        ], self::REPO_ROOT, ['XDEBUG_MODE' => 'off', 'TMPDIR' => $tmpDir, 'rectorIgnorePaths' => ''], null, 240);
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();
        self::assertSame($before, \Safe\file_get_contents($fixture), 'a --dry-run Rector must never write the fixture');
        self::assertSame(self::EXIT_PENDING_CHANGES, $process->getExitCode(), "Rector must propose exactly the control's change:\n" . $output);

        $report = \Safe\json_decode($process->getOutput(), true);
        self::assertIsArray($report);
        self::assertSame([self::CONTROL_FIXTURE], $report['changed_files'], \sprintf(
            "The shipped configDefaults/generic/rector-phpunit.php must change only %s (the positive control).\n"
            . 'If %s is listed, a rule rewrote a correct (needle, haystack) assertion, swapping the two and '
            . "silently inverting it (issue #36): skip the rule named in applied_rectors in rector-phpunit.php.\n"
            . "If the control is missing, the run never reached the fixtures and proves nothing.\n"
            . "Rector output:\n%s",
            self::CONTROL_FIXTURE,
            self::NEEDLE_HAYSTACK_FIXTURE,
            $output,
        ));
    }
}
