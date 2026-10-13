<?php

declare(strict_types=1);

namespace LTS\PHPQA\Regex;

use InvalidArgumentException;

/**
 * A regular expression from outside the code: a command-line argument, a line
 * of a configuration file. PCRE only warns on a pattern that does not compile,
 * and thecodingmachine/safe turns the warning into PREG_INTERNAL_ERROR, which
 * names neither the pattern nor where it came from. This compiles the pattern
 * when it is made and matches under an error handler that throws naming both.
 *
 * @internal
 */
final readonly class RegexPattern
{
    private function __construct(public string $pattern, private string $origin)
    {
    }

    /**
     * @param string $origin what the pattern is, for the message: "ignore pattern", "<file> entry"
     *
     * @throws InvalidArgumentException when the pattern does not compile
     */
    public static function compile(string $pattern, string $origin): self
    {
        $compiled = new self($pattern, $origin);
        $compiled->matches('');

        return $compiled;
    }

    /**
     * @throws InvalidArgumentException when the pattern does not compile
     */
    public function matches(string $subject): bool
    {
        $pattern = $this->pattern;
        $origin  = $this->origin;
        set_error_handler(static function (int $level, string $message) use ($pattern, $origin): never {
            throw new InvalidArgumentException(\sprintf('%s "%s" is not a valid regular expression: %s', $origin, $pattern, $message), $level);
        });
        try {
            return 1 === \Safe\preg_match($pattern, $subject);
        } finally {
            restore_error_handler();
        }
    }
}
