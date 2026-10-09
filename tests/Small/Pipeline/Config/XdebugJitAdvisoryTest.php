<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Config;

use LTS\PHPQA\Pipeline\Config\XdebugJitAdvisory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(XdebugJitAdvisory::class)]
#[Small]
final class XdebugJitAdvisoryTest extends TestCase
{
    private const string JIT_ON = '1|tracing|64M';

    #[Test]
    public function xdebugWithJitOnIsNamedAndTheMitigationStated(): void
    {
        $text = implode("\n", XdebugJitAdvisory::lines(true, self::JIT_ON));

        self::assertStringContainsString('Xdebug is loaded and OPcache JIT is configured on', $text);
        self::assertStringContainsString('XDEBUG_MODE=off', $text);
    }

    #[Test]
    public function noXdebugMeansNoAdvisory(): void
    {
        self::assertSame([], XdebugJitAdvisory::lines(false, self::JIT_ON));
    }

    #[Test]
    #[DataProvider('jitThatIsNotOn')]
    public function aHostWhereJitIsNotOnIsNotWarned(string $probe): void
    {
        self::assertSame([], XdebugJitAdvisory::lines(true, $probe));
    }

    /** @return iterable<string, array{string}> */
    public static function jitThatIsNotOn(): iterable
    {
        yield 'opcache off for the CLI' => ['0|tracing|64M'];
        yield 'jit disabled' => ['1|disable|64M'];
        yield 'jit off' => ['1|off|64M'];
        yield 'jit empty' => ['1||64M'];
        yield 'no buffer' => ['1|tracing|0'];
        yield 'buffer unset' => ['1|tracing|'];
        yield 'an unreadable answer' => ['Segmentation fault'];
        yield 'an empty answer' => [''];
    }
}
