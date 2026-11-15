<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

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
    /** `uses: <reference>`, the reference being everything up to a comment or the end of the line. */
    private const string USES = '/^\s*-?\s*uses:\s*([^\s#]+)/';

    /** `owner/repo[/path]@<40 hex digits>` followed by the release it is, as `# v1.2.3`. */
    private const string PINNED = '/^\s*-?\s*uses:\s*[A-Za-z0-9_.-]+\/[A-Za-z0-9_.\/-]+@[0-9a-f]{40}\s+#\s+v\d+(\.\d+)*\s*$/';

    /** The opening of a fenced code block: any indent, then three or more backticks or tildes, then the info string. */
    private const string FENCE_OPEN = '/^(\s*)(`{3,}|~{3,})\s*(\S*)/';

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
     * quoted keys is found, and one that is not valid YAML throws `ParseException`: an example a
     * reader copies should parse. Any other fence (a shell example, a markdown example holding a
     * fence) is read line by line.
     *
     * @return list<string> one message per reference
     */
    public static function unpinnedInMarkdown(string $markdown): array
    {
        $problems = [];
        foreach (self::fencedBlocks($markdown) as $block) {
            array_push(
                $problems,
                ...(\in_array($block['info'], self::YAML_INFO, true)
                    ? self::unpinnedInWorkflow($block['text'])
                    : self::unpinnedLines($block['text'])),
            );
        }

        return $problems;
    }

    /**
     * The remote references on lines that start with `uses:`, each that is not a commit SHA with its
     * release beside it.
     *
     * @return list<string> one message per reference
     */
    public static function unpinnedLines(string $text): array
    {
        $problems = [];
        foreach (explode("\n", $text) as $line) {
            if (1 !== \Safe\preg_match(self::USES, $line, $match) || !isset($match[1])) {
                continue;
            }

            if (str_starts_with($match[1], './')) {
                continue;
            }

            if (1 !== \Safe\preg_match(self::PINNED, $line)) {
                $problems[] = self::message($match[1]);
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
     * the page. The indent a fence opens at is taken off the lines inside it.
     *
     * @return list<array{info: string, text: string}>
     */
    private static function fencedBlocks(string $markdown): array
    {
        $blocks = [];
        $open   = null;
        foreach (explode("\n", $markdown) as $line) {
            if (null === $open) {
                if (1 === \Safe\preg_match(self::FENCE_OPEN, $line, $match) && isset($match[1], $match[2], $match[3])) {
                    $open = ['char' => $match[2][0], 'length' => \strlen($match[2]), 'indent' => $match[1], 'info' => strtolower($match[3]), 'lines' => []];
                }

                continue;
            }

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

    /**
     * The reference on each line in the pinned shape, except a line that is text inside a scalar
     * the YAML holds (a `run:` block quoting a step): that line is shell, not a step, and accounts
     * for no reference.
     *
     * @return list<string>
     */
    private static function pinnedReferences(string $workflow, mixed $tree): array
    {
        $scalarLines = self::scalarLines($tree);
        $references  = [];
        foreach (explode("\n", $workflow) as $line) {
            if (1 !== \Safe\preg_match(self::PINNED, $line) || 1 !== \Safe\preg_match(self::USES, $line, $match) || !isset($match[1])) {
                continue;
            }

            $index = array_search(self::normalised($line), $scalarLines, true);
            if (false !== $index) {
                unset($scalarLines[$index]);

                continue;
            }

            $references[] = $match[1];
        }

        return $references;
    }

    /** @return list<string> every line of every string a parsed node holds, other than a `uses` value */
    private static function scalarLines(mixed $node): array
    {
        if (!\is_array($node)) {
            return [];
        }

        $lines = [];
        foreach ($node as $key => $value) {
            if (self::USES_KEY === $key) {
                continue;
            }

            if (\is_string($value)) {
                foreach (explode("\n", $value) as $line) {
                    $lines[] = self::normalised($line);
                }

                continue;
            }

            array_push($lines, ...self::scalarLines($value));
        }

        return $lines;
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
