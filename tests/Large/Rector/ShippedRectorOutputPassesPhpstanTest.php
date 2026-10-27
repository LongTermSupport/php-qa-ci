<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Rector;

use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Defence for the class "a shipped Rector config writes code that PHPStan rejects".
 *
 * PHPStan wins: a rewrite Rector makes that PHPStan then reports can never be made green,
 * because Rector redoes it on every writable run. Each file in
 * tests/assets/RectorVersusPhpstan/ is one known clash of that kind, valid under PHPStan
 * as written, with a comment naming the pair of rules that clash. The shipped configs are
 * run, writable, over the fixtures, in the lane's order, and PHPStan then analyses what
 * they wrote. A finding fails the test, naming the fixture. A new clash is a new fixture.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class ShippedRectorOutputPassesPhpstanTest extends TestCase
{
    /** The php-qa-ci checkout whose shipped configs and phars are under test. */
    private const string REPO_ROOT = __DIR__ . '/../../..';

    /** The fixtures: one file per known clash. */
    private const string FIXTURES = self::REPO_ROOT . '/tests/assets/RectorVersusPhpstan';

    /** The Rector configs the rector lane runs over source paths, in its order. */
    private const array RECTOR_CONFIGS = ['rector-safe.php', 'rector-php85.php'];

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-rector-vs-phpstan');
        $this->project->write('composer.json', '{"name": "probe/probe", "type": "project", "require": {"php": "^8.5", "thecodingmachine/safe": "^3.0"}}');
        foreach ($this->fixtures() as $path) {
            $this->project->write('src/' . basename($path), \Safe\file_get_contents($path));
        }
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function thereAreFixturesToCheck(): void
    {
        self::assertNotSame([], $this->fixtures(), 'tests/assets/RectorVersusPhpstan holds no fixture.');
    }

    #[Test]
    public function everyFixtureIsCleanUnderPhpstanBeforeRectorRuns(): void
    {
        self::assertSame([], $this->phpstanFindings(), 'A fixture is not valid PHP for PHPStan as written, so it proves nothing about Rector.');
    }

    #[Test]
    public function everyFixtureIsStillCleanUnderPhpstanAfterTheShippedRectorConfigs(): void
    {
        foreach (self::RECTOR_CONFIGS as $config) {
            $process = new Process(
                [\PHP_BINARY, '-d', 'display_errors=stderr', $this->pharPath('rector'), 'process', '--config', \Safe\realpath(self::REPO_ROOT . '/configDefaults/generic/' . $config), '--clear-cache', '--no-progress-bar', 'src'],
                $this->project->path,
                ['TMPDIR' => $this->project->path],
                null,
                600,
            );
            $process->run();
            self::assertContains($process->getExitCode(), [0, 2], 'Rector ' . $config . " could not process the fixtures:\n" . $process->getOutput() . $process->getErrorOutput());
        }

        $findings = $this->phpstanFindings();

        self::assertSame([], $findings, 'A shipped Rector config wrote code PHPStan rejects (PHPStan wins, adjust Rector). Failing fixtures: ' . implode(', ', array_unique(array_map(static fn (string $finding): string => explode(':', $finding, 2)[0], $findings))));
    }

    /**
     * @return list<string> the absolute path of each fixture, in name order
     */
    private function fixtures(): array
    {
        $found = array_values(array_filter(\Safe\glob(self::FIXTURES . '/*.php'), is_string(...)));
        sort($found);

        return $found;
    }

    /**
     * Each finding as "<fixture>:<line>: <message> (<identifier>)", so a failure names the fixture.
     *
     * @return list<string>
     */
    private function phpstanFindings(): array
    {
        $process = new Process(
            [\PHP_BINARY, '-d', 'display_errors=stderr', $this->pharPath('phpstan'), 'analyse', '--level=max', '--no-progress', '--error-format=json', '--memory-limit=1G', 'src'],
            $this->project->path,
            ['TMPDIR' => $this->project->path],
            null,
            600,
        );
        $process->run();

        $report = \Safe\json_decode($process->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($report, 'PHPStan wrote no JSON report: ' . $process->getErrorOutput());
        self::assertSame([], $report['errors'] ?? [], 'PHPStan reported internal errors.');

        $files = $report['files'] ?? [];
        self::assertIsArray($files);

        $findings = [];
        foreach ($files as $file => $details) {
            self::assertIsArray($details);
            $messages = $details['messages'] ?? [];
            self::assertIsArray($messages);
            foreach ($messages as $message) {
                self::assertIsArray($message);
                $identifier = $message['identifier'] ?? 'no identifier';
                self::assertIsScalar($message['line'] ?? null);
                self::assertIsScalar($message['message'] ?? null);
                self::assertIsScalar($identifier);
                $findings[] = basename((string)$file) . ':' . $message['line'] . ': ' . $message['message'] . ' (' . $identifier . ')';
            }
        }

        sort($findings);

        return $findings;
    }

    private function pharPath(string $tool): string
    {
        return \Safe\realpath(self::REPO_ROOT . '/vendor-phar/' . $tool . '.phar');
    }
}
