<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The rule for how a remote action is named in a workflow this repository runs or ships, and in the
 * documentation that teaches one: a full commit SHA with the release it is beside it, never a tag.
 *
 * A tag is a pointer its owner can move, so whoever controls the action's repository decides what
 * runs. A reference to an action in this repository (`./`) is a path, not a tag, and is exempt.
 */
final readonly class ActionPins
{
    /** `uses: <reference>`, the key plain or quoted, the reference being everything up to a comment, a quote or the end of the line. */
    private const string USES = '/^\s*-?\s*["\']?uses["\']?\s*:\s*["\']?([^\s#"\']+)/';

    /** A `uses` entry of a flow mapping (`{... uses: owner/repo@ref ...}`), the key and the value plain or quoted. */
    private const string FLOW_USES = '/[{,]\s*["\']?uses["\']?\s*:\s*["\']?([A-Za-z0-9_.-]+\/[A-Za-z0-9_.\/-]+@[^\s,"\'}\]]+)/';

    /** What a line of a blockquote starts with: up to three spaces, then one marker per level of quoting. */
    private const string QUOTE_PREFIX = '/^(\s{0,3}(?:>[ ]?)+)/';

    /** `owner/repo[/path]@<40 hex digits>` followed by the release it is, as `# v1.2.3`. */
    private const string PINNED = '/^\s*-?\s*uses:\s*[A-Za-z0-9_.-]+\/[A-Za-z0-9_.\/-]+@[0-9a-f]{40}\s+#\s+v\d+(\.\d+)*\s*$/';

    /** The opening of a fenced code block: any indent, then three or more backticks or tildes, then the info string. */
    private const string FENCE_OPEN = '/^(\s*)(`{3,}|~{3,})\s*([A-Za-z]*)/';

    /** The info strings of a fence that holds YAML. */
    private const array YAML_INFO = ['yaml', 'yml'];

    /** The key a step names its action under. */
    private const string USES_KEY = 'uses';

    private function __construct()
    {
    }

    /**
     * The remote references in the fenced code blocks of a page, each that is not a commit SHA with
     * its release beside it. Prose outside a fence is never read.
     *
     * A fence tagged `yaml` or `yml` is read as YAML, so a step written as a flow mapping or with
     * quoted keys is found, and one that is not valid YAML throws `ParseException` naming `$source`:
     * an example a reader copies should parse. Any other fence (a shell example, a markdown example
     * holding a fence) is read line by line, which finds a step on a line of its own and a flow
     * mapping that names a remote action.
     *
     * @param string $source what the failure for a fence that does not parse calls the page
     *
     * @return list<string> one message per reference
     */
    public static function unpinnedInMarkdown(string $markdown, string $source = 'the page'): array
    {
        $problems = [];
        foreach (self::fencedBlocks($markdown) as $block) {
            if (!\in_array($block['info'], self::YAML_INFO, true)) {
                array_push($problems, ...self::unpinnedLines($block['text']));

                continue;
            }

            try {
                array_push($problems, ...self::unpinnedInWorkflow($block['text']));
            } catch (ParseException $e) {
                throw new ParseException($source . ': a fence tagged yaml does not parse: ' . $e->getMessage(), previous: $e);
            }
        }

        return $problems;
    }

    /**
     * The remote references in the text, each that is not a commit SHA with its release beside it:
     * those on lines that start with `uses:` (the key plain or quoted), and every `uses` entry of a
     * flow mapping that names a remote action, which is reported whatever it names, since the
     * pinned shape is a block line with the release beside it.
     *
     * @return list<string> one message per reference
     */
    public static function unpinnedLines(string $text): array
    {
        $problems = [];
        foreach (explode("\n", $text) as $line) {
            if (1 === \Safe\preg_match(self::USES, $line, $match) && isset($match[1]) && !str_starts_with($match[1], './') && 1 !== \Safe\preg_match(self::PINNED, $line)) {
                $problems[] = self::message($match[1]);
            }

            foreach (self::flowReferences($line) as $reference) {
                $problems[] = self::message($reference);
            }
        }

        return $problems;
    }

    /**
     * Every remote reference the YAML of a workflow holds, at any depth and written any way, that is
     * not matched by a line in the pinned shape.
     *
     * The YAML says what the workflow names; the lines say how it is written. A reference the YAML
     * holds that no pinned line accounts for is a tag, a branch, a commit with no release beside
     * it, or a form (a flow mapping, a quoted value) this repository does not write a step in.
     *
     * @return list<string> one message per reference
     */
    public static function unpinnedInWorkflow(string $workflow): array
    {
        $tree     = Yaml::parse($workflow);
        $pinned   = self::pinnedReferences($workflow, $tree);
        $problems = [];
        foreach (self::references($tree) as $reference) {
            if (str_starts_with($reference, './')) {
                continue;
            }

            $index = array_search($reference, $pinned, true);
            if (false === $index) {
                $problems[] = self::message($reference);

                continue;
            }

            unset($pinned[$index]);
        }

        return $problems;
    }

    /**
     * Each fenced code block, with the first word of its info string and its text, and nothing
     * else. A fence closes on a line of the same character at least as long as the one that opened
     * it, so a fence of four backticks holds one of three; a fence never closed runs to the end of
     * the page. The indent a fence opens at is taken off the lines inside it. A fence opened inside
     * a blockquote has the quote markers taken off its lines and ends at the first line that is
     * not quoted.
     *
     * @return list<array{info: string, text: string}>
     */
    private static function fencedBlocks(string $markdown): array
    {
        $blocks = [];
        $open   = null;
        foreach (explode("\n", $markdown) as $raw) {
            [$line, $quoted] = self::withoutQuote($raw);
            if (null !== $open && $open['quoted'] && !$quoted) {
                $blocks[] = ['info' => $open['info'], 'text' => implode("\n", $open['lines'])];
                $open     = null;
            }

            if (null === $open) {
                if (1 === \Safe\preg_match(self::FENCE_OPEN, $line, $match) && isset($match[1], $match[2], $match[3])) {
                    $open = ['char' => $match[2][0], 'length' => \strlen($match[2]), 'indent' => $match[1], 'info' => strtolower($match[3]), 'quoted' => $quoted, 'lines' => []];
                }

                continue;
            }

            $line    = $open['quoted'] ? $line : $raw;
            $trimmed = trim($line);
            if (\strlen($trimmed) >= $open['length'] && '' === trim($trimmed, $open['char'])) {
                $blocks[] = ['info' => $open['info'], 'text' => implode("\n", $open['lines'])];
                $open     = null;

                continue;
            }

            $open['lines'][] = str_starts_with($line, $open['indent']) ? substr($line, \strlen($open['indent'])) : ltrim($line);
        }

        if (null !== $open) {
            $blocks[] = ['info' => $open['info'], 'text' => implode("\n", $open['lines'])];
        }

        return $blocks;
    }

    /** @return list<string> the remote reference of each `uses` entry of a flow mapping on the line */
    private static function flowReferences(string $line): array
    {
        $references = [];
        $matches    = [];
        \Safe\preg_match_all(self::FLOW_USES, $line, $matches);
        if (!\is_array($matches) || !isset($matches[1]) || !\is_array($matches[1])) {
            return $references;
        }

        foreach ($matches[1] as $reference) {
            if (\is_string($reference)) {
                $references[] = $reference;
            }
        }

        return $references;
    }

    /**
     * The line with its blockquote markers taken off, and whether it had any.
     *
     * @return array{string, bool}
     */
    private static function withoutQuote(string $line): array
    {
        if (1 === \Safe\preg_match(self::QUOTE_PREFIX, $line, $match) && isset($match[1])) {
            return [substr($line, \strlen($match[1])), true];
        }

        return [$line, false];
    }

    /**
     * The reference on each line in the pinned shape, except a line that is text inside a scalar
     * the YAML holds (a `run:` block quoting a step): that line is shell, not a step, and accounts
     * for no reference.
     *
     * @return list<string>
     */
    private static function pinnedReferences(string $workflow, mixed $tree): array
    {
        $scalars    = self::scalars($tree);
        $references = [];
        foreach (explode("\n", $workflow) as $line) {
            if (1 !== \Safe\preg_match(self::PINNED, $line) || 1 !== \Safe\preg_match(self::USES, $line, $match) || !isset($match[1])) {
                continue;
            }

            if (self::consumedByScalar(self::normalised($line), $scalars)) {
                continue;
            }

            $references[] = $match[1];
        }

        return $references;
    }

    /**
     * Whether a scalar holds the text, taking one occurrence out of it so that a scalar quoting a
     * step once accounts for one line and not for every line the same.
     *
     * @param list<string> $scalars
     */
    private static function consumedByScalar(string $text, array &$scalars): bool
    {
        foreach ($scalars as $index => $scalar) {
            $at = strpos($scalar, $text);
            if (false !== $at) {
                $scalars[$index] = substr_replace($scalar, '', $at, \strlen($text));

                return true;
            }
        }

        return false;
    }

    /**
     * Every string a parsed node holds, other than a `uses` value. A literal block keeps its lines
     * and a folded one joins them, so a line is looked for inside the text, not as the whole of it.
     *
     * @return list<string>
     */
    private static function scalars(mixed $node): array
    {
        if (!\is_array($node)) {
            return [];
        }

        $scalars = [];
        foreach ($node as $key => $value) {
            if (self::USES_KEY === $key) {
                continue;
            }

            if (\is_string($value)) {
                $scalars[] = $value;

                continue;
            }

            array_push($scalars, ...self::scalars($value));
        }

        return $scalars;
    }

    private static function normalised(string $line): string
    {
        $trimmed = trim($line);

        return str_starts_with($trimmed, '- ') ? ltrim(substr($trimmed, 2)) : $trimmed;
    }

    /** @return list<string> the value of every `uses` key under a parsed node, in document order */
    private static function references(mixed $node): array
    {
        if (!\is_array($node)) {
            return [];
        }

        $references = [];
        foreach ($node as $key => $value) {
            if (self::USES_KEY === $key && \is_string($value)) {
                $references[] = $value;

                continue;
            }

            array_push($references, ...self::references($value));
        }

        return $references;
    }

    private static function message(string $reference): string
    {
        return $reference . ' is not a commit SHA with its release beside it';
    }
}
