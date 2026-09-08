<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Cli;

use LTS\PHPQA\Pipeline\Cli\Exception\UsageException;
use LTS\PHPQA\Pipeline\Cli\PathNormaliser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PathNormaliser::class)]
#[UsesClass(UsageException::class)]
#[Small]
final class PathNormaliserTest extends TestCase
{
    #[Test]
    public function aRelativePathIsReturnedWithoutATrailingSlash(): void
    {
        self::assertSame('src/Domain', new PathNormaliser()->normalise('src/Domain/', '/p'));
        self::assertSame('src', new PathNormaliser()->normalise('src', '/p/'));
    }

    #[Test]
    public function anAbsolutePathUnderTheRootIsRelativised(): void
    {
        self::assertSame('src/Domain', new PathNormaliser()->normalise('/p/src/Domain', '/p'));
    }

    #[Test]
    public function theRootItselfIsRefused(): void
    {
        $this->expectException(UsageException::class);
        new PathNormaliser()->normalise('/p', '/p');
    }

    #[Test]
    public function aRelativeRootIsRefused(): void
    {
        $this->expectException(UsageException::class);
        new PathNormaliser()->normalise('.', '/p');
    }

    #[Test]
    public function aPathOutsideTheRootIsRefused(): void
    {
        try {
            new PathNormaliser()->normalise('/elsewhere/src', '/p');
            self::fail('expected UsageException');
        } catch (UsageException $usageException) {
            self::assertStringContainsString('is outside the project root /p', $usageException->getMessage());
        }
    }

    #[Test]
    public function aSiblingWithTheRootAsPrefixIsOutside(): void
    {
        $this->expectException(UsageException::class);
        new PathNormaliser()->normalise('/project2/src', '/project');
    }
}
