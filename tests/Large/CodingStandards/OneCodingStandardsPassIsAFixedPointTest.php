<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\CodingStandards;

use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Defence for the class "one writable allCS pass is not a fixed point": the shipped
 * Rector configs and the shipped PHP CS Fixer config disagree, and because the fixer
 * runs after Rector, Rector never sees what the fixer did. The read-only run that
 * follows (CI) then fails on a tree a writable run has just fixed (#193).
 *
 * The corpus holds every class-like kind, with the member kinds the fixer orders and
 * separates written in the reverse of the shipped order and without blank lines, so
 * every reorder and every separation the fixer makes is one Rector then reads.
 * One writable pass, run as the rector and phpCsFixer lanes run it, must leave
 * nothing for either tool's read-only check.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class OneCodingStandardsPassIsAFixedPointTest extends TestCase
{
    private const string REPO_ROOT = __DIR__ . '/../../..';

    private const string CORPUS = self::REPO_ROOT . '/tests/assets/codingStandardsFixedPoint';

    private const string DRY_RUN = '--dry-run';

    /** The Rector configs the rector lane runs over source paths, in its order. */
    private const array RECTOR_CONFIGS = ['rector-safe.php', 'rector-php85.php'];

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-cs-fixed-point');
        $this->project->write('composer.json', '{"name": "probe/probe", "type": "project", "require": {"php": "^8.5", "thecodingmachine/safe": "^3.0"},"autoload": {"psr-4": {"Probe\\\\": "src/"}}}');
        foreach (\Safe\scandir(self::CORPUS) as $name) {
            if (str_ends_with($name, '.php')) {
                $this->project->write('src/' . $name, \Safe\file_get_contents(self::CORPUS . '/' . $name));
            }
        }
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function oneWritablePassLeavesNothingForTheReadOnlyChecks(): void
    {
        foreach (self::RECTOR_CONFIGS as $config) {
            $this->assertTool($this->rector($config), [0], 'Rector ' . $config . ' could not process the corpus');
        }

        $this->assertTool($this->fixer(), [0, 8], 'PHP CS Fixer could not process the corpus');

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

        self::assertSame([], $pending, 'After one writable Rector + PHP CS Fixer pass, the read-only checks still have changes to make, so the shipped configs disagree.');
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
            ...['src'],
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
            ...['src'],
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

    /** @param list<int> $passing */
    private function assertTool(Process $process, array $passing, string $message): void
    {
        self::assertContains($process->getExitCode(), $passing, $message . ":\n" . $process->getOutput() . $process->getErrorOutput());
    }
}
