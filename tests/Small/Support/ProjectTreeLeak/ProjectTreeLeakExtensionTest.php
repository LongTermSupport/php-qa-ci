<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Support\ProjectTreeLeak;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Through the entry point: a PHPUnit run under this repository's own
 * configuration, the one Infection's mutant runs use, whose only test writes
 * into the working directory (the project root, as under Infection) and
 * passes. The run must exit non-zero, which is what makes Infection count the
 * mutant killed, name the test and the path, and leave the tree as it was.
 *
 * @internal
 */
#[CoversNothing]
#[Medium]
final class ProjectTreeLeakExtensionTest extends TestCase
{
    private string $root;

    private string $probe;

    protected function setUp(): void
    {
        $this->root  = \dirname(__DIR__, 4);
        $this->probe = 'project-tree-leak-probe-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_file($this->root . '/' . $this->probe)) {
            \Safe\unlink($this->root . '/' . $this->probe);
        }

        foreach (\Safe\glob($this->root . '/var/qa/project-tree-leaks/*/*/' . $this->probe) as $kept) {
            self::assertIsString($kept);
            \Safe\unlink($kept);
            \Safe\rmdir(\dirname($kept));
            $processDirectory = \dirname($kept, 2);
            if (['.', '..'] === \Safe\scandir($processDirectory)) {
                \Safe\rmdir($processDirectory);
            }
        }

        foreach ($this->childTempDirectories() as $directory) {
            foreach (\Safe\glob($directory . '/*') as $left) {
                self::assertIsString($left);
                \Safe\unlink($left);
            }

            \Safe\rmdir($directory);
        }
    }

    #[Test]
    public function aPassingTestThatWritesIntoTheProjectTreeFailsTheRunAndLeavesTheTreeClean(): void
    {
        $process = $this->runFixture('WritesIntoTheWorkingDirectoryTest.php');

        self::assertStringContainsString('OK (1 test', $process->getOutput(), 'the test itself passes');
        self::assertSame(1, $process->getExitCode(), 'the extension fails the run: ' . $process->getErrorOutput());
        // The short name: Rector resolves the fixture class, PHPStan (which excludes tests/assets) cannot.
        self::assertStringContainsString(
            '\WritesIntoTheWorkingDirectoryTest::writesARelativePath wrote ' . $this->probe . ' into the project tree',
            $process->getErrorOutput(),
        );
        self::assertFileDoesNotExist($this->root . '/' . $this->probe);
    }

    /**
     * exit() in a shutdown function cancels every shutdown function after it, so this
     * extension's verdict must not cost TempLeakExtension, listed after it, its report
     * or the removal of its directory (issue #137).
     */
    #[Test]
    public function aTestThatLeaksIntoTheTreeAndTheTempDirectoryIsReportedByBothExtensions(): void
    {
        $process = $this->runFixture('WritesIntoTheTreeAndTheTempDirectoryTest.php');

        self::assertStringContainsString('OK (1 test', $process->getOutput(), 'the test itself passes');
        self::assertSame(1, $process->getExitCode(), 'the extensions fail the run: ' . $process->getErrorOutput());
        // The short name: Rector resolves the fixture class, PHPStan (which excludes tests/assets) cannot.
        $test = '\WritesIntoTheTreeAndTheTempDirectoryTest::writesARelativePathAndLeavesATempFile';
        self::assertStringContainsString($test . ' wrote ' . $this->probe . ' into the project tree', $process->getErrorOutput());
        self::assertStringContainsString('Tests left files in the temp directory', $process->getErrorOutput());
        self::assertStringContainsString($test . ' left ' . $this->probe, $process->getErrorOutput());
        self::assertFileDoesNotExist($this->root . '/' . $this->probe);
        self::assertSame([], $this->childTempDirectories(), 'TempLeakExtension removed its directory');
    }

    private function runFixture(string $fixture): Process
    {
        $process = new Process(
            [
                \PHP_BINARY,
                $this->root . '/bin/phpunit',
                '--configuration=' . $this->root . '/qaConfig/phpunit.xml',
                '--no-coverage',
                '--no-logging',
                '--do-not-record-test-run-history',
                $this->root . '/tests/assets/projectTreeLeak/' . $fixture,
            ],
            $this->root,
            ['PROJECT_TREE_LEAK_PROBE' => $this->probe, 'XDEBUG_MODE' => 'off'],
        );
        $process->run();

        return $process;
    }

    /**
     * The child run's TMPDIR is this process's temp directory, so the child claims its own
     * directory inside it; one left behind means TempLeakExtension's shutdown never ran.
     *
     * @return list<string>
     */
    private function childTempDirectories(): array
    {
        return array_values(array_filter(
            \Safe\glob(sys_get_temp_dir() . '/php-qa-ci-tests-*'),
            \is_string(...),
        ));
    }
}
