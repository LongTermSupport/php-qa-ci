<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Process;

use InvalidArgumentException;
use Safe\Exceptions\FilesystemException;

/**
 * Processes as the kernel reports them in /proc: who descends from whom, and
 * who is still running. Linux only. Without /proc a process has no
 * descendants and nothing is alive, so a caller falls back to what it can see
 * of its own children.
 *
 * @internal
 */
final readonly class ProcessTree
{
    private const string PROC = '/proc';

    /**
     * Every process below $pid, at any depth, as of now. Asking for the
     * descendants of init or of 0 (a null pid cast to int) is refused: the
     * answer is every process on the host, and the caller means to stop them.
     *
     * @return list<int>
     */
    public function descendantsOf(int $pid): array
    {
        if ($pid < 2) {
            throw new InvalidArgumentException(\sprintf('Refusing to walk the process tree from pid %d: that is every process on the host.', $pid));
        }

        return $this->below($pid, $this->childrenByParent());
    }

    /** Running, as opposed to gone or exited and waiting to be reaped. */
    public function isAlive(int $pid): bool
    {
        $fields = $this->statFields($pid);

        return null !== $fields && 'Z' !== $fields[0] && 'X' !== $fields[0];
    }

    /**
     * @param array<int, list<int>> $children child pids by parent pid
     *
     * @return list<int>
     */
    private function below(int $pid, array $children): array
    {
        $found = [];
        foreach ($children[$pid] ?? [] as $child) {
            $found = [...$found, $child, ...$this->below($child, $children)];
        }

        return $found;
    }

    /** @return array<int, list<int>> child pids by parent pid */
    private function childrenByParent(): array
    {
        if (!is_dir(self::PROC)) {
            return [];
        }

        $children = [];
        foreach (\Safe\scandir(self::PROC) as $entry) {
            if (!\is_string($entry) || 1 !== \Safe\preg_match('/^\d+$/', $entry)) {
                continue;
            }

            $fields = $this->statFields((int)$entry);
            if (null !== $fields) {
                $children[(int)($fields[1] ?? 0)][] = (int)$entry;
            }
        }

        return $children;
    }

    /**
     * The /proc/<pid>/stat fields after the command name (state first, then
     * the parent pid), or null once the process has gone. The name is in
     * parentheses and may itself hold spaces or ')', so the fields start after
     * the last ')'.
     *
     * @return list<string>|null
     */
    private function statFields(int $pid): ?array
    {
        $file = self::PROC . '/' . $pid . '/stat';
        if (!is_file($file)) {
            return null;
        }

        try {
            $line = \Safe\file_get_contents($file);
        } catch (FilesystemException $filesystemException) {
            // A process that exits between the check and the read is gone,
            // which is an answer; one whose /proc entry is still there is a
            // real failure.
            if (is_dir(self::PROC . '/' . $pid)) {
                throw $filesystemException;
            }

            return null;
        }

        return explode(' ', substr($line, (int)strrpos($line, ')') + 2));
    }
}
