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
 * a class that is not a cache storage, and a class name that does not exist. Rector 2.8 removed
 * `RectorConfig::cacheClass()` itself, so a config that still calls it fails with "Call to undefined
 * method", which is recognised for that one method only. The line is then found
 * in the config the pass ran, since the message alone cannot name it.
 * ShippedRectorConfigsBootAgainstTheBundledPharTest holds the first message to the shipped phar.
 *
 * Rector wraps its message at the terminal width, even inside a quoted class name, so each
 * `[ERROR]` block of the output is matched with its whitespace removed. Only a block that starts
 * with the marker counts, and never a line between the begin and end lines of a diff, which is
 * source code that may quote it.
 *
 * @internal
 */
final readonly class StaleConfigDiagnosis
{
    /** The line (whitespace removed) that opens a diff in Rector's output; what follows it is source code. */
    private const string DIFF_BEGIN = '----------begindiff----------';

    /** The line (whitespace removed) that closes that diff. */
    private const string DIFF_END = '-----------enddiff-----------';

    /** `cacheClass()` was given a class that is not a CacheStorageInterface (it may not exist at all). */
    private const string CACHE_CLASS_REJECTED = '/^\[ERROR\]Expectedaninstanceofthisclassortothisclassamongitsparents"[^"]*CacheStorageInterface"\.Got:"([^"]+)"/';

    /** The config calls `RectorConfig::cacheClass()`, which Rector 2.8 removed. */
    private const string CACHE_CLASS_METHOD_REMOVED = '/^\[ERROR\]Calltoundefinedmethod"?Rector\\\Config\\\RectorConfig::cacheClass\(\)/';

    /** A class name in the config, or a config it loads, does not exist. */
    private const string CLASS_MISSING = '/^\[ERROR\]Expectedanexistingclassname\.Got:"([^"]+)"/';

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

        $blocks = $this->errorBlocks($rectorOutput);
        $class  = $this->rejectedCacheClass(...$blocks);
        if (null !== $class) {
            return $this->cacheClassLines($label, $configFile, 'it refers to a class the bundled Rector does not have.', \sprintf('Rector does not accept "%s" as a cache storage.', $class));
        }

        if ($this->cacheClassMethodRemoved(...$blocks)) {
            return $this->cacheClassLines($label, $configFile, 'it calls a method the bundled Rector does not have.', 'The bundled Rector has no RectorConfig::cacheClass().');
        }

        $class = $this->missingClass(...$blocks);

        return null === $class ? [] : $this->missingClassLines($label, $configFile, $class);
    }

    /**
     * Rector's `[ERROR]` blocks with their whitespace removed: a line that starts with the marker and
     * the lines under it up to the next blank line. A source line in a diff that quotes the marker
     * starts with something else, so it opens no block.
     *
     * @return list<string>
     */
    private function errorBlocks(string $rectorOutput): array
    {
        $blocks  = [];
        $current = null;
        $inDiff  = false;
        foreach (explode("\n", $rectorOutput) as $line) {
            $text = str_replace([' ', "\t", "\r"], '', $line);
            if (str_contains($text, self::DIFF_BEGIN)) {
                $inDiff = true;
            }

            if ($inDiff) {
                $inDiff = !str_contains($text, self::DIFF_END);

                continue;
            }

            if ('' === $text) {
                if (null !== $current) {
                    $blocks[] = $current;
                }

                $current = null;

                continue;
            }

            if (null !== $current) {
                $current .= $text;
            } elseif (str_starts_with($text, '[ERROR]')) {
                $current = $text;
            }
        }

        if (null !== $current) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    private function rejectedCacheClass(string ...$blocks): ?string
    {
        foreach ($blocks as $block) {
            if (1 === \Safe\preg_match(self::CACHE_CLASS_REJECTED, $block, $matches) && isset($matches[1])) {
                return $matches[1];
            }
        }

        return null;
    }

    private function cacheClassMethodRemoved(string ...$blocks): bool
    {
        return array_any($blocks, static fn (string $block): bool => 1 === \Safe\preg_match(self::CACHE_CLASS_METHOD_REMOVED, $block));
    }

    private function missingClass(string ...$blocks): ?string
    {
        foreach ($blocks as $block) {
            if (1 === \Safe\preg_match(self::CLASS_MISSING, $block, $matches) && isset($matches[1])) {
                return $matches[1];
            }
        }

        return null;
    }

    /** @return list<string> */
    private function cacheClassLines(string $label, string $configFile, string $cause, string $rejection): array
    {
        $config = $this->shown($configFile);
        $calls  = $this->quotedLines($configFile, static fn (string $line): bool => 1 === \Safe\preg_match(self::CACHE_CLASS_CALL, $line));

        return [
            \sprintf("Rector ('%s') cannot load %s: %s", $label, $config, $cause),
            ...([] === $calls ? ['  No cacheClass(...) call is in ' . $config . '; look in the files it includes.'] : $calls),
            $rejection,
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
