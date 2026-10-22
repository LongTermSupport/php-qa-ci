<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Process\Dto;

use LTS\PHPQA\ComposerPlugin\ChildExit;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Only the result knows whether a signal killed the child or the child exited with a signal's
 * code itself, so the line that reports how it ended is the result's to write.
 *
 * @internal
 */
#[CoversClass(ProcessResultDto::class)]
#[UsesClass(ChildExit::class)]
#[Small]
final class ProcessResultDtoTest extends TestCase
{
    #[Test]
    public function anOrdinaryExitIsItsCode(): void
    {
        self::assertSame('exit 1', new ProcessResultDto(1, '', '')->describeExit());
        self::assertSame('exit 255', new ProcessResultDto(255, '', '')->describeExit());
    }

    #[Test]
    public function aChildASignalKilledIsDescribedAsKilled(): void
    {
        self::assertSame('exit 143, killed by signal 15 (SIGTERM)', new ProcessResultDto(143, '', '', \SIGTERM)->describeExit());
        self::assertSame('exit 139, killed by signal 11 (SIGSEGV): ' . ChildExit::segfaultAdvice(), new ProcessResultDto(139, '', '', \SIGSEGV)->describeExit());
    }

    /** A wrapper that exits 139 relays a crash below it, and was not itself killed. */
    #[Test]
    public function aChildThatExitedWithASignalsCodeItselfIsNotCalledKilled(): void
    {
        self::assertSame(
            'exit 139, which it exited with itself: the code a shell gives for signal 11 (SIGSEGV), so a process below it crashed with a segmentation fault, a defect to debug and report where the crashing code lives, never to retry past (see ' . ChildExit::segfaultGuide() . ')',
            new ProcessResultDto(139, '', '')->describeExit(),
        );
        self::assertSame(
            'exit 143, which it exited with itself: the code a shell gives for signal 15 (SIGTERM)',
            new ProcessResultDto(143, '', '')->describeExit(),
        );
    }
}
