<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\UnvalidatedRegexPattern;

use InvalidArgumentException;
use RuntimeException;

final readonly class NotReportable
{
    private const string PATTERN = '/^a+$/';

    private const array PATTERNS = ['/a/', '/b/'];

    public function literalPatterns(string $subject): void
    {
        preg_match('/^a+$/', $subject);
        preg_match(self::PATTERN, $subject);
        \Safe\preg_match(self::PATTERN . 'i', $subject);
        preg_replace(self::PATTERNS, '', $subject);
        preg_replace(['/a/', '/b/'], '', $subject);
        preg_replace_callback_array(['/a/' => static fn (array $match): string => ''], $subject);
        preg_match(subject: $subject, pattern: self::PATTERN);
        foreach (self::PATTERNS as $pattern) {
            preg_match($pattern, $subject);
        }
    }

    public function quotedWithItsDelimiter(string $value, string $subject): void
    {
        preg_match('/^' . preg_quote($value, '/') . '$/', $subject);
        preg_match("/^{$this->literal()}x/", $subject);
        preg_match(\sprintf('/^%s\.%s$/', preg_quote($value, '/'), 'log'), $subject);
        \Safe\preg_match('#' . preg_quote($value, '#') . '#', $subject);
    }

    public function quotedWithABracketDelimiter(string $value, string $subject): void
    {
        preg_match('{' . preg_quote($value, '}') . '}', $subject);
        preg_match('(' . preg_quote($value, '(') . ')', $subject);
    }

    /** @param literal-string $pattern */
    public function aLiteralStringParameter(string $pattern, string $subject): bool
    {
        return 1 === preg_match($pattern, $subject);
    }

    public function compiledUnderAHandlerThatNamesIt(string $pattern, string $subject): bool
    {
        set_error_handler(static function (int $level, string $message) use ($pattern): never {
            throw new InvalidArgumentException(\sprintf('"%s" is not a valid regular expression: %s', $pattern, $message), $level);
        });
        try {
            return 1 === preg_match($pattern, $subject);
        } catch (RuntimeException $exception) {
            preg_match($pattern, $exception->getMessage());

            throw $exception;
        } finally {
            restore_error_handler();
        }
    }

    public function aClosureThatGuardsItsOwnCall(string $pattern, string $subject): callable
    {
        return static function () use ($pattern, $subject): bool {
            set_error_handler(static function (int $level, string $message) use ($pattern): never {
                throw new InvalidArgumentException(\sprintf('"%s" is not a valid regular expression: %s', $pattern, $message), $level);
            });
            try {
                return 1 === preg_match($pattern, $subject);
            } finally {
                restore_error_handler();
            }
        };
    }

    public function aFirstClassCallableCompilesNothing(): callable
    {
        return preg_match(...);
    }

    public function notAPatternFunction(string $value): string
    {
        return preg_quote($value, '/');
    }

    public function aLocalBuiltFromOwnedParts(string $value, string $subject): void
    {
        $pattern = '/^' . preg_quote($value, '/') . '$/';
        preg_match($pattern, $subject);

        $suffixed = '/^' . preg_quote($value, '/');
        $suffixed .= '$/i';
        preg_match($suffixed, $subject);

        $either = \sprintf('/^%s$/', preg_quote($value, '/'));
        if ('' === $value) {
            $either = '/^$/';
        }

        preg_match($either, $subject);
        $closure = static function (string $value) use ($subject): bool {
            $quoted = '/' . preg_quote($value, '/') . '/';

            return 1 === preg_match($quoted, $subject);
        };
        $closure($value);
    }

    public function builtFromAnOwnedLocal(string $value, string $subject, bool $anchored): void
    {
        $escaped = preg_quote($value, '/');
        preg_match('/^' . $escaped . ($anchored ? '$' : '') . '/', $subject);

        $alias = $escaped;
        preg_match("/{$alias}/", $subject);

        $grown = '/';
        $grown .= $escaped;
        $grown = $grown . '/';
        preg_match($grown, $subject);
    }

    /** @return literal-string */
    private function literal(): string
    {
        return 'abc';
    }
}
