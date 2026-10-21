<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\DescribeExit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * bin/describe-exit is offered to a consumer for a script of their own, and is run by php-qa-ci's
 * Bash scripts for its own helpers. A segfault is reported where the crashing code lives, so only
 * the second names php-qa-ci's tracker, and only when the script says the helper is php-qa-ci's.
 *
 * The exit code is given as a number: no process is crashed.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class DescribeExitCommandTest extends TestCase
{
    private const string REPO_ROOT = __DIR__ . '/../../..';

    private const string COMMAND = self::REPO_ROOT . '/bin/describe-exit';

    private const string KILLED_BY_SIGSEGV = 'exit code 139, killed by signal 11 (SIGSEGV): it crashed with a segmentation fault, a defect to ';

    #[Test]
    public function aSegfaultInAProgramOfYourOwnPointsAtTheSegfaultGuide(): void
    {
        self::assertSame(
            self::KILLED_BY_SIGSEGV . 'debug and report where the crashing code lives, never to retry past (see ' . \Safe\realpath(self::REPO_ROOT) . "/docs/segfaults.md)\n",
            $this->describe('139')->getOutput(),
        );
    }

    #[Test]
    public function aSegfaultInAPhpQaCiHelperNamesPhpQaCisTracker(): void
    {
        self::assertSame(
            self::KILLED_BY_SIGSEGV . "report at https://github.com/LongTermSupport/php-qa-ci/issues and never to retry past\n",
            $this->describe('--php-qa-ci-helper', '139')->getOutput(),
        );
    }

    /** Every refused command line is enumerated in ChildExitTest; these are two of them. */
    #[Test]
    public function anyOtherArgumentsAreAUsageError(): void
    {
        foreach ([[], ['--other', '1']] as $arguments) {
            $process = $this->describe(...$arguments);

            self::assertSame(2, $process->getExitCode(), implode(' ', $arguments));
            self::assertSame("Usage: bin/describe-exit [--php-qa-ci-helper] <exit code 0-255>\n", $process->getErrorOutput(), implode(' ', $arguments));
        }
    }

    private function describe(string ...$arguments): Process
    {
        $process = new Process([\PHP_BINARY, '-d', 'display_errors=stderr', self::COMMAND, ...$arguments]);
        $process->run();

        return $process;
    }
}
