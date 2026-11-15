<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

/**
 * The `bin/*-install` entry points and the class each hands control to, found one way for every test
 * that reads them.
 */
final readonly class InstallerEntryPoints
{
    /** The class an entry point hands control to: `\Some\Name::main(`. */
    private const string ENTRY_CLASS = '/(\\\LTS\\\PHPQA\\\[A-Za-z\\\]+)::main\(/';

    private function __construct()
    {
    }

    /**
     * @return array<non-falsy-string, class-string|null> the entry point's path under the package
     *                                                    (`bin/turbo-install`) => the class it calls,
     *                                                    or null when no class could be found
     */
    public static function classes(): array
    {
        $files = [];
        foreach (\Safe\glob(\dirname(__DIR__, 2) . '/bin/*-install') as $file) {
            if (\is_string($file)) {
                $files[] = $file;
            }
        }

        sort($files);

        $classes = [];
        foreach ($files as $file) {
            $classes['bin/' . basename($file)] = self::classOf($file);
        }

        return $classes;
    }

    /** @return class-string|null */
    private static function classOf(string $entryPoint): ?string
    {
        if (1 !== \Safe\preg_match(self::ENTRY_CLASS, \Safe\file_get_contents($entryPoint), $match) || !isset($match[1])) {
            return null;
        }

        $class = ltrim($match[1], '\\');

        return class_exists($class) ? $class : null;
    }
}
