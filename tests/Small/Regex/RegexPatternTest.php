<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Regex;

use InvalidArgumentException;
use LTS\PHPQA\Regex\RegexPattern;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RegexPattern::class)]
#[Small]
final class RegexPatternTest extends TestCase
{
    private const string ORIGIN = 'ignore pattern';

    private const string HELP = '--help';

    #[Test]
    public function aPatternThatCompilesMatchesAsPcreDoes(): void
    {
        $pattern = RegexPattern::compile('#/src/Legacy/#', self::ORIGIN);

        self::assertSame('#/src/Legacy/#', $pattern->pattern);
        self::assertTrue($pattern->matches('/project/src/Legacy/Old.php'));
        self::assertFalse($pattern->matches('/project/src/Current/New.php'));
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('patternsThatDoNotCompile')]
    public function aPatternThatDoesNotCompileIsRefusedNamingItAndWhereItCameFrom(string $pattern, string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('ignore pattern "%s" is not a valid regular expression: %s', $pattern, $reason));

        RegexPattern::compile($pattern, self::ORIGIN);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function patternsThatDoNotCompile(): iterable
    {
        yield 'an option taken for a pattern' => [self::HELP, "preg_match(): Unknown modifier 'h'"];
        yield 'an unclosed group' => ['#/src/(unclosed#', 'preg_match(): Compilation failed: missing closing parenthesis at offset 14'];
        yield 'no delimiter' => ['src', 'preg_match(): Delimiter must not be alphanumeric, backslash, or NUL byte'];
        yield 'empty' => ['', 'preg_match(): Empty regular expression'];
    }

    #[Test]
    public function compilingLeavesNoErrorHandlerBehind(): void
    {
        $before = set_error_handler(null);
        restore_error_handler();

        try {
            RegexPattern::compile(self::HELP, self::ORIGIN);
        } catch (InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString(self::HELP, $invalidArgumentException->getMessage());
        }

        RegexPattern::compile('#ok#', self::ORIGIN)->matches('ok');
        $after = set_error_handler(null);
        restore_error_handler();

        self::assertSame($before, $after);
    }
}
