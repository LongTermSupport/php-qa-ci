<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Process;

use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\ProcessTree;
use LTS\PHPQA\Pipeline\Process\RunningProcesses;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 */
#[CoversClass(SymfonyProcessRunner::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(RunningProcesses::class)]
#[UsesClass(ProcessTree::class)]
#[Small]
final class SymfonyProcessRunnerTest extends TestCase
{
    private const string XDEBUG_MODE = 'XDEBUG_MODE';

    private const string PRINT_MODE = 'echo getenv("XDEBUG_MODE");';

    private const string OFF = 'off';

    private const string COVERAGE = 'coverage';

    #[Test]
    public function aChildThatNamesNoXdebugModeGetsOffWhateverTheParentHas(): void
    {
        $env = SymfonyProcessRunner::childEnvironment([self::XDEBUG_MODE => self::COVERAGE, 'FOO' => 'bar'], []);

        self::assertSame(self::OFF, $env[self::XDEBUG_MODE]);
        self::assertSame('bar', $env['FOO']);
    }

    #[Test]
    public function aChildThatNamesAnXdebugModeKeepsIt(): void
    {
        $env = SymfonyProcessRunner::childEnvironment([self::XDEBUG_MODE => self::OFF], [self::XDEBUG_MODE => self::COVERAGE]);

        self::assertSame(self::COVERAGE, $env[self::XDEBUG_MODE]);
    }

    #[Test]
    public function aRealChildWithNoEnvironmentOfItsOwnSeesXdebugOff(): void
    {
        $result = new SymfonyProcessRunner(new NullOutput(), new RunningProcesses())
            ->run(new ProcessSpecDto([\PHP_BINARY, '-r', self::PRINT_MODE], \Safe\getcwd(), streamOutput: false))
        ;

        self::assertSame(self::OFF, trim($result->output));
    }

    #[Test]
    public function aRealChildAskingForCoverageSeesCoverage(): void
    {
        $result = new SymfonyProcessRunner(new NullOutput(), new RunningProcesses())
            ->run(new ProcessSpecDto([\PHP_BINARY, '-r', self::PRINT_MODE], \Safe\getcwd(), [self::XDEBUG_MODE => self::COVERAGE], streamOutput: false))
        ;

        // A live Xdebug may print the JIT warning ahead of the answer; the answer is the last word.
        self::assertStringEndsWith(self::COVERAGE, trim($result->output));
    }
}
