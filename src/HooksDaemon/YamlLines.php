<?php

declare(strict_types=1);

namespace LTS\PHPQA\HooksDaemon;

/**
 * A YAML file as lines, read the way a block-style mapping nests: by indent.
 * Enough structure to find a key and the lines that belong to it, and no
 * more: it never parses values, so it can never re-serialise one.
 *
 * Immutable: an edit returns new lines, and the text keeps every line it
 * was not asked to change.
 *
 * @internal
 */
final readonly class YamlLines
{
    private const string LINE_BREAK = "\n";

    private const string COMMENT = '#';

    /** @var list<string> */
    private array $lines;

    public function __construct(string ...$lines)
    {
        $this->lines = array_values($lines);
    }

    public static function fromText(string $text): self
    {
        return new self(...explode(self::LINE_BREAK, $text));
    }

    public function text(): string
    {
        return implode(self::LINE_BREAK, $this->lines);
    }

    public function count(): int
    {
        return \count($this->lines);
    }

    public function line(int $index): string
    {
        return $this->lines[$index];
    }

    public function indent(int $index): int
    {
        return \strlen($this->lines[$index]) - \strlen(ltrim($this->lines[$index], ' '));
    }

    /** A line holding YAML rather than nothing or only a comment. */
    public function isContent(int $index): bool
    {
        $trimmed = trim($this->lines[$index]);

        return '' !== $trimmed && !str_starts_with($trimmed, self::COMMENT);
    }

    /** The file's own nesting step: the indent of its first nested line. */
    public function unit(int $default): int
    {
        foreach (array_keys($this->lines) as $index) {
            if ($this->isContent($index) && $this->indent($index) > 0) {
                return $this->indent($index);
            }
        }

        return $default;
    }

    /** The line holding `$key:` at exactly `$indent`, from `$from` up to (not including) `$to`. */
    public function find(string $key, int $indent, int $from, int $to): ?int
    {
        for ($index = $from; $index < $to; ++$index) {
            if ($this->isContent($index) && $indent === $this->indent($index) && null !== $this->keyed($index, $key)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The inline value and trailing comment of a `$key:` line, or null when
     * the line holds a different key.
     *
     * @return array{value: string, comment: string}|null
     */
    public function keyed(int $index, string $key): ?array
    {
        $prefix  = $key . ':';
        $trimmed = ltrim($this->lines[$index], ' ');
        if (!str_starts_with($trimmed, $prefix)) {
            return null;
        }

        $rest = substr($trimmed, \strlen($prefix));
        if ('' !== $rest && !ctype_space($rest[0])) {
            return null;
        }

        $rest     = trim($rest);
        $position = str_starts_with($rest, self::COMMENT) ? 0 : strpos($rest, ' ' . self::COMMENT);
        if (false === $position) {
            return ['value' => $rest, 'comment' => ''];
        }

        return ['value' => rtrim(substr($rest, 0, $position)), 'comment' => ltrim(substr($rest, $position))];
    }

    /**
     * Where a key's block ends: the next content line no deeper than the key.
     * Comments do not end a block, since a comment's indent says nothing
     * about which key it belongs to.
     */
    public function end(int $key): int
    {
        $count = $this->count();
        for ($index = $key + 1; $index < $count; ++$index) {
            if ($this->isContent($index) && $this->indent($index) <= $this->indent($key)) {
                return $index;
            }
        }

        return $count;
    }

    /**
     * The last line that belongs to a key's block: deeper than the key and not
     * blank. A trailing comment no deeper than the key introduces whatever
     * follows, so it is not the block's.
     */
    public function lastOwned(int $key, int $end): int
    {
        $last = $key;
        for ($index = $key + 1; $index < $end; ++$index) {
            if ('' !== trim($this->lines[$index]) && $this->indent($index) > $this->indent($key)) {
                $last = $index;
            }
        }

        return $last;
    }

    /** The indent of a key's first child, or null when it has none. */
    public function childIndent(int $key, int $end): ?int
    {
        for ($index = $key + 1; $index < $end; ++$index) {
            if ($this->isContent($index)) {
                return $this->indent($index);
            }
        }

        return null;
    }

    /** @return list<string> the lines from `$from` to `$to`, both included */
    public function between(int $from, int $to): array
    {
        return \array_slice($this->lines, $from, $to - $from + 1);
    }

    public function withInserted(int $at, string ...$insert): self
    {
        return $this->withReplaced($at, 0, ...$insert);
    }

    public function withReplaced(int $from, int $length, string ...$replacement): self
    {
        $lines = $this->lines;
        array_splice($lines, $from, $length, array_values($replacement));

        return new self(...$lines);
    }
}
