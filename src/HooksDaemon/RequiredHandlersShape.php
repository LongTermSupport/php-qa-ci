<?php

declare(strict_types=1);

namespace LTS\PHPQA\HooksDaemon;

/**
 * Whether a daemon config is laid out the one way RequiredHandlersYamlEditor
 * can edit in place, and where each required setting sits when it is.
 *
 * The supported layout is plain block mappings along the whole path to every
 * required setting: space indentation, unquoted keys, each key once, a value
 * on the key's line only where it is a scalar. Anything else on that path
 * (a quoted or complex key, a tab, a duplicate key, a list, a flow collection,
 * an anchor, alias or tag, a merge key, a second document) is named and the
 * caller leaves the file as it is, because the YAML loader would read an
 * edited file differently from how the text reads: a second `handlers:` key
 * replaces the first, and a mapping item under a list is invalid.
 *
 * @internal
 */
final readonly class RequiredHandlersShape
{
    /** A key written without quotes or indicators, then its colon. */
    private const string PLAIN_KEY = '/^([A-Za-z0-9_][A-Za-z0-9_.\/-]*):(?:\s|$)/';

    /** A line that starts or ends a YAML document. */
    private const string DOCUMENT_MARKER = '/^(---|\.\.\.)(?:\s|$)/';

    /** The inline value that opens into a block without changing its meaning. */
    private const string EMPTY_FLOW_MAPPING = '{}';

    /** What an inline value may not start with: anchor, alias, tag, block scalar, flow collection. */
    private const string INDICATORS = '&*!|>{[';

    /** The spellings YAML 1.1 reads as true, which PyYAML and the daemon accept. */
    private const array TRUE_SPELLINGS = ['true', 'yes', 'on'];

    /** What a problem with the root block is called, since the root has no key. */
    private const string TOP_LEVEL ='the top level';

    /** The name of the first part of the file the editor cannot edit in place, or null. */
    public function blocker(YamlLines $lines): ?string
    {
        $whole = $this->wholeFileBlocker($lines);
        if (null !== $whole) {
            return $whole;
        }

        foreach (RequiredHandlersYamlEditor::REQUIRED as $required) {
            $found = $this->walk($lines, self::pathOf($required));
            if (\is_string($found)) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The inline value of the setting at `$path`, or null when the file does
     * not hold it (or the layout on the way to it is not a supported one).
     *
     * @param array<int, string> $path
     */
    public function settingValue(YamlLines $lines, array $path): ?string
    {
        $found = $this->walk($lines, $path);
        if (!\is_int($found)) {
            return null;
        }

        return $lines->parts($found, $path[\count($path) - 1])['value'];
    }

    /** Whether a scalar as written already means `$wanted`, quotes and YAML 1.1 booleans allowed. */
    public function holds(string $written, string $wanted): bool
    {
        if ('true' === $wanted) {
            return \in_array(strtolower($written), self::TRUE_SPELLINGS, true);
        }

        return \in_array($written, [$wanted, '"' . $wanted . '"', "'" . $wanted . "'"], true);
    }

    /**
     * The full key path of one required setting.
     *
     * @param array{handler: string, path: list<string>, value: string} $required
     *
     * @return list<string>
     */
    public static function pathOf(array $required): array
    {
        return [RequiredHandlersYamlEditor::HANDLERS_KEY, RequiredHandlersYamlEditor::EVENT_KEY, $required['handler'], ...$required['path']];
    }

    private function wholeFileBlocker(YamlLines $lines): ?string
    {
        $content = false;
        for ($index = 0; $index < $lines->count(); ++$index) {
            $line = $lines->line($index);
            if (1 === \Safe\preg_match('/^ *\t/', $line)) {
                return 'the file (tab indentation)';
            }

            if ($this->isDocumentMarker($line)) {
                if ($content || str_starts_with($line, '...')) {
                    return 'the file (more than one YAML document)';
                }

                continue;
            }

            $content = $content || $lines->isContent($index);
        }

        return null;
    }

    /**
     * Follows `$path` down the mapping blocks.
     *
     * @param array<int, string> $path
     *
     * @return int|string|null the index of the line holding the last key, the name of the part that
     *                         cannot be edited in place, or null when the file does not reach the key
     */
    private function walk(YamlLines $lines, array $path): int|string|null
    {
        $parent = -1;
        $to     = $lines->count();
        $trail  = '';
        $last   = \count($path) - 1;
        foreach ($path as $depth => $key) {
            $child = $lines->childIndent($parent, $to);
            if (null === $child) {
                return null;
            }

            if (-1 === $parent && 0 !== $child) {
                return self::TOP_LEVEL . ' (indented)';
            }

            $found = $this->keysIn($lines, $parent + 1, $to, $child, $trail, $key);
            if (\is_string($found)) {
                return $found;
            }

            if ([] === $found) {
                return null;
            }

            $label  = $this->join($trail, $key);
            $index  = $found[0];
            $inline = $lines->parts($index, $key)['value'];
            if ($depth === $last) {
                return '' !== $inline && str_contains(self::INDICATORS, $inline[0]) ? $label . ' (an anchor, alias, tag, block scalar or flow value)' : $index;
            }

            if (self::EMPTY_FLOW_MAPPING === $inline) {
                return null;
            }

            if ('' !== $inline) {
                return $label;
            }

            $parent = $index;
            $to     = $lines->end($index);
            $trail  = $label;
        }

        return null;
    }

    /**
     * Checks every key at `$child` in a block and returns the lines holding `$key`.
     *
     * @return list<int>|string the lines, or the name of the part that cannot be edited in place
     */
    private function keysIn(YamlLines $lines, int $from, int $to, int $child, string $trail, string $key): array|string
    {
        $found = [];
        for ($index = $from; $index < $to; ++$index) {
            if (!$lines->isContent($index)) {
                continue;
            }

            $indent = $lines->indent($index);
            if ($indent < $child) {
                return ('' === $trail ? self::TOP_LEVEL : $trail) . ' (inconsistent indentation)';
            }

            if ($indent > $child || $this->isDocumentMarker($lines->line($index))) {
                continue;
            }

            $name = $this->keyAt(ltrim($lines->line($index)), $trail);
            if (!$name[0]) {
                return $name[1];
            }

            if ($name[1] === $key) {
                $found[] = $index;
            }
        }

        if (\count($found) > 1) {
            return $this->join($trail, $key) . ' (duplicate key)';
        }

        return $found;
    }

    /**
     * @return array{bool, string} true and the key's name, or false and the name of what cannot be edited
     */
    private function keyAt(string $trimmed, string $trail): array
    {
        if ('-' === $trimmed || str_starts_with($trimmed, '- ')) {
            return [false, ('' === $trail ? self::TOP_LEVEL : $trail) . ' (a list)'];
        }

        if (str_starts_with($trimmed, '<<')) {
            return [false, $this->join($trail, '<<') . ' (merge key)'];
        }

        if (1 === \Safe\preg_match(self::PLAIN_KEY, $trimmed, $match) && isset($match[1])) {
            return [true, $match[1]];
        }

        $raw = strstr($trimmed, ':', true);

        return [false, $this->join($trail, false === $raw ? $trimmed : $raw) . ' (not a plain key)'];
    }

    private function isDocumentMarker(string $line): bool
    {
        return 1 === \Safe\preg_match(self::DOCUMENT_MARKER, $line);
    }

    private function join(string $trail, string $key): string
    {
        return '' === $trail ? $key : $trail . '.' . $key;
    }
}
