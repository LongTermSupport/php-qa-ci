<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\CodingStandards;

use LTS\PHPQA\Pipeline\Runner\RepeatUntilSettled;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/**
 * Defence for the class "a writable allCS run is not a fixed point": PHP CS Fixer
 * runs after Rector, so Rector never sees what the fixer did, and the read-only run
 * that follows (CI) fails on a tree a writable run has just fixed (#193). The fixer
 * can disagree with Rector (a reordered member left without the blank line Rector
 * wants) or create Rector's work (final_class makes a class final, and Rector's
 * final-class rules then apply).
 *
 * The corpus holds every class-like kind, a class that is not final, and the member
 * kinds the fixer orders and separates written in the reverse of the shipped order
 * without blank lines. The writable run is RepeatUntilSettled over the rector and
 * phpCsFixer lanes' invocations, as the coding-standards phase runs them; it must
 * leave nothing for either tool's read-only check.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class WritableCodingStandardsRunIsAFixedPointTest extends TestCase
{
    /** The php-qa-ci checkout whose shipped configs and phars are under test. */
    private const string REPO_ROOT = __DIR__ . '/../../..';

    /** The class-likes written against the shipped order, copied into the probe project. */
    private const string CORPUS = self::REPO_ROOT . '/tests/assets/codingStandardsFixedPoint';

    /** The option both tools take for their read-only check. */
    private const string DRY_RUN = '--dry-run';

    /** The probe project's checked path, which holds the corpus. */
    private const string SOURCE = 'src';

    /** The Rector configs the rector lane runs over source paths, in its order. */
    private const array RECTOR_CONFIGS = ['rector-safe.php', 'rector-php85.php'];

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-cs-fixed-point');
        $this->project->write('composer.json', '{"name": "probe/probe", "type": "project", "require": {"php": "^8.5", "thecodingmachine/safe": "^3.0"},"autoload": {"psr-4": {"Probe\\\": "src/"}}}');
        foreach (\Safe\glob(self::CORPUS . '/*.php') as $path) {
            self::assertIsString($path);
            $this->project->write('src/' . basename($path), \Safe\file_get_contents($path));
        }
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function aWritableRunLeavesNothingForTheReadOnlyChecks(): void
    {
        $output = new BufferedOutput();
        new RepeatUntilSettled($output)->run(function (): bool {
            foreach (self::RECTOR_CONFIGS as $config) {
                $this->assertTool($this->rector($config), 'Rector ' . $config . ' could not process the corpus', 0);
            }

            $this->assertTool($this->fixer(), 'PHP CS Fixer could not process the corpus', 0);

            return true;
        }, $this->project->path, self::SOURCE);

        self::assertStringNotContainsString('WARNING', $output->fetch(), 'the tools undo each other');

        $pending = [];
        foreach (self::RECTOR_CONFIGS as $config) {
            $process = $this->rector($config, self::DRY_RUN);
            if (0 !== $process->getExitCode()) {
                $pending[] = 'Rector ' . $config . ":\n" . $process->getOutput();
            }
        }

        $process = $this->fixer(self::DRY_RUN);
        if (0 !== $process->getExitCode()) {
            $pending[] = "PHP CS Fixer:\n" . $process->getOutput();
        }

        self::assertSame([], $pending, 'After a writable Rector + PHP CS Fixer run, the read-only checks still have changes to make.');
    }

    private function rector(string $config, string ...$options): Process
    {
        return $this->tool(
            'rector.phar',
            'process',
            '--config',
            \Safe\realpath(self::REPO_ROOT . '/configDefaults/generic/' . $config),
            '--clear-cache',
            '--no-progress-bar',
            ...$options,
            ...[self::SOURCE],
        );
    }

    private function fixer(string ...$options): Process
    {
        return $this->tool(
            'php-cs-fixer.phar',
            '--config=' . \Safe\realpath(self::REPO_ROOT . '/configDefaults/generic/php_cs.php'),
            '--using-cache=no',
            '--allow-risky=yes',
            'fix',
            ...$options,
            ...[self::SOURCE],
        );
    }

    private function tool(string $phar, string ...$arguments): Process
    {
        $process = new Process(
            [\PHP_BINARY, \Safe\realpath(self::REPO_ROOT . '/vendor-phar/' . $phar), ...$arguments],
            $this->project->path,
            ['TMPDIR' => $this->project->path],
            null,
            600,
        );
        $process->run();

        return $process;
    }

    private function assertTool(Process $process, string $message, int ...$passing): void
    {
        self::assertContains($process->getExitCode(), $passing, $message . ":\n" . $process->getOutput() . $process->getErrorOutput());
    }
}
