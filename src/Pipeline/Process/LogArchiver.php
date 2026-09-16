<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Process;

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
            $replaced = \Safe\preg_replace('/[^[:alnum:]]+/', '_', implode(' ', $pathsChecked));
            $suffix   = trim(\is_string($replaced) ? $replaced : implode('_', $replaced), '_');
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

        $matching = $this->matching($logDir, $pattern);
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

        $archives = $this->matching($logDir, $anyArchive);
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

    /** @return list<string> newest first */
    private function matching(string $logDir, string $pattern): array
    {
        $files = [];
        foreach (\Safe\scandir($logDir) as $entry) {
            if (\is_string($entry) && 1 === \Safe\preg_match($pattern, $entry)) {
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
