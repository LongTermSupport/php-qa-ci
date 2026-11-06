<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\ShellCheck;

use LTS\PHPQA\Pipeline\Lane\ShellCheck\Dto\DaemonOwnedSplitDto;

/**
 * Which tracked shell files belong to the Claude Code hooks daemon rather than
 * to the project, so the shellCheck lane does not report defects the project
 * cannot fix: the daemon rewrites them on every upgrade.
 *
 * Two kinds are daemon-owned:
 *
 * - a script the daemon generates, which says so itself in a header comment
 *   line beginning `# DAEMON-OWNED FILE` near the top of every one. The marker
 *   decides, not the directory, so a hand-written script in the same
 *   directory (`.claude/hooks/`) is still checked;
 * - anything under the daemon's install folder, `.claude/hooks-daemon/`, which
 *   is third-party code whenever a project tracks it.
 *
 * @internal
 */
final readonly class DaemonOwnedFiles
{
    /** What the daemon writes at the start of a header comment line in every file it generates. */
    public const string MARKER = '# DAEMON-OWNED FILE';

    /** The daemon's install folder, project-relative. */
    public const string INSTALL_FOLDER = '.claude/hooks-daemon';

    /** A shebang, a comment rule and the marker sit at the top; the marker is never deeper than this. */
    private const int HEADER_LINES = 10;

    /** The most bytes read of one header line. */
    private const int LINE_BYTES = 512;

    public function __construct(private string $projectRoot)
    {
    }

    /**
     * @param string ...$files project-relative
     */
    public function partition(string ...$files): DaemonOwnedSplitDto
    {
        $kept    = [];
        $skipped = [];
        foreach ($files as $relative) {
            if ($this->isOwnedByDaemon($relative)) {
                $skipped[] = $relative;

                continue;
            }

            $kept[] = $relative;
        }

        return new DaemonOwnedSplitDto($kept, $skipped);
    }

    private function isOwnedByDaemon(string $relative): bool
    {
        return str_starts_with($relative, self::INSTALL_FOLDER . '/') || $this->carriesMarker($relative);
    }

    private function carriesMarker(string $relative): bool
    {
        $absolute = $this->projectRoot . '/' . $relative;
        if (!is_file($absolute) || !is_readable($absolute)) {
            return false;
        }

        $handle = \Safe\fopen($absolute, 'rb');
        try {
            for ($line = 0; $line < self::HEADER_LINES; ++$line) {
                $text = fgets($handle, self::LINE_BYTES);
                if (false === $text) {
                    return false;
                }

                if (str_starts_with($text, self::MARKER)) {
                    return true;
                }
            }
        } finally {
            \Safe\fclose($handle);
        }

        return false;
    }
}
