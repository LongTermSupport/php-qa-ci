<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Installer;

use LTS\PHPQA\Installer\InstallerOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InstallerOutput::class)]
#[Small]
final class InstallerOutputTest extends TestCase
{
    public function testAnOutputIsWrittenByTheInstallerUnlessItSaysACommandItPrintsWritesIt(): void
    {
        $output = new InstallerOutput('vendor-phar/turbo-ext', true, true);

        self::assertSame('vendor-phar/turbo-ext', $output->path);
        self::assertTrue($output->isDirectory);
        self::assertTrue($output->untrackedByDesign);
        self::assertFalse($output->printedCommand);
    }

    public function testAnOutputCanSayAPrintedCommandWritesIt(): void
    {
        self::assertTrue(new InstallerOutput('vendor-bin/shellcheck.linux.aarch64', false, true, true)->printedCommand);
    }
}
