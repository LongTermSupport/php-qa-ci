<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\ShellCheck;

/**
 * Finds the command substitutions in a shell script, or in a workflow's run scripts, that capture
 * the output of PHP while leaving its warnings on stdout: `$(php ...)` or `` `php ...` `` with no
 * `display_errors` set to `stderr`, `0`, `off`, `no` or `false`. The PHP CLI displays errors on
 * stdout unless an ini says otherwise, so a warning or deprecation becomes part of the value.
 *
 * The program is the first word of the substitution that is not a `NAME=value` assignment. It is
 * PHP when its file name is `php` with an optional version, when it is one of the project's own
 * PHP scripts, named by file name, when it is a variable named for the PHP binary (`$PHP`,
 * `${PHP_BINARY:-php}`) or defaulting to `php`, or when it is a variable whose last assignment in
 * the same file names either. Composer is not PHP here: its own error handler throws on a warning
 * and writes a deprecation to stderr.
 *
 * @internal
 */
final readonly class CapturedPhpOutputScanner
{
    /** A binary named php, with an optional version such as php8.5. */
    private const string PHP_NAME = '/^php(\d+(\.\d+)*)?$/';

    /** A variable named for the PHP binary: PHP, PHP_BIN, PHP_BINARY, PHP_QA_CI_PHP_EXECUTABLE and the like. */
    private const string PHP_VARIABLE = '/^(?:\w+_)?PHP(?:_(?:BIN|BINARY|EXECUTABLE|CMD|COMMAND|PATH))?$/i';

    /** A lone variable as a program, with any default: $NAME, ${NAME}, ${NAME:-default}. */
    private const string LONE_VARIABLE = '/^\$\{?([A-Za-z_]\w*)(?::?-([^}]*))?\}?$/';

    /** A setting of display_errors that keeps PHP's warnings off stdout. */
    private const string ROUTED = '/display_errors\s*=\s*["\']?(?:stderr|0|off|no|false)(?!\w)/i';

    /** A shell word that assigns a variable for the command rather than naming its program. */
    private const string ASSIGNMENT = '/^[A-Za-z_]\w*=/';

    /** A line that assigns a shell variable: NAME=value, after an optional export, local or readonly. */
    private const string VARIABLE_ASSIGNMENT = '/^\s*(?:(?:export|local|readonly)\s+)?([A-Za-z_]\w*)=(.*)$/';

    /** A here-document with a quoted delimiter, whose body the shell does not expand. */
    private const string QUOTED_HEREDOC = '/<<-?\s*([\'"])(\w+)\1/';

    /** The quotes that may surround a word or an assigned value. */
    private const string QUOTES = '"\'';

    /** Opens a command substitution. */
    private const string SUBSTITUTION = '$(';

    /** Opens and closes a backtick command substitution. */
    private const string BACKTICK = '`';

    /** Escapes the character after it. */
    private const string ESCAPE = '\\';

    /** @var list<string> the file names of the project's own PHP scripts, run by their shebang */
    private array $phpScripts;

    public function __construct(string ...$phpScripts)
    {
        $this->phpScripts = array_values($phpScripts);
    }

    /**
     * The lines, counted from 1, on which such a substitution starts; a command continued over
     * several lines counts on its first.
     *
     * @return list<int>
     */
    public function scan(string $script): array
    {
        $assignments = $this->assignments($script);
        $lines       = explode("\n", $script);
        $found       = [];
        $count       = \count($lines);
        for ($index = 0; $index < $count; ++$index) {
            $start   = $index + 1;
            $logical = rtrim($lines[$index], "\r");
            while (str_ends_with($logical, self::ESCAPE) && $index + 1 < $count) {
                $logical = substr($logical, 0, -1) . ' ' . rtrim($lines[++$index], "\r");
            }

            if (str_starts_with(ltrim($logical), '#')) {
                continue;
            }

            // A here-document whose delimiter is quoted is literal: nothing in its body runs.
            if (1 === \Safe\preg_match(self::QUOTED_HEREDOC, $logical, $heredoc) && isset($heredoc[2])) {
                $delimiter = $heredoc[2];
                while ($index + 1 < $count && trim($lines[$index + 1]) !== $delimiter) {
                    ++$index;
                }

                ++$index;
            }

            if (array_any($this->substitutions($logical), fn (string $body): bool => $this->leavesWarningsOnStdout($body, $assignments))) {
                $found[] = $start;
            }
        }

        return $found;
    }

    /**
     * The last value each variable is assigned in the script, unquoted.
     *
     * @return array<string, string>
     */
    private function assignments(string $script): array
    {
        $assignments = [];
        foreach (explode("\n", $script) as $line) {
            if (1 === \Safe\preg_match(self::VARIABLE_ASSIGNMENT, $line, $match) && isset($match[1], $match[2])) {
                $assignments[$match[1]] = trim(trim($match[2]), self::QUOTES);
            }
        }

        return $assignments;
    }

    /**
     * The body of every command substitution on the line, nested ones included; a single-quoted
     * span outside double quotes is literal and skipped, and `$((` is arithmetic.
     *
     * @return list<string>
     */
    private function substitutions(string $line): array
    {
        $bodies   = [];
        $inDouble = false;
        $length   = \strlen($line);
        for ($position = 0; $position < $length; ++$position) {
            $char = $line[$position];
            if (self::ESCAPE === $char) {
                ++$position;

                continue;
            }

            if ('"' === $char) {
                $inDouble = !$inDouble;

                continue;
            }

            if ("'" === $char && !$inDouble) {
                $closing  = strpos($line, "'", $position + 1);
                $position = false === $closing ? $length : $closing;

                continue;
            }

            if (self::BACKTICK === $char) {
                $closing  = strpos($line, self::BACKTICK, $position + 1);
                $end      = false === $closing ? $length : $closing;
                $bodies[] = substr($line, $position + 1, $end - $position - 1);
                $position = $end;

                continue;
            }

            if (self::SUBSTITUTION === substr($line, $position, 2) && '(' !== substr($line, $position + 2, 1)) {
                // The scan goes on inside the body, so a substitution nested in it is found too.
                $bodies[] = substr($line, $position + 2, $this->closing($line, $position + 2) - $position - 2);
                ++$position;
            }
        }

        return $bodies;
    }

    /** Where the parenthesis closing a substitution whose body starts at $from is, or the line's end. */
    private function closing(string $line, int $from): int
    {
        $depth  = 1;
        $length = \strlen($line);
        for ($position = $from; $position < $length; ++$position) {
            $char = $line[$position];
            if (self::ESCAPE === $char) {
                ++$position;
            } elseif ('(' === $char) {
                ++$depth;
            } elseif (')' === $char && 0 === --$depth) {
                return $position;
            }
        }

        return $length;
    }

    /** @param array<string, string> $assignments */
    private function leavesWarningsOnStdout(string $body, array $assignments): bool
    {
        $program = array_find($this->words($body), static fn (string $word): bool => 1 !== \Safe\preg_match(self::ASSIGNMENT, $word));

        return null !== $program && $this->isPhp($program, $assignments) && 1 !== \Safe\preg_match(self::ROUTED, $body);
    }

    /**
     * The body split at whitespace that is neither quoted nor inside a nested substitution.
     *
     * @return list<string>
     */
    private function words(string $body): array
    {
        $words   = [];
        $current = '';
        $quote   = null;
        $depth   = 0;
        $length  = \strlen($body);
        for ($position = 0; $position < $length; ++$position) {
            $char = $body[$position];
            if (null === $quote && 0 === $depth && ctype_space($char)) {
                if ('' !== $current) {
                    $words[] = $current;
                }

                $current = '';

                continue;
            }

            $current .= $char;
            if (self::ESCAPE === $char && $position + 1 < $length) {
                $current .= $body[++$position];
            } elseif (null === $quote && str_contains(self::QUOTES, $char)) {
                $quote = $char;
            } elseif ($char === $quote) {
                $quote = null;
            } elseif ('(' === $char && "'" !== $quote) {
                ++$depth;
            } elseif (')' === $char && "'" !== $quote && $depth > 0) {
                --$depth;
            }
        }

        if ('' !== $current) {
            $words[] = $current;
        }

        return $words;
    }

    /** @param array<string, string> $assignments */
    private function isPhp(string $word, array $assignments): bool
    {
        $bare = trim($word, self::QUOTES);
        if (1 === \Safe\preg_match(self::LONE_VARIABLE, $bare, $match) && isset($match[1])) {
            $name = $match[1];

            return (isset($match[2]) && '' !== $match[2] && $this->isPhpPath($match[2]))
                || 1 === \Safe\preg_match(self::PHP_VARIABLE, $name)
                || (\array_key_exists($name, $assignments) && $this->isPhpPath($assignments[$name]));
        }

        return $this->isPhpPath($bare);
    }

    private function isPhpPath(string $path): bool
    {
        $name = basename($path);

        return 1 === \Safe\preg_match(self::PHP_NAME, $name) || \in_array($name, $this->phpScripts, true);
    }
}
