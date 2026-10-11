<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Process;

use InvalidArgumentException;
use RuntimeException;
use SplFileObject;

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
    /** @param string $proc where the kernel's process table is mounted */
    public function __construct(
        private string $proc = '/proc',
    ) {
    }

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

    /**
     * Running, as opposed to gone or exited and waiting to be reaped. The answer
     * changes as processes exit, so two calls may disagree.
     *
     * @phpstan-impure
     */
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
        if (!is_dir($this->proc)) {
            return [];
        }

        $children = [];
        foreach (\Safe\scandir($this->proc) as $entry) {
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
        $file = $this->proc . '/' . $pid . '/stat';
        if (!is_file($file)) {
            return null;
        }

        try {
            $line = $this->statLine($file);
        } catch (RuntimeException $runtimeException) {
            // A process that exits between the check and the read is gone,
            // which is an answer; one whose /proc entry is still there is a
            // real failure.
            if (is_dir($this->proc . '/' . $pid)) {
                throw $runtimeException;
            }

            return null;
        }

        return explode(' ', substr($line, (int)strrpos($line, ')') + 2));
    }

    /**
     * The stat file's line, or a RuntimeException when it cannot be opened or
     * read. SplFileObject turns a failed open into an exception without the
     * warning file_get_contents() emits first. A read that fails once the file
     * is open (the process exited in between) raises a notice and returns "",
     * so for the length of the read a notice becomes the exception instead,
     * and an empty line is one too: a process's stat line is never empty.
     */
    private function statLine(string $file): string
    {
        $stat = new SplFileObject($file);
        set_error_handler(static function (int $level, string $message) use ($file): never {
            throw new RuntimeException(\sprintf('Cannot read %s: %s', $file, $message), $level);
        });
        try {
            $line = $stat->fgets();
        } finally {
            restore_error_handler();
        }

        if ('' === $line) {
            throw new RuntimeException(\sprintf('Cannot read %s: it is empty', $file));
        }

        return $line;
    }
}
