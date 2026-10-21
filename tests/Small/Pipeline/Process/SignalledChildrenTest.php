<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Process;

use LTS\PHPQA\Pipeline\Process\Dto\SignalledChildDto;
use LTS\PHPQA\Pipeline\Process\SignalledChildren;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SignalledChildren::class)]
#[UsesClass(SignalledChildDto::class)]
#[Small]
final class SignalledChildrenTest extends TestCase
{
    #[Test]
    public function nothingIsRecordedAtFirst(): void
    {
        self::assertSame([], new SignalledChildren()->take());
    }

    /** Taken in the order recorded, and taken once: the next lane starts from nothing. */
    #[Test]
    public function takingReturnsEveryChildInOrderAndForgetsThem(): void
    {
        $signalled = new SignalledChildren();
        $first     = new SignalledChildDto('a', \SIGTERM);
        $second    = new SignalledChildDto('b', \SIGKILL);
        $signalled->record($first);
        $signalled->record($second);

        self::assertSame([$first, $second], $signalled->take());
        self::assertSame([], $signalled->take());
    }
}
