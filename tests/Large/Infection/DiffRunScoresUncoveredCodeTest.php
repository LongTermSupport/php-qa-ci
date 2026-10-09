<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Infection;

use LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto;
use LTS\PHPQA\Pipeline\Lane\Infection\InfectionArguments;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The diff lane's argv, run by the bundled Infection against coverage of a
 * fixture: a changed file no test runs must fail the diff floor rather than
 * generate no mutant and pass, while a changed file with no mutable code (an
 * interface) passes and a tested one passes.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class DiffRunScoresUncoveredCodeTest extends TestCase
{
    private const string LIBRARY = __DIR__ . '/../../..';

    private const string FIXTURE = __DIR__ . '/../../assets/infection/diffScope';

    private const array FIXTURE_FILES = ['bootstrap.php', 'phpunit.xml', 'src/Shape.php', 'src/Tested.php', 'src/Untested.php', 'tests/TestedTest.php'];

    private TempDir $project;

    protected function setUp(): void
    {
        if (!\extension_loaded('xdebug')) {
            self::markTestSkipped('the fixture coverage needs Xdebug');
        }

        $this->project = TempDir::create('phpqa-diffscope');
        $fixture       = \Safe\realpath(self::FIXTURE);
        foreach (self::FIXTURE_FILES as $file) {
            $this->project->write($file, \Safe\file_get_contents($fixture . '/' . $file));
        }

        $phpunit = \Safe\realpath(self::LIBRARY . '/bin/phpunit');
        $this->project->write('infection.json', \Safe\json_encode([
            'source'    => ['directories' => ['src']],
            'bootstrap' => 'bootstrap.php',
            'logs'      => ['text' => 'out/log.txt', 'summary' => 'out/summary-log.txt'],
            'phpUnit'   => ['configDir' => '.', 'customPath' => $phpunit],
            'tmpDir'    => 'out/tmp',
        ], \JSON_UNESCAPED_SLASHES));

        $coverage = new Process(
            [\PHP_BINARY, $phpunit, '-c', 'phpunit.xml', '--coverage-xml', $this->project->path . '/logs/coverage-xml', '--log-junit', $this->project->path . '/logs/phpunit.junit.xml'],
            $this->project->path,
            ['XDEBUG_MODE' => 'coverage'],
            null,
            300,
        );
        $coverage->run();
        self::assertTrue($coverage->isSuccessful(), 'the fixture coverage run failed: ' . $coverage->getOutput() . $coverage->getErrorOutput());
        self::assertDirectoryExists($this->project->path . '/logs/coverage-xml', 'the fixture coverage run wrote no coverage');
    }

    protected function tearDown(): void
    {
        if (isset($this->project)) {
            $this->project->remove();
        }
    }

    #[Test]
    public function aChangedFileNoTestRunsFailsTheDiffFloor(): void
    {
        $process = $this->infect('Untested.php');

        self::assertSame(1, $process->getExitCode(), $process->getOutput());
        self::assertStringContainsString('3 mutants were not covered by tests', $process->getOutput());
        self::assertStringContainsString('The minimum required MSI percentage should be 80%, but actual is 0%', $process->getOutput());
    }

    #[Test]
    public function aChangedFileWithNoMutableCodePasses(): void
    {
        $process = $this->infect('Shape.php');

        self::assertSame(0, $process->getExitCode(), $process->getOutput());
        self::assertStringContainsString('No mutations were generated for the selected code.', $process->getOutput());
    }

    #[Test]
    public function aChangedFileWhoseMutantsTheTestsKillPasses(): void
    {
        $process = $this->infect('Tested.php');

        self::assertSame(0, $process->getExitCode(), $process->getOutput());
        self::assertStringContainsString('Mutation Score Indicator (MSI): 100%', $process->getOutput());
    }

    private function infect(string $changedFile): Process
    {
        $options = new InfectionOptionsDto(
            enabled: true,
            threads: 1,
            onlyCovered: false,
            minMsi: 60,
            minCoveredMsi: 80,
            diffBase: 'origin/main',
            diffCoveredMsi: 80,
        );
        $root = $this->project->path;
        $argv = new InfectionArguments()->diff($options, $root . '/logs', $root . '/infection.json', $root . '/src/' . $changedFile);

        $process = new Process(
            [\PHP_BINARY, \Safe\realpath(self::LIBRARY . '/vendor-phar/infection.phar'), ...$argv, '--no-progress', '--no-ansi'],
            $root,
            ['XDEBUG_MODE' => 'off'],
            null,
            300,
        );
        $process->run();

        return $process;
    }
}
