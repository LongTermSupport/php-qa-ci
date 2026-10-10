<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Process;

use LTS\PHPQA\Pipeline\Process\ProcessTree;
use LTS\PHPQA\Tests\Support\ExitingProcStreamWrapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A process that exits after ProcessTree has opened its stat file and before
 * the read finds nothing to read: the kernel's read fails with a notice, or
 * comes back empty. If the process's /proc entry has gone, the process is
 * gone, which is an answer, and reaching it must raise no notice: under
 * PHPUnit's failOnNotice it fails a green run, and in a consumer's
 * interrupted run it is printed to the terminal. If the entry is still there
 * the read is a real failure, and neither "alive" nor "gone" is true.
 *
 * @internal
 */
#[CoversClass(ProcessTree::class)]
#[Small]
final class ProcessTreeExitingStatTest extends TestCase
{
    private const array SCHEMES = [
        ExitingProcStreamWrapper::GONE_FAILED_READ_SCHEME,
        ExitingProcStreamWrapper::GONE_EMPTY_READ_SCHEME,
        ExitingProcStreamWrapper::RUNNING_FAILED_READ_SCHEME,
        ExitingProcStreamWrapper::RUNNING_EMPTY_READ_SCHEME,
    ];

    /** @var list<string> */
    private array $notices = [];

    protected function setUp(): void
    {
        foreach (self::SCHEMES as $scheme) {
            \Safe\stream_wrapper_register($scheme, ExitingProcStreamWrapper::class);
        }

        set_error_handler(function (int $level, string $message): bool {
            $this->notices[] = $message;

            return true;
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        foreach (self::SCHEMES as $scheme) {
            \Safe\stream_wrapper_unregister($scheme);
        }
    }

    #[Test]
    #[DataProvider('goneProcesses')]
    public function aProcessThatExitsBetweenTheOpenAndTheReadIsGoneWithoutANotice(string $scheme): void
    {
        $alive = new ProcessTree($scheme . '://proc')->isAlive(4242);

        self::assertSame([], $this->notices);
        self::assertFalse($alive);
    }

    /** @return iterable<string, array{string}> */
    public static function goneProcesses(): iterable
    {
        yield 'the read fails' => [ExitingProcStreamWrapper::GONE_FAILED_READ_SCHEME];
        yield 'the read is empty' => [ExitingProcStreamWrapper::GONE_EMPTY_READ_SCHEME];
    }

    /** Its /proc entry still there, the process has not gone: the failed read is real and is not swallowed. */
    #[Test]
    #[DataProvider('runningProcesses')]
    public function aStatFileThatReadsNothingForARunningProcessIsAnError(string $scheme): void
    {
        $thrown = null;
        try {
            new ProcessTree($scheme . '://proc')->isAlive(4242);
        } catch (RuntimeException $runtimeException) {
            $thrown = $runtimeException;
        }

        self::assertSame([], $this->notices);
        self::assertInstanceOf(RuntimeException::class, $thrown);
        self::assertStringContainsString($scheme . '://proc/4242/stat', $thrown->getMessage());
    }

    /** @return iterable<string, array{string}> */
    public static function runningProcesses(): iterable
    {
        yield 'the read fails' => [ExitingProcStreamWrapper::RUNNING_FAILED_READ_SCHEME];
        yield 'the read is empty' => [ExitingProcStreamWrapper::RUNNING_EMPTY_READ_SCHEME];
    }
}
