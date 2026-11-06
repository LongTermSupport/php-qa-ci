<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\ShellCheck\Dto;

use LTS\PHPQA\Pipeline\Lane\ShellCheck\Dto\DaemonOwnedSplitDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DaemonOwnedSplitDto::class)]
#[Small]
final class DaemonOwnedSplitDtoTest extends TestCase
{
    #[Test]
    public function itHoldsTheKeptAndTheSkippedFilesAsGiven(): void
    {
        $split = new DaemonOwnedSplitDto(['a.bash'], ['b.bash']);

        self::assertSame(['a.bash'], $split->kept);
        self::assertSame(['b.bash'], $split->skipped);
    }
}
