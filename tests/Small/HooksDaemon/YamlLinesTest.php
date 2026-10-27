<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\HooksDaemon;

use LTS\PHPQA\HooksDaemon\YamlLines;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(YamlLines::class)]
#[Small]
final class YamlLinesTest extends TestCase
{
    #[Test]
    public function thePartsOfAKeyLineAreItsInlineValueAndItsComment(): void
    {
        $lines = YamlLines::fromText("a: 1 # one\n  b:\nc: {}   # none\n");

        self::assertSame(['value' => '1', 'comment' => '# one'], $lines->parts(0, 'a'));
        self::assertSame(['value' => '', 'comment' => ''], $lines->parts(1, 'b'));
        self::assertSame(['value' => '{}', 'comment' => '# none'], $lines->parts(2, 'c'));
    }

    #[Test]
    public function thePartsOfALineHoldingAnotherKeyAreEmpty(): void
    {
        $lines = YamlLines::fromText("a: 1 # one\n");

        self::assertSame(['value' => '', 'comment' => ''], $lines->parts(0, 'b'));
        self::assertSame(['value' => '', 'comment' => ''], $lines->parts(0, 'a: 1'));
    }
}
