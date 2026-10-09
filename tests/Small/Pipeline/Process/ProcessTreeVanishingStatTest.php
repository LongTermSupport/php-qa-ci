<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Process;

use LTS\PHPQA\Pipeline\Process\ProcessTree;
use LTS\PHPQA\Tests\Support\VanishingProcStreamWrapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
    protected function setUp(): void
    {
        \Safe\stream_wrapper_register(VanishingProcStreamWrapper::SCHEME, VanishingProcStreamWrapper::class);
    }

    protected function tearDown(): void
    {
        \Safe\stream_wrapper_unregister(VanishingProcStreamWrapper::SCHEME);
    }

    #[Test]
    public function aProcessThatExitsDuringTheReadIsGoneWithoutAWarning(): void
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $alive = new ProcessTree(VanishingProcStreamWrapper::SCHEME . '://proc')->isAlive(4242);
        } finally {
            restore_error_handler();
        }

        self::assertFalse($alive);
        self::assertSame([], $warnings);
    }
}
