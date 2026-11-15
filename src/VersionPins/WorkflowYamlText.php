<?php

declare(strict_types=1);

namespace LTS\PHPQA\VersionPins;

/**
 * The text-level reading of a GitHub Actions workflow the version-pin detectors share: a file with
 * its comments removed and its line endings made one kind, the end of a flow list or mapping found
 * through nesting and quotes, and a name with its quotes taken off.
 *
 * The detectors read a workflow for the shape of its triggers and jobs, not by evaluating it, so a
 * comment must not count as the thing it names, in either direction.
 *
 * @internal
 */
final readonly class WorkflowYamlText
{
    /** Characters a quoted scalar can start after; an apostrophe inside a plain word starts nothing. */
    private const string QUOTE_STARTS = " \t[{,";

    /** The file with every line ending a newline, every comment removed and no trailing spaces. */
    public function withoutComments(string $yaml): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $yaml));

        return implode("\n", array_map($this->withoutComment(...), $lines));
    }

    /**
     * The flow list or mapping a text begins with, from its opening bracket to the one that closes
     * it, over as many lines as it takes.
     *
     * @return string|null null when the text does not begin with a bracket or the bracket is never closed
     */
    public function balanced(string $text): ?string
    {
        if ('' === $text || !str_contains('[{', $text[0])) {
            return null;
        }

        $depth  = 0;
        $length = \strlen($text);
        for ($i = 0; $i < $length; ++$i) {
            $end = $this->quoteEnd($text, $i);
            if (null !== $end) {
                $i = $end;

                continue;
            }

            if (str_contains('[{', $text[$i])) {
                ++$depth;
            } elseif (str_contains(']}', $text[$i])) {
                --$depth;
            }

            if (0 === $depth) {
                return substr($text, 0, $i + 1);
            }
        }

        return null;
    }

    /**
     * The entries of a balanced flow list or mapping, split at the commas that are not nested or
     * quoted, each as written.
     *
     * @return list<string>
     */
    public function flowItems(string $balanced): array
    {
        $inner  = substr($balanced, 1, -1);
        $items  = [];
        $start  = 0;
        $depth  = 0;
        $length = \strlen($inner);
        for ($i = 0; $i < $length; ++$i) {
            $end = $this->quoteEnd($inner, $i);
            if (null !== $end) {
                $i = $end;

                continue;
            }

            if (str_contains('[{', $inner[$i])) {
                ++$depth;
            } elseif (str_contains(']}', $inner[$i])) {
                --$depth;
            } elseif (',' === $inner[$i] && 0 === $depth) {
                $items[] = trim(substr($inner, $start, $i - $start));
                $start   = $i + 1;
            }
        }

        $items[] = trim(substr($inner, $start));

        return array_values(array_filter($items, static fn (string $item): bool => '' !== $item));
    }

    /** The text with one matching pair of quotes around it taken off, and the spaces around it. */
    public function unquote(string $text): string
    {
        $text = trim($text);
        if (\strlen($text) >= 2 && ('"' === $text[0] || "'" === $text[0]) && $text[0] === $text[\strlen($text) - 1]) {
            return substr($text, 1, -1);
        }

        return $text;
    }

    /** The count of spaces a line starts with. */
    public function indentOf(string $line): int
    {
        return \strlen($line) - \strlen(ltrim($line, ' '));
    }

    private function withoutComment(string $line): string
    {
        $length = \strlen($line);
        for ($i = 0; $i < $length; ++$i) {
            $end = $this->quoteEnd($line, $i);
            if (null !== $end) {
                $i = $end;

                continue;
            }

            if ('#' === $line[$i] && (0 === $i || \in_array($line[$i - 1], [' ', "\t"], true))) {
                return rtrim(substr($line, 0, $i));
            }
        }

        return rtrim($line);
    }

    /**
     * Where the quoted scalar that opens at position $i ends.
     *
     * A quote opens a scalar only at the start of a token: after a space, a bracket, a comma or the
     * start of the text, so an apostrophe inside a plain word starts nothing. A double-quoted scalar
     * escapes with a backslash, a single-quoted one by doubling its quote.
     *
     * @return int|null the position of the closing quote, the last position when it never closes, or
     *                  null when no quoted scalar opens at $i
     */
    private function quoteEnd(string $text, int $i): ?int
    {
        $quote = $text[$i];
        if (('"' !== $quote && "'" !== $quote) || (0 !== $i && !str_contains(self::QUOTE_STARTS, $text[$i - 1]))) {
            return null;
        }

        $length = \strlen($text);
        $j      = $i + 1;
        while ($j < $length) {
            if ('"' === $quote && '\\' === $text[$j]) {
                $j += 2;

                continue;
            }

            if ($text[$j] === $quote) {
                if ("'" === $quote && $j + 1 < $length && "'" === $text[$j + 1]) {
                    $j += 2;

                    continue;
                }

                return $j;
            }

            ++$j;
        }

        return $length - 1;
    }
}
