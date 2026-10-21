<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Process;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Keeps a rotated history of a tool's log: the live log stays at its known
 * name (downstream tools read it), a timestamped copy is added, and the last
 * ten copies per pattern are kept. Full-suite runs and `-p <path>` runs are
 * separate patterns so a path run never evicts a full-suite log. A cap on the
 * tool's total archives then bounds the directory, which per-pattern retention
 * alone cannot do.
 *
 * @api
 */
final readonly class LogArchiver
{
    /**
     * Per-pattern retention cannot bound the directory. A `-p` run derives its
     * pattern from the paths it covered, so every distinct file analysed mints
     * a new pattern holding up to KEEP of its own — a hundred one-off path runs
     * are a hundred patterns, each comfortably under its limit and none of them
     * ever pruned. This cap is what actually bounds the directory.
     */
    public const int DIRECTORY_CAP = 100;

    /** How much history one pattern keeps: enough to compare a few runs, not enough to hoard. */
    private const int KEEP = 10;

    /**
     * The longest path-derived suffix an archive name carries. A file name may be 255 bytes in all, and the
     * suffix sits between the tool's log name, a timestamp and an extension, so this leaves them ample room.
     */
    private const int MAX_SUFFIX_BYTES = 120;

    /** Hex characters of the digest that keeps a shortened suffix unique to its full path list. */
    private const int DIGEST_HEX_LENGTH = 12;

    public function __construct(private OutputInterface $output)
    {
    }

    /**
     * @param list<string> $pathsChecked the paths this run covered, used to name a path-specific archive
     */
    public function archive(string $toolName, string $logDir, string $logFileName, bool $pathSpecific, array $pathsChecked, ?string $timestamp = null): void
    {
        $live = $logDir . '/' . $logFileName;
        if (!is_file($live)) {
            return;
        }

        $timestamp ??= date('Ymd-His');
        $base      = pathinfo($logFileName, \PATHINFO_FILENAME);
        $extension = pathinfo($logFileName, \PATHINFO_EXTENSION);

        if ($pathSpecific) {
            $suffix   = $this->suffixFor(...$pathsChecked);
            $archive  = \sprintf('%s/%s.%s.%s.%s', $logDir, $base, $suffix, $timestamp, $extension);
            $pattern  = \sprintf('/^%s\.%s\.\d{8}-\d{6}\.%s$/', preg_quote($base, '/'), preg_quote($suffix, '/'), preg_quote($extension, '/'));
            $runType  = 'Path-specific run: ' . implode(' ', $pathsChecked);
        } else {
            $archive = \sprintf('%s/%s.%s.%s', $logDir, $base, $timestamp, $extension);
            $pattern = \sprintf('/^%s\.\d{8}-\d{6}\.%s$/', preg_quote($base, '/'), preg_quote($extension, '/'));
            $runType = 'Full test suite run';
        }

        \Safe\copy($live, $archive);
        $this->output->writeln($runType);
        $this->output->writeln('Log: ' . basename($archive));

        $matching = $this->matching($logDir, static fn (string $entry): bool => 1 === \Safe\preg_match($pattern, $entry));
        if (\count($matching) > self::KEEP) {
            $evicted = \array_slice($matching, self::KEEP);
            foreach ($evicted as $old) {
                \Safe\unlink($old);
            }

            $this->output->writeln(\sprintf('Cleaned up %d old log(s)', \count($evicted)));
        }

        $this->capDirectory($toolName, $logDir, $base, $extension);
    }

    /**
     * The path-specific part of an archive name: the covered paths with everything but letters and digits
     * collapsed to `_`. A run over deep absolute paths, or over several paths, makes that longer than a file
     * name may be, and the copy then fails with "File name too long". Past {@see MAX_SUFFIX_BYTES} the suffix
     * is cut and ends in a digest of the whole sanitised list, so it stays short, stays the same for the
     * same paths (retention still groups the runs) and differs for paths that merely share a long prefix.
     */
    private function suffixFor(string ...$pathsChecked): string
    {
        $replaced = \Safe\preg_replace('/[^[:alnum:]]+/', '_', implode(' ', $pathsChecked));
        $suffix   = trim(\is_string($replaced) ? $replaced : implode('_', $replaced), '_');

        if (\strlen($suffix) <= self::MAX_SUFFIX_BYTES) {
            return $suffix;
        }

        $digest = substr(hash('sha256', $suffix), 0, self::DIGEST_HEX_LENGTH);

        return rtrim(substr($suffix, 0, self::MAX_SUFFIX_BYTES - self::DIGEST_HEX_LENGTH - 1), '_') . '_' . $digest;
    }

    /**
     * Prune this tool's archives, oldest first, down to the cap. Only files
     * matching the archive shape for this tool are considered, so the live log
     * and another tool's logs in the same directory are never touched.
     */
    private function capDirectory(string $toolName, string $logDir, string $base, string $extension): void
    {
        $anyArchive = \sprintf(
            '/^%s(\..+)?\.\d{8}-\d{6}\.%s$/',
            preg_quote($base, '/'),
            preg_quote($extension, '/'),
        );

        $archives = $this->matching($logDir, static fn (string $entry): bool => 1 === \Safe\preg_match($anyArchive, $entry));
        if (\count($archives) <= self::DIRECTORY_CAP) {
            return;
        }

        $pruned = \array_slice($archives, self::DIRECTORY_CAP);
        foreach ($pruned as $old) {
            \Safe\unlink($old);
        }

        $this->output->writeln(\sprintf(
            'Pruned %d %s log(s): the directory cap is %d archives.',
            \count($pruned),
            $toolName,
            self::DIRECTORY_CAP,
        ));
    }

    /**
     * @param Closure(string): bool $isArchive whether a directory entry is one of the archives wanted
     *
     * @return list<string> newest first
     */
    private function matching(string $logDir, Closure $isArchive): array
    {
        $files = [];
        foreach (\Safe\scandir($logDir) as $entry) {
            if (\is_string($entry) && $isArchive($entry)) {
                $files[] = $logDir . '/' . $entry;
            }
        }

        usort($files, static function (string $a, string $b): int {
            $byTime = \Safe\filemtime($b) <=> \Safe\filemtime($a);

            return 0 !== $byTime ? $byTime : strcmp($b, $a);
        });

        return $files;
    }
}
