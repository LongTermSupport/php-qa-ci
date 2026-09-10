<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\ShellCheck;

/**
 * Which files the shellCheck lane hands to ShellCheck, decided from the
 * repository rather than from the working tree: the candidate set is what git
 * tracks, so untracked scratch is excluded by construction and a new script is
 * checked the day it is committed.
 *
 * A tracked file qualifies by shell extension or by shell shebang, which is
 * how the extensionless wrappers under bin/ are found without being named.
 * A project that wants a different set gives an explicit glob list instead,
 * and then the globs decide alone — it has said which files it means.
 * The ignored paths subtract from whichever set was produced.
 *
 * @internal
 */
final readonly class ShellFileFinder
{
    private const array EXTENSIONS = ['.bash', '.sh'];

    private const array INTERPRETERS = ['sh', 'bash', 'dash', 'ksh'];

    private const string SHEBANG = '#!';

    private const string ENV = 'env';

    private const int SHEBANG_READ_BYTES = 512;

    public function __construct(private string $projectRoot)
    {
    }

    /**
     * @param list<string> $trackedFiles    project-relative, as `git ls-files` prints them
     * @param list<string> $globs           project-relative; empty means discover
     * @param string       ...$ignoredPaths project-relative directories or files to subtract
     *
     * @return list<string> project-relative, sorted
     */
    public function find(array $trackedFiles, array $globs, string ...$ignoredPaths): array
    {
        $found = [];
        foreach ($trackedFiles as $relative) {
            // git lists what the index holds, which on a dirty tree names files
            // that are not on disk. ShellCheck exits 2 on one of those, and the
            // lane reads exit 2 as a crash.
            if (!is_file($this->projectRoot . '/' . $relative)) {
                continue;
            }

            if ($this->under($relative, ...$ignoredPaths)) {
                continue;
            }

            $wanted = [] === $globs ? $this->isShellFile($relative) : $this->matchesAny($relative, ...$globs);
            if ($wanted) {
                $found[] = $relative;
            }
        }

        sort($found);

        return $found;
    }

    private function matchesAny(string $relative, string ...$globs): bool
    {
        return array_any($globs, static fn (string $glob): bool => fnmatch($glob, $relative));
    }

    private function under(string $relative, string ...$ignoredPaths): bool
    {
        return array_any(
            $ignoredPaths,
            static fn (string $ignored): bool => $relative === $ignored || str_starts_with($relative, rtrim($ignored, '/') . '/'),
        );
    }

    private function isShellFile(string $relative): bool
    {
        foreach (self::EXTENSIONS as $extension) {
            if (str_ends_with($relative, $extension)) {
                return true;
            }
        }

        return \in_array($this->interpreter($relative), self::INTERPRETERS, true);
    }

    /** The interpreter a shebang names, resolved through `env`, or null when there is no shebang. */
    private function interpreter(string $relative): ?string
    {
        $line = $this->firstLine($relative);
        if (null === $line || !str_starts_with($line, self::SHEBANG)) {
            return null;
        }

        $words = array_values(array_filter(
            explode(' ', str_replace("\t", ' ', trim(substr($line, \strlen(self::SHEBANG))))),
            static fn (string $word): bool => '' !== $word,
        ));

        $interpreter = isset($words[0]) ? basename($words[0]) : null;
        if (self::ENV !== $interpreter) {
            return $interpreter;
        }

        // `#!/usr/bin/env bash` names the interpreter in the next word.
        $named = $words[1] ?? null;

        return null === $named ? null : basename($named);
    }

    /** The first line of a tracked file, or null when it cannot be read. */
    private function firstLine(string $relative): ?string
    {
        $absolute = $this->projectRoot . '/' . $relative;
        if (!is_readable($absolute)) {
            return null;
        }

        $handle = \Safe\fopen($absolute, 'rb');
        $line   = fgets($handle, self::SHEBANG_READ_BYTES);
        \Safe\fclose($handle);

        return false === $line ? null : $line;
    }
}
