<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Infection;

use InvalidArgumentException;

/**
 * Which of the files already loaded when PHPUnit's bootstrap starts are files
 * Infection mutates (#155). Infection swaps a file for its mutant by
 * intercepting the include that loads it, and turns the interceptor on in its
 * own bootstrap; PHPUnit loads Composer's autoloader, and with it every
 * `files` entry, before any bootstrap. A source file loaded by then keeps its
 * original code in every mutant run, so none of its mutants can be killed.
 *
 * What Infection mutates is decided the way its source collector decides it:
 * a `*.php` file under a source directory, not matched by a `source.excludes`
 * entry, and, in a diff run, one of the files the lane passes it. Infection
 * hands every exclude to Symfony Finder's `notPath()`, which matches it
 * against the path relative to the source directory: a string Finder takes
 * for a regex (delimited, with optional modifiers) as that regex, any other
 * as a substring. Paths are compared by their real path, as PHP records an
 * included file under it.
 *
 * @internal
 */
final readonly class PreloadedSourceFinder
{
    /** The pattern modifiers Finder accepts after a regex's closing delimiter. */
    private const string MODIFIERS = 'imsxuADUn';

    /** The bracket pairs Finder accepts as a regex's opening and closing delimiters. */
    private const array BRACKETS = ['{' => '}', '(' => ')', '[' => ']', '<' => '>'];

    /**
     * @param list<string> $sourceDirectories absolute, as the lane's derived infection.json states them
     * @param list<string> $excludes          infection.json's `source.excludes`
     * @param list<string> $loaded            the files included once the autoloader has loaded
     * @param string       ...$scope          the files a diff run passes Infection; none for a full run
     *
     * @return list<string> the loaded files Infection mutates, each once, in the order loaded
     *
     * @throws InvalidArgumentException when an exclude Finder takes for a regex does not compile
     */
    public function find(array $sourceDirectories, array $excludes, array $loaded, string ...$scope): array
    {
        $roots   = array_map(fn (string $directory): string => rtrim($this->real($directory), '/') . '/', $sourceDirectories);
        $inScope = array_map($this->real(...), $scope);
        $found   = [];
        foreach (array_unique($loaded) as $file) {
            if (!str_ends_with($file, '.php') || ([] !== $inScope && !\in_array($file, $inScope, true))) {
                continue;
            }

            foreach ($roots as $root) {
                if (str_starts_with($file, $root) && !$this->excluded(substr($file, \strlen($root)), ...$excludes)) {
                    $found[] = $file;

                    break;
                }
            }
        }

        return $found;
    }

    private function excluded(string $relative, string ...$excludes): bool
    {
        return array_any($excludes, fn (string $exclude): bool => $this->isRegex($exclude) ? $this->matches($exclude, $relative) : str_contains($relative, $exclude));
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
