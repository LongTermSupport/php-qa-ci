<?php

declare(strict_types=1);

namespace ProjectTreeLeakFixture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A test that leaks into both places the suite guards: the working directory
 * (the project root) and the temp directory TempLeakExtension gives the
 * process. Run only by ProjectTreeLeakExtensionTest, in a child PHPUnit
 * process, under the suite's own configuration; it passes, so the verdict is
 * the two extensions' alone, and both must be given.
 *
 * @internal
 */
#[CoversNothing]
final class WritesIntoTheTreeAndTheTempDirectoryTest extends TestCase
{
    #[Test]
    public function writesARelativePathAndLeavesATempFile(): void
    {
        $name = getenv('PROJECT_TREE_LEAK_PROBE');
        self::assertIsString($name);

        \Safe\file_put_contents($name, 'escaped the sandbox');
        \Safe\file_put_contents(sys_get_temp_dir() . '/' . $name, 'left behind');

        self::assertFileExists($name);
    }
}
