<?php

declare(strict_types=1);

namespace LTS\PHPQA\HooksDaemon;

use LTS\PHPQA\HooksDaemon\Dto\RequiredHandlersEditDto;

/**
 * Enforces the hooks-daemon handler settings php-qa-ci needs in a project's
 * `handlers.pre_tool_use` as a TEXT edit, because the config is hand-maintained
 * and commented and a parse-and-dump would drop every comment and reformat the
 * file.
 *
 * A setting that is already right is never written, so a compliant file comes
 * back byte for byte. A setting that is missing is added at the file's own
 * indent; one with a different value has that one line replaced, its trailing
 * comment kept. A layout that cannot be edited that way (a populated flow
 * mapping, a block where a scalar belongs, CRLF line endings) is reported with
 * the setting that blocked it and the file is left as it was.
 *
 * @internal
 */
final readonly class RequiredHandlersYamlEditor
{
    /** Every setting is enforced under `handlers.pre_tool_use`. */
    public const string HANDLERS_KEY = 'handlers';

    /** The event the handlers belong to. */
    public const string EVENT_KEY = 'pre_tool_use';

    /**
     * Handler name => the settings it must carry, each a key path and a value.
     * A flat list of rows keeps the order the changes are reported in.
     *
     * @var list<array{handler: string, path: list<string>, value: string}>
     */
    public const array REQUIRED = [
        ['handler' => 'git_stash', 'path' => [self::ENABLED], 'value' => self::TRUE],
        ['handler' => 'git_stash', 'path' => ['options', 'mode'], 'value' => 'deny'],
        ['handler' => 'plan_time_estimates', 'path' => [self::ENABLED], 'value' => self::TRUE],
        ['handler' => 'validate_instruction_content', 'path' => [self::ENABLED], 'value' => self::TRUE],
        ['handler' => 'markdown_organization', 'path' => [self::ENABLED], 'value' => self::TRUE],
    ];

    /** The nesting step of a file with no nested line to learn it from. */
    private const int DEFAULT_UNIT = 2;

    /** The one inline value that opens into a block without changing its meaning. */
    private const string EMPTY_FLOW_MAPPING = '{}';

    /** The switch every handler carries. */
    private const string ENABLED = 'enabled';

    /** The canonical spelling of a boolean true. */
    private const string TRUE = 'true';

    /** The spellings YAML 1.1 reads as true, which PyYAML and the daemon accept. */
    private const array TRUE_SPELLINGS = ['true', 'yes', 'on'];

    public function apply(string $yaml): RequiredHandlersEditDto
    {
        if (str_contains($yaml, "\r")) {
            return $this->refused($yaml, 'the file (CRLF line endings)');
        }

        $lines    = YamlLines::fromText($yaml);
        $unit     = $lines->unit(self::DEFAULT_UNIT);
        $handlers = $lines->find(self::HANDLERS_KEY, 0, 0, $lines->count());
        if (null === $handlers) {
            [$lines, $handlers] = $this->withHandlersKey($lines);
        }

        $opened = $this->opened($lines, $handlers, self::HANDLERS_KEY);
        if (!$opened instanceof YamlLines) {
            return $this->refused($yaml, self::HANDLERS_KEY);
        }

        $lines   = $opened;
        $changes = [];
        foreach (self::REQUIRED as $required) {
            $edited = $this->ensure(
                $lines,
                $handlers,
                $required['value'],
                $unit,
                self::HANDLERS_KEY,
                $required['handler'] . '.' . implode('.', $required['path']),
                $changes,
                self::EVENT_KEY,
                $required['handler'],
                ...$required['path'],
            );
            if (\is_string($edited)) {
                return $this->refused($yaml, $edited);
            }

            $lines = $edited;
        }

        return new RequiredHandlersEditDto([] === $changes ? $yaml : $lines->text(), $changes, null);
    }

    private function refused(string $yaml, string $where): RequiredHandlersEditDto
    {
        return new RequiredHandlersEditDto($yaml, [], $where);
    }

    /**
     * A bare `handlers:` line at the end of the file, which ends with a
     * newline afterwards.
     *
     * @return array{YamlLines, int} the lines and the index of the new key
     */
    private function withHandlersKey(YamlLines $lines): array
    {
        $last = $lines->count() - 1;
        if ('' === $lines->line($last)) {
            return [$lines->withInserted($last, self::HANDLERS_KEY . ':'), $last];
        }

        return [$lines->withInserted($last + 1, self::HANDLERS_KEY . ':', ''), $last + 1];
    }

    /**
     * Makes the setting at `$path` (below the key at `$parent`) hold `$value`.
     *
     * @param string       $trail   the dotted name of the key at `$parent`
     * @param string       $setting the setting being enforced, as a person names it
     * @param list<string> $changes appended to, one line per edit
     * @param string       ...$path the keys below the parent, the last one the setting itself
     *
     * @return string|YamlLines the edited lines, or the dotted name of the key that blocked an in-place edit
     */
    private function ensure(YamlLines $lines, int $parent, string $value, int $unit, string $trail, string $setting, array &$changes, string ...$path): string|YamlLines
    {
        $key   = $path[0];
        $rest  = \array_slice($path, 1);
        $end   = $lines->end($parent);
        $child = $lines->childIndent($parent, $end) ?? $lines->indent($parent) + $unit;
        $index = $lines->find($key, $child, $parent + 1, $end);
        $label = $trail . '.' . $key;

        if (null === $index) {
            $changes[] = 'Added ' . $setting . ': ' . $value;

            return $lines->withInserted($lines->lastOwned($parent, $end) + 1, ...$this->nested($value, $child, $unit, ...$path));
        }

        if ([] === $rest) {
            return $this->setLeaf($lines, $index, $key, $value, $label, $setting, $changes);
        }

        $opened = $this->opened($lines, $index, $key);
        if (!$opened instanceof YamlLines) {
            return $label;
        }

        return $this->ensure($opened, $index, $value, $unit, $label, $setting, $changes, ...$rest);
    }

    /**
     * @param list<string> $changes
     */
    private function setLeaf(YamlLines $lines, int $index, string $key, string $value, string $label, string $setting, array &$changes): string|YamlLines
    {
        $parsed = $lines->keyed($index, $key);
        if (null === $parsed || '' === $parsed['value']) {
            return $label;
        }

        if ($this->matches($parsed['value'], $value)) {
            return $lines;
        }

        $changes[] = 'Updated ' . $setting . ': ' . $parsed['value'] . ' → ' . $value;

        return $lines->withReplaced($index, 1, str_repeat(' ', $lines->indent($index)) . $key . ': ' . $value . $this->trailingComment($lines->line($index), $parsed['comment']));
    }

    /** The comment at the end of a line with the spacing that precedes it, as written. */
    private function trailingComment(string $line, string $comment): string
    {
        if ('' === $comment) {
            return '';
        }

        $at = strrpos($line, $comment);
        if (false === $at) {
            return ' ' . $comment;
        }

        return substr($line, \strlen(rtrim(substr($line, 0, $at), ' ')));
    }

    /**
     * The lines of a key chain, each level `$unit` deeper than the last, ending
     * in the setting.
     *
     * @return list<string>
     */
    private function nested(string $value, int $indent, int $unit, string ...$path): array
    {
        $out   = [];
        $last  = \count($path) - 1;
        $depth = 0;
        foreach ($path as $key) {
            $out[] = str_repeat(' ', $indent + $depth * $unit) . $key . ($depth === $last ? ': ' . $value : ':');
            ++$depth;
        }

        return $out;
    }

    /**
     * The lines with the key's value on its own line, ready to take children,
     * or null when its inline value cannot be opened up without changing what
     * it means. Only an empty flow mapping can; a trailing comment is kept.
     */
    private function opened(YamlLines $lines, int $index, string $key): ?YamlLines
    {
        $parsed = $lines->keyed($index, $key);
        if (null === $parsed || '' === $parsed['value']) {
            return $lines;
        }

        if (self::EMPTY_FLOW_MAPPING !== $parsed['value']) {
            return null;
        }

        $comment = '' === $parsed['comment'] ? '' : ' ' . $parsed['comment'];

        return $lines->withReplaced($index, 1, str_repeat(' ', $lines->indent($index)) . $key . ':' . $comment);
    }

    /** Whether a scalar as written already means `$wanted`, quotes and YAML 1.1 booleans allowed. */
    private function matches(string $written, string $wanted): bool
    {
        if (self::TRUE === $wanted) {
            return \in_array(strtolower($written), self::TRUE_SPELLINGS, true);
        }

        return \in_array($written, [$wanted, '"' . $wanted . '"', "'" . $wanted . "'"], true);
    }
}
