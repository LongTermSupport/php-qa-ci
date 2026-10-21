<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Rector;

/**
 * Whether a failed Rector pass failed because its config names a class the bundled Rector does
 * not have, and the message that says so.
 *
 * Rector reads a config before it processes a file, and reports a class it cannot use with a
 * generic Assert message that names neither the config nor the line. Rector 2.7 removed
 * `MemoryCacheStorage`, so every project config copied from an older shipped `rector-safe.php`
 * (which called `cacheClass(MemoryCacheStorage::class)`) fails that way. Two of Rector's own
 * messages are recognised, because they are what it prints for exactly this: `cacheClass()` given
 * a class that is not a cache storage, and a class name that does not exist. The line is then found
 * in the config the pass ran, since the message alone cannot name it.
 * ShippedRectorConfigsBootAgainstTheBundledPharTest holds the first message to the shipped phar.
 *
 * Rector wraps its message at the terminal width, even inside a quoted class name, so the output is
 * matched with its whitespace removed.
 *
 * @internal
 */
final readonly class StaleConfigDiagnosis
{
    /** `cacheClass()` was given a class that is not a CacheStorageInterface (it may not exist at all). */
    private const string CACHE_CLASS_REJECTED = '/\[ERROR\]Expectedaninstanceofthisclassortothisclassamongitsparents"[^"]*CacheStorageInterface"\.Got:"([^"]+)"/';

    /** A class name in the config, or a config it loads, does not exist. */
    private const string CLASS_MISSING = '/\[ERROR\]Expectedanexistingclassname\.Got:"([^"]+)"/';

    /** Rector's exit code for an error; a dry run with changes to make exits 2 and prints a diff, which is no error. */
    private const int EXIT_ERROR = 1;

    /** A call to `cacheClass(` on a line of code. */
    private const string CACHE_CLASS_CALL = '/\bcacheClass\s*\(/';

    /** The most config lines the message quotes. */
    private const int MAX_LINES = 5;

    /** The last line of every message: where the migration is described. */
    private const string DOCS_LINE = 'Why and how: docs/tools/rector.md in php-qa-ci, "A project config that sets its own cache".';

    public function __construct(
        private string $projectRoot,
        private string $cacheDir,
    ) {
    }

    /**
     * The lines to print for a failed pass, or an empty list when it did not fail this way. Only a
     * pass that exited with an error is looked at, and only Rector's `[ERROR]` message counts: the
     * diff of a pass with pending changes shows source lines, and one may quote that message.
     *
     * @return list<string>
     */
    public function lines(string $label, string $rectorOutput, string $configFile, int $exitCode): array
    {
        if (self::EXIT_ERROR !== $exitCode) {
            return [];
        }

        $flat  =str_replace([' ', "\t", "\r", "\n"], '', $rectorOutput);
        $class = $this->rejectedCacheClass($flat);
        if (null !== $class) {
            return $this->cacheClassLines($label, $configFile, $class);
        }

        $class = $this->missingClass($flat);

        return null === $class ? [] : $this->missingClassLines($label, $configFile, $class);
    }

    private function rejectedCacheClass(string $flat): ?string
    {
        if (1 !== \Safe\preg_match(self::CACHE_CLASS_REJECTED, $flat, $matches) || !isset($matches[1])) {
            return null;
        }

        return $matches[1];
    }

    private function missingClass(string $flat): ?string
    {
        if (1 !== \Safe\preg_match(self::CLASS_MISSING, $flat, $matches) || !isset($matches[1])) {
            return null;
        }

        return $matches[1];
    }

    /** @return list<string> */
    private function cacheClassLines(string $label, string $configFile, string $class): array
    {
        $config = $this->shown($configFile);
        $calls  = $this->quotedLines($configFile, static fn (string $line): bool => 1 === \Safe\preg_match(self::CACHE_CLASS_CALL, $line));

        return [
            \sprintf("Rector ('%s') cannot load %s: it refers to a class the bundled Rector does not have.", $label, $config),
            ...([] === $calls ? ['  No cacheClass(...) call is in ' . $config . '; look in the files it includes.'] : $calls),
            \sprintf('Rector does not accept "%s" as a cache storage.', $class),
            'Remove the cacheClass(...) call. Rector then uses its own file cache, which php-qa-ci keeps under '
            . $this->shown($this->cacheDir) . '.',
            self::DOCS_LINE,
        ];
    }

    /** @return list<string> */
    private function missingClassLines(string $label, string $configFile, string $class): array
    {
        $position = strrpos($class, '\\');
        $needle   = false === $position ? $class : substr($class, $position + 1);

        return [
            \sprintf("Rector ('%s') cannot load %s: it refers to the class %s, which does not exist.", $label, $this->shown($configFile), $class),
            ...$this->quotedLines($configFile, static fn (string $line): bool => str_contains($line, $needle)),
            "The bundled Rector no longer has this class, or the project's autoloader does not provide it.",
            'Remove the reference or replace it with what the bundled Rector offers instead.',
            self::DOCS_LINE,
        ];
    }

    /**
     * The `file:line: code` of the first lines of code in a config that match. A comment is not
     * code, so it cannot be the cause.
     *
     * @param callable(string): bool $matches
     *
     * @return list<string>
     */
    private function quotedLines(string $configFile, callable $matches): array
    {
        if (!is_file($configFile)) {
            return [];
        }

        $quoted = [];
        foreach (explode("\n", \Safe\file_get_contents($configFile)) as $index => $line) {
            $code = trim($line);
            if ($this->isComment($code) || !$matches($code)) {
                continue;
            }

            $quoted[] = \sprintf('  %s:%d: %s', $this->shown($configFile), $index + 1, $code);
            if (self::MAX_LINES === \count($quoted)) {
                break;
            }
        }

        return $quoted;
    }

    private function isComment(string $code): bool
    {
        return '' === $code || str_starts_with($code, '//') || str_starts_with($code, '#') || str_starts_with($code, '*') || str_starts_with($code, '/*');
    }

    private function shown(string $path): string
    {
        $prefix = $this->projectRoot . '/';

        return str_starts_with($path, $prefix) ? substr($path, \strlen($prefix)) : $path;
    }
}
