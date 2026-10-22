<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Psr4Validate;

use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * bin/psr4-validate as a user runs it, from the directory it is to check. The
 * command's own tests cannot see what the binary hands it as the project root.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class Psr4ValidateBinaryTest extends TestCase
{
    private const string BINARY = __DIR__ . '/../../../bin/psr4-validate';

    private TempDir $directory;

    protected function setUp(): void
    {
        $this->directory = TempDir::create('phpqa-psr4-validate-binary');
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    #[Test]
    public function aDirectoryWithoutAComposerJsonIsRefusedAndFails(): void
    {
        $process = $this->binary();

        self::assertSame(1, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertStringContainsString('No composer.json in ' . $this->directory->path . '; run psr4-validate from the project root.', $process->getOutput());
    }

    #[Test]
    public function theHelpPrintsWhereverItIsRun(): void
    {
        $process = $this->binary('--help');

        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertStringStartsWith('Usage: psr4-validate [<ignore-pattern> ...]', $process->getOutput());
    }

    private function binary(string ...$arguments): Process
    {
        $process = new Process(
            [\PHP_BINARY, '-d', 'display_errors=stderr', \Safe\realpath(self::BINARY), ...$arguments],
            $this->directory->path,
        );
        $process->run();

        return $process;
    }
}
