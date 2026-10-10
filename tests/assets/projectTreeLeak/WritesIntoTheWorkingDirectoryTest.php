<?php

declare(strict_types=1);

namespace ProjectTreeLeakFixture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a mutant that turns a sandboxed path into a relative one does: the write
 * lands in the working directory, which under Infection is the project root.
 * Run only by ProjectTreeLeakExtensionTest, in a child PHPUnit process, under the
 * suite's own configuration; it passes, so the verdict is the extension's alone.
 *
 * @internal
 */
#[CoversNothing]
final class WritesIntoTheWorkingDirectoryTest extends TestCase
{
    #[Test]
    public function writesARelativePath(): void
    {
        $name = getenv('PROJECT_TREE_LEAK_PROBE');
        self::assertIsString($name);

        \Safe\file_put_contents($name, 'escaped the sandbox');

        self::assertFileExists($name);
    }
}
