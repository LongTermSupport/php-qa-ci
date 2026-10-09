<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Process;

use LTS\PHPQA\Pipeline\Process\ProcessTree;
use LTS\PHPQA\Tests\Support\VanishingProcStreamWrapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A process that exits between ProcessTree's check of its stat file and the
 * read is gone, which is an answer. Reaching that answer must not raise a PHP
 * warning: under PHPUnit's failOnWarning it fails a green run, and in a
 * consumer's interrupted run it is printed to the terminal.
 *
 * @internal
 */
#[CoversClass(ProcessTree::class)]
#[Small]
final class ProcessTreeVanishingStatTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        \Safe\stream_wrapper_register(VanishingProcStreamWrapper::SCHEME, VanishingProcStreamWrapper::class);
        \Safe\stream_wrapper_register(VanishingProcStreamWrapper::UNREADABLE_SCHEME, VanishingProcStreamWrapper::class);
        set_error_handler(function (int $level, string $message): bool {
            $this->warnings[] = $message;

            return true;
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        \Safe\stream_wrapper_unregister(VanishingProcStreamWrapper::SCHEME);
        \Safe\stream_wrapper_unregister(VanishingProcStreamWrapper::UNREADABLE_SCHEME);
    }

    #[Test]
    public function aProcessThatExitsDuringTheReadIsGoneWithoutAWarning(): void
    {
        $alive = new ProcessTree(VanishingProcStreamWrapper::SCHEME . '://proc')->isAlive(4242);

        self::assertFalse($alive);
        self::assertSame([], $this->warnings);
    }

    /** Its /proc entry still there, the process has not gone: the failure is real and is not swallowed. */
    #[Test]
    public function aStatFileThatCannotBeReadForARunningProcessIsAnError(): void
    {
        $thrown = null;
        try {
            new ProcessTree(VanishingProcStreamWrapper::UNREADABLE_SCHEME . '://proc')->isAlive(4242);
        } catch (RuntimeException $runtimeException) {
            $thrown = $runtimeException;
        }

        self::assertInstanceOf(RuntimeException::class, $thrown);
        self::assertSame([], $this->warnings);
    }
}
