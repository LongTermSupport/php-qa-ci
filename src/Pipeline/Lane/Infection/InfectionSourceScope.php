<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Infection;

use InvalidArgumentException;

/**
 * Whether Infection collects a file for mutation from infection.json's
 * `source`, decided the way its source collector decides it: a `*.php` file
 * under a source directory, not hidden from Finder by a dot or a
 * version-control directory, and not matched by a `source.excludes` entry.
 * Infection hands every exclude to Symfony Finder's `notPath()`, which matches
 * it against the path relative to the source directory: a string Finder takes
 * for a regex (delimited, with optional modifiers) as that regex, any other as
 * a substring. Paths are compared by their real path when they exist.
 *
 * @internal
 */
final readonly class InfectionSourceScope
{
    /** The pattern modifiers Finder accepts after a regex's closing delimiter. */
    private const string MODIFIERS = 'imsxuADUn';

    /** The bracket pairs Finder accepts as a regex's opening and closing delimiters. */
    private const array BRACKETS = ['{' => '}', '(' => ')', '[' => ']', '<' => '>'];

    /** The version-control directories Finder skips whose names do not start with a dot, which it skips anyway. */
    private const array VCS_DIRECTORIES = ['_svn', 'CVS', '_darcs'];

    /**
     * Why Infection does not collect $file, or null when it does. Finder lists
     * a file once for each source directory it is under, so one that keeps it
     * is enough; otherwise the reason is the first one's.
     *
     * @param list<string> $sourceDirectories absolute, as the lane's derived infection.json states them
     * @param string       ...$excludes       infection.json's `source.excludes`
     *
     * @throws InvalidArgumentException when an exclude Finder takes for a regex does not compile
     */
    public function outside(string $file, array $sourceDirectories, string ...$excludes): ?string
    {
        if (!str_ends_with($file, '.php')) {
            return 'not a .php file';
        }

        $path   = $this->real($file);
        $reason = null;
        foreach ($sourceDirectories as $directory) {
            $root = rtrim($this->real($directory), '/') . '/';
            if (!str_starts_with($path, $root)) {
                continue;
            }

            $left = $this->reason(substr($path, \strlen($root)), ...$excludes);
            if (null === $left) {
                return null;
            }

            $reason ??= $left;
        }

        return $reason ?? 'under no source directory';
    }

    /** Why Finder leaves out the file at $relative below a source directory, or null when it lists it. */
    private function reason(string $relative, string ...$excludes): ?string
    {
        $directories = explode('/', $relative);
        $name        = array_pop($directories);
        if (str_starts_with($name, '.') || array_any($directories, static fn (string $directory): bool => str_starts_with($directory, '.') || \in_array($directory, self::VCS_DIRECTORIES, true))) {
            return "in a directory Infection's Finder skips";
        }

        foreach ($excludes as $exclude) {
            if ($this->isRegex($exclude) ? $this->matches($exclude, $relative) : str_contains($relative, $exclude)) {
                return \sprintf('excluded by source.excludes "%s"', $exclude);
            }
        }

        return null;
    }

    /** PCRE reports a pattern that does not compile as a warning, which becomes the exception for the length of the match. */
    private function matches(string $pattern, string $relative): bool
    {
        set_error_handler(static function (int $level, string $message) use ($pattern): never {
            throw new InvalidArgumentException(\sprintf('source.excludes entry "%s" is not a valid regular expression (%s), so what Infection mutates cannot be worked out', $pattern, $message), $level);
        });
        try {
            return 1 === \Safe\preg_match($pattern, $relative);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Symfony Finder's reading of a string as a regex rather than text: its
     * pattern is the shortest body of three or more characters followed only
     * by modifiers, and the body's first and last characters must delimit it.
     */
    private function isRegex(string $candidate): bool
    {
        if (\strlen($candidate) < 3) {
            return false;
        }

        $body  = substr($candidate, 0, max(3, \strlen(rtrim($candidate, self::MODIFIERS))));
        $start = $body[0];
        $end   = $body[-1];
        if ($start === $end) {
            return 1 !== \Safe\preg_match('/[*?[:alnum:] \\\]/', $start);
        }

        return (self::BRACKETS[$start] ?? null) === $end;
    }

    /** The real path of an existing path, else the path as written. */
    private function real(string $path): string
    {
        return file_exists($path) ? \Safe\realpath($path) : $path;
    }
}
