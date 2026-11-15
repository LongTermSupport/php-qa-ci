<?php

declare(strict_types=1);

namespace LTS\PHPQA\VersionPins;

/**
 * The events a GitHub Actions workflow runs on, read from its `on` key in whichever shape it is
 * written: a scalar, a flow list or mapping on one line or several, a block list at any indentation
 * (including none), a block mapping at any consistent indentation, any of them with quoted names, and
 * the key itself quoted.
 *
 * Only the first level under `on` names events: a branch called `pull_request` under `push` is not
 * one. A trigger the reader cannot reduce to names (an alias, an anchor, a tag, a merge key, no `on`
 * key, nothing under it) is not guessed at: the result is null, and the caller reports it.
 *
 * Takes text that {@see WorkflowYamlText::withoutComments()} has already cleaned.
 *
 * @internal
 */
final readonly class WorkflowTriggerReader
{
    /** The `on` key at the start of a line, plain or quoted, with what follows its colon. */
    private const string ON_KEY = '/^(?:on|"on"|\'on\'):(.*)$/';

    /** What a name cannot start with: an alias, an anchor, a tag, a block scalar, a complex key, a nested collection. */
    private const string NOT_A_NAME = '*&!|>?[{';

    /** The YAML merge key: it pulls in names from elsewhere that this reader cannot see. */
    private const string MERGE_KEY = '<<';

    public function __construct(private WorkflowYamlText $text = new WorkflowYamlText())
    {
    }

    /**
     * @return list<string>|null the event names, in order; null when the trigger cannot be read
     */
    public function events(string $workflow): ?array
    {
        $lines = explode("\n", $workflow);
        foreach ($lines as $index => $line) {
            if (1 === \Safe\preg_match(self::ON_KEY, $line, $match) && isset($match[1])) {
                return $this->eventsAfterKey(trim($match[1]), ...\array_slice($lines, $index + 1));
            }
        }

        return null;
    }

    /**
     * @param string $rest         what follows the colon of the `on` key
     * @param string ...$following the lines after the `on:` line
     *
     * @return list<string>|null
     */
    private function eventsAfterKey(string $rest, string ...$following): ?array
    {
        if ('' === $rest) {
            return $this->blockEvents(...$following);
        }

        if ('[' === $rest[0] || '{' === $rest[0]) {
            return $this->flowEvents($rest . "\n" . implode("\n", $following));
        }

        if (str_contains(self::NOT_A_NAME, $rest[0]) || 1 === \Safe\preg_match('/\s/', $this->text->unquote($rest))) {
            return null;
        }

        return [$this->text->unquote($rest)];
    }

    /** @return list<string>|null */
    private function flowEvents(string $fromTheBracket): ?array
    {
        $balanced = $this->text->balanced($fromTheBracket);
        if (null === $balanced) {
            return null;
        }

        $names = [];
        foreach ($this->text->flowItems($balanced) as $item) {
            $name = '[' === $balanced[0] ? $item : $this->keyOf($item);
            if (null === $name || !$this->isAName($this->text->unquote($name))) {
                return null;
            }

            $names[] = $this->text->unquote($name);
        }

        return $names;
    }

    /**
     * @param string ...$lines the lines after the `on:` line
     *
     * @return list<string>|null
     */
    private function blockEvents(string ...$lines): ?array
    {
        $body = [];
        foreach ($lines as $line) {
            if ('' === trim($line)) {
                continue;
            }

            if (0 === $this->text->indentOf($line) && !str_starts_with($line, '-')) {
                break;
            }

            $body[] = $line;
        }

        if ([] === $body) {
            return null;
        }

        $base     = $this->text->indentOf($body[0]);
        $isList   = str_starts_with(ltrim($body[0]), '-');
        $names    = [];
        foreach ($body as $line) {
            $indent = $this->text->indentOf($line);
            if ($indent < $base) {
                return null;
            }

            if ($indent > $base) {
                continue;
            }

            $name = $isList ? $this->listItem(ltrim($line)) : $this->keyOf(ltrim($line));
            if (null === $name || !$this->isAName($this->text->unquote($name))) {
                return null;
            }

            $names[] = $this->text->unquote($name);
        }

        return $names;
    }

    /** What follows the dash of a block list item, or null when the line is not one. */
    private function listItem(string $line): ?string
    {
        if (!str_starts_with($line, '-')) {
            return null;
        }

        return trim(substr($line, 1));
    }

    /** The key of a `key: value` entry, quoted or not, or null when the text has no key. */
    private function keyOf(string $entry): ?string
    {
        $length = \strlen($entry);
        $quote  = null;
        for ($i = 0; $i < $length; ++$i) {
            $char = $entry[$i];
            if (null !== $quote) {
                $quote = $char === $quote ? null : $quote;

                continue;
            }

            if ('"' === $char || "'" === $char) {
                $quote = $char;

                continue;
            }

            if (':' === $char && ($i + 1 === $length || ' ' === $entry[$i + 1])) {
                return trim(substr($entry, 0, $i));
            }
        }

        return null;
    }

    private function isAName(string $name): bool
    {
        return '' !== $name && self::MERGE_KEY !== $name && !str_contains(self::NOT_A_NAME, $name[0]);
    }
}
