<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Process\Dto;

use LTS\PHPQA\ComposerPlugin\ChildExit;
use LTS\PHPQA\Pipeline\Process\Dto\SignalledChildDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SignalledChildDto::class)]
#[UsesClass(ChildExit::class)]
#[Small]
final class SignalledChildDtoTest extends TestCase
{
    #[Test]
    public function aSigtermIsDescribedWithTheCommandAndIsNoSegfault(): void
    {
        $child = new SignalledChildDto('php -r x', \SIGTERM);

        self::assertSame(143, $child->exitCode());
        self::assertFalse($child->isSegmentationFault());
        self::assertSame('`php -r x` ended with exit code 143, killed by signal 15 (SIGTERM)', $child->describe());
    }

    #[Test]
    public function aSigsegvIsASegfaultAndSaysWhatToDo(): void
    {
        $child = new SignalledChildDto('phpstan', \SIGSEGV);

        self::assertSame(139, $child->exitCode());
        self::assertTrue($child->isSegmentationFault());
        self::assertSame('`phpstan` ended with exit code 139, killed by signal 11 (SIGSEGV): ' . ChildExit::segfaultAdvice(), $child->describe());
    }

    /** A child that exited 139 itself relays a crash below it; it was not killed, and is not called so. */
    #[Test]
    public function aChildThatExited139ItselfIsASegfaultBelowIt(): void
    {
        $child = new SignalledChildDto("sh -c 'exit 139'", \SIGSEGV, exitedItself: true);

        self::assertSame(139, $child->exitCode());
        self::assertTrue($child->isSegmentationFault());
        self::assertSame("`sh -c 'exit 139'` ended with " . ChildExit::describeExitedItself(139), $child->describe());
        self::assertStringNotContainsString('killed by', $child->describe());
    }
}
