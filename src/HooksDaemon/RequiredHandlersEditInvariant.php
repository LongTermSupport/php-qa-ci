<?php

declare(strict_types=1);

namespace LTS\PHPQA\HooksDaemon;

use LTS\PHPQA\HooksDaemon\Dto\RequiredHandlersEditDto;

/**
 * The safety check on an edit already computed: read the result back, and
 * hold only when the file is still a layout the editor reads, every required
 * setting is right in it, and nothing but lines of the enforced keys moved.
 * No YAML parser is a production dependency, so the read-back is the
 * editor's own reader (RequiredHandlersShape) over the new text, and the
 * comparison is a line diff against the original.
 *
 * @internal
 */
final readonly class RequiredHandlersEditInvariant
{
    /** The most cells of the line diff table that are worth filling for a config file. */
    private const int MAX_DIFF_CELLS = 4_000_000;

    public function __construct(
        private RequiredHandlersShape $shape = new RequiredHandlersShape(),
    ) {
    }

    /** @return string|null why the edit must not be written, or null when it is safe to */
    public function violation(string $original, RequiredHandlersEditDto $edit): ?string
    {
        if ([] === $edit->changes) {
            return $edit->yaml === $original ? null : 'the text changed although no setting was reported changed';
        }

        $after   = YamlLines::fromText($edit->yaml);
        $blocker = $this->shape->blocker($after);
        if (null !== $blocker) {
            return 'the edited file is not a layout the editor reads: ' . $blocker;
        }

        foreach (RequiredHandlersYamlEditor::REQUIRED as $required) {
            $written = $this->shape->settingValue($after, RequiredHandlersShape::pathOf($required));
            if (null === $written || !$this->shape->holds($written, $required['value'])) {
                return $required['handler'] . '.' . implode('.', $required['path']) . ' is not ' . $required['value'] . ' in the edited file';
            }
        }

        return $this->diffViolation(explode("\n", $original), explode("\n", $edit->yaml), \count($edit->changes));
    }

    /**
     * @param array<int, string> $before
     * @param array<int, string> $after
     */
    private function diffViolation(array $before, array $after, int $changes): ?string
    {
        $diff = $this->diff($before, $after);
        if (null === $diff) {
            return 'the edit is too large to check line by line';
        }

        [$removed, $added] = $diff;
        $addedHeads        = array_map($this->head(...), $added);
        foreach ($removed as $line) {
            if (null === $this->head($line) || !\in_array($this->head($line), $addedHeads, true)) {
                return \sprintf('the line "%s" was removed', $line);
            }
        }

        foreach ($added as $line) {
            if ('' !== trim($line) && null === $this->head($line)) {
                return \sprintf('the line "%s" was added', $line);
            }
        }

        if (\count($removed) > $changes) {
            return \sprintf('%d lines were removed for %d reported changes', \count($removed), $changes);
        }

        return null;
    }

    /**
     * The indent and key of a line holding an enforced key, or null for any other line.
     */
    private function head(string $line): ?string
    {
        if (1 !== \Safe\preg_match('/^( *)([A-Za-z0-9_]+):(?:\s|$)/', $line, $match) || !isset($match[1], $match[2]) || !\in_array($match[2], $this->enforcedKeys(), true)) {
            return null;
        }

        return $match[1] . $match[2];
    }

    /** @return list<string> every key on the path to a required setting */
    private function enforcedKeys(): array
    {
        $keys = [];
        foreach (RequiredHandlersYamlEditor::REQUIRED as $required) {
            array_push($keys, ...RequiredHandlersShape::pathOf($required));
        }

        return array_values(array_unique($keys));
    }

    /**
     * The lines only in `$before` and the lines only in `$after`, from a
     * longest-common-subsequence diff of the lines between their shared
     * first and last lines; null when that table would be too large.
     *
     * @param array<int, string> $before
     * @param array<int, string> $after
     *
     * @return array{list<string>, list<string>}|null
     */
    private function diff(array $before, array $after): ?array
    {
        $start = 0;
        $limit = min(\count($before), \count($after));
        while ($start < $limit && $before[$start] === $after[$start]) {
            ++$start;
        }

        $tail = 0;
        while ($tail < $limit - $start && $before[\count($before) - 1 - $tail] === $after[\count($after) - 1 - $tail]) {
            ++$tail;
        }

        $left  = \array_slice($before, $start, \count($before) - $start - $tail);
        $right = \array_slice($after, $start, \count($after) - $start - $tail);
        if (\count($left) * \count($right) > self::MAX_DIFF_CELLS) {
            return null;
        }

        return $this->walkTable($left, $right, $this->commonLengths($left, $right));
    }

    /**
     * @param array<int, string> $left
     * @param array<int, string> $right
     *
     * @return array<int, array<int, int>> the length of the common subsequence of the lines from each pair of positions on
     */
    private function commonLengths(array $left, array $right): array
    {
        $table = array_fill(0, \count($left) + 1, array_fill(0, \count($right) + 1, 0));
        for ($i = \count($left) - 1; $i >= 0; --$i) {
            for ($j = \count($right) - 1; $j >= 0; --$j) {
                $table[$i][$j] = $left[$i] === $right[$j] ? $table[$i + 1][$j + 1] + 1 : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }

        return $table;
    }

    /**
     * @param array<int, string>          $left
     * @param array<int, string>          $right
     * @param array<int, array<int, int>> $table
     *
     * @return array{list<string>, list<string>}
     */
    private function walkTable(array $left, array $right, array $table): array
    {
        $removed = [];
        $added   = [];
        $i       = 0;
        $j       = 0;
        while ($i < \count($left) && $j < \count($right)) {
            if ($left[$i] === $right[$j]) {
                ++$i;
                ++$j;
            } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $removed[] = $left[$i++];
            } else {
                $added[] = $right[$j++];
            }
        }

        return [[...$removed, ...\array_slice($left, $i)], [...$added, ...\array_slice($right, $j)]];
    }
}
