<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\DeadCode\Dto;

use LTS\PHPQA\Pipeline\Lane\DeadCode\Dto\BinScanDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BinScanDto::class)]
#[Small]
final class BinScanDtoTest extends TestCase
{
    #[Test]
    public function aScanWithNothingToReportIsClean(): void
    {
        self::assertTrue(new BinScanDto([], [])->isClean());
    }

    #[Test]
    public function anUndeclaredScriptOrAnUnreadablePathIsNotClean(): void
    {
        self::assertFalse(new BinScanDto(['bin/worker'], [])->isClean());
        self::assertFalse(new BinScanDto([], ['bin/locked'])->isClean());
    }
}
