<?php

declare(strict_types=1);

namespace LTS\PHPQA\HooksDaemon;

/**
 * Which Claude Code hooks daemon is installed, read from the daemon's own
 * version module rather than by running its CLI: the module is the daemon's
 * single source of truth for its version, and reading it needs no Python
 * environment (a container may mount a daemon whose venv cannot run there).
 *
 * @internal
 */
final readonly class HooksDaemonVersionReader
{
    /** Relative to the daemon root (`<project>/.claude/hooks-daemon`). */
    public const string VERSION_FILE = 'src/claude_code_hooks_daemon/version.py';

    /** `__version__ = "X.Y.Z"`, with any pre-release or build suffix the version carries. */
    private const string VERSION_ASSIGNMENT = '/^__version__\s*=\s*["\'](?<version>\d+\.\d+\.\d+[0-9A-Za-z.+-]*)["\']/m';

    public function read(string $daemonRoot): ?string
    {
        $file = $daemonRoot . '/' . self::VERSION_FILE;
        if (!is_file($file)) {
            return null;
        }

        if (1 !== \Safe\preg_match(self::VERSION_ASSIGNMENT, \Safe\file_get_contents($file), $found)) {
            return null;
        }

        return $found['version'] ?? null;
    }

    /** Compared as versions, not strings: a larger minor sorts later and a pre-release sorts before its release. */
    public function isAtLeast(string $version, string $minimum): bool
    {
        return version_compare($version, $minimum, '>=');
    }
}
