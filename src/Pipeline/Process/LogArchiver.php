<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Process;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Keeps a rotated history of a tool's log: the live log stays at its known
 * name (downstream tools read it), a timestamped copy is added, and the last
 * ten copies per pattern are kept. Full-suite runs and `-p <path>` runs are
 * separate patterns so a path run never evicts a full-suite log.
 *
 * @api
 */
final readonly class LogArchiver
{
    private const int KEEP = 10;

    private const int WARN_ABOVE = 100;

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

        $total = \count(\Safe\glob($logDir . '/*.' . $extension));
        if ($total > self::WARN_ABOVE) {
            $this->output->writeln('');
            $this->output->writeln('==========================================');
            $this->output->writeln('WARNING: HIGH LOG FILE COUNT');
            $this->output->writeln('==========================================');
            $this->output->writeln(\sprintf('Total %s log files in %s: %d', $toolName, $logDir, $total));
            $this->output->writeln(\sprintf('Retention: %d per pattern (full suite + each -p path); consider clearing old logs.', self::KEEP));
            $this->output->writeln('==========================================');
        }
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
