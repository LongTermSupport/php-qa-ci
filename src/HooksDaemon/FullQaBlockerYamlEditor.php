<?php

declare(strict_types=1);

namespace LTS\PHPQA\HooksDaemon;

use LTS\PHPQA\HooksDaemon\Dto\YamlEditResultDto;

/**
 * Adds or refreshes the `handlers.pre_tool_use.subagent_full_qa_blocker` block
 * in a hooks-daemon config as a TEXT edit, because the file is hand-maintained
 * and commented and a parse-and-dump would strip every comment in it.
 *
 * Only the handler's own lines are ever written. Everything else is kept
 * byte for byte, so a layout that cannot be edited that way (a non-empty
 * flow mapping, CRLF line endings) is reported rather than guessed at. A block
 * is php-qa-ci's to rewrite only while it carries the marker comment; a block
 * the project wrote itself, or switched off, is left as it is.
 *
 * @internal
 */
final readonly class FullQaBlockerYamlEditor
{
    /** The top-level key every handler event sits under. */
    private const string HANDLERS_KEY = 'handlers';

    /** The event the handler belongs to. */
    private const string EVENT_KEY = 'pre_tool_use';

    /** The one inline value that opens into a block without changing its meaning. */
    private const string EMPTY_FLOW_MAPPING = '{}';

    /** The nesting step of a file with no nested line to learn it from. */
    private const int DEFAULT_UNIT = 2;

    /** An `enabled:` field switched off, in any spelling YAML reads as false. */
    private const string DISABLED_FIELD = '/^enabled:\s*(?:false|no|off)\s*(?:#.*)?$/i';

    /** The same, inside a flow mapping on the handler's own line. */
    private const string DISABLED_INLINE = '/\benabled:\s*(?:false|no|off)\b/i';

    public function __construct(private FullQaBlockerBlockRenderer $renderer = new FullQaBlockerBlockRenderer())
    {
    }

    public function apply(string $yaml, string $binDir): YamlEditResultDto
    {
        if (str_contains($yaml, "\r")) {
            return new YamlEditResultDto(YamlEditOutcomeEnum::Unsupported, $yaml);
        }

        $lines    = YamlLines::fromText($yaml);
        $unit     = $lines->unit(self::DEFAULT_UNIT);
        $handlers = $lines->find(self::HANDLERS_KEY, 0, 0, $lines->count());
        if (null === $handlers) {
            return $this->appendSection($lines, $unit, $binDir);
        }

        $lines = $this->opened($lines, $handlers, self::HANDLERS_KEY);
        if (!$lines instanceof YamlLines) {
            return new YamlEditResultDto(YamlEditOutcomeEnum::Unsupported, $yaml);
        }

        $handlersEnd = $lines->end($handlers);
        $eventIndent = $lines->childIndent($handlers, $handlersEnd) ?? $unit;
        $event       = $lines->find(self::EVENT_KEY, $eventIndent, $handlers + 1, $handlersEnd);
        if (null === $event) {
            return $this->inserted(
                $lines,
                $handlers + 1,
                str_repeat(' ', $eventIndent) . self::EVENT_KEY . ':',
                ...$this->renderer->render($eventIndent + $unit, $unit, $binDir),
            );
        }

        $lines = $this->opened($lines, $event, self::EVENT_KEY);
        if (!$lines instanceof YamlLines) {
            return new YamlEditResultDto(YamlEditOutcomeEnum::Unsupported, $yaml);
        }

        $eventEnd      = $lines->end($event);
        $handlerIndent = $lines->childIndent($event, $eventEnd) ?? $eventIndent + $unit;
        $handler       = $lines->find(FullQaBlockerBlockRenderer::HANDLER_KEY, $handlerIndent, $event + 1, $eventEnd);
        if (null === $handler) {
            return $this->inserted(
                $lines,
                $lines->lastOwned($event, $eventEnd) + 1,
                ...$this->renderer->render($handlerIndent, $unit, $binDir),
            );
        }

        return $this->revised($yaml, $lines, $handler, $unit, $binDir);
    }

    private function revised(string $yaml, YamlLines $lines, int $handler, int $unit, string $binDir): YamlEditResultDto
    {
        $inline = $lines->keyed($handler, FullQaBlockerBlockRenderer::HANDLER_KEY);
        if (null !== $inline && '' !== $inline['value']) {
            return new YamlEditResultDto(
                1 === \Safe\preg_match(self::DISABLED_INLINE, $inline['value']) ? YamlEditOutcomeEnum::DisabledByProject : YamlEditOutcomeEnum::ProjectOwned,
                $yaml,
            );
        }

        $end   = $lines->end($handler);
        $block = $lines->between($handler, $lines->lastOwned($handler, $end));
        if ($this->isDisabled($lines->childIndent($handler, $end), ...$block)) {
            return new YamlEditResultDto(YamlEditOutcomeEnum::DisabledByProject, $yaml);
        }

        if (!$this->isManaged(...$block)) {
            return new YamlEditResultDto(YamlEditOutcomeEnum::ProjectOwned, $yaml);
        }

        $rendered = $this->renderer->render($lines->indent($handler), $unit, $binDir);
        if ($rendered === $block) {
            return new YamlEditResultDto(YamlEditOutcomeEnum::Unchanged, $yaml);
        }

        return new YamlEditResultDto(YamlEditOutcomeEnum::Updated, $lines->withReplaced($handler, \count($block), ...$rendered)->text());
    }

    /**
     * No top-level `handlers:` at all: the whole section goes at the end, and
     * the file ends with a newline afterwards.
     */
    private function appendSection(YamlLines $lines, int $unit, string $binDir): YamlEditResultDto
    {
        $section = [
            self::HANDLERS_KEY . ':',
            str_repeat(' ', $unit) . self::EVENT_KEY . ':',
            ...$this->renderer->render(2 * $unit, $unit, $binDir),
        ];
        $last = $lines->count() - 1;
        if ('' === $lines->line($last)) {
            return $this->inserted($lines, $last, ...$section);
        }

        return $this->inserted($lines, $last + 1, ...[...$section, '']);
    }

    /**
     * The lines with the key's value on its own line, ready to take children,
     * or null when its inline value cannot be opened up without changing what
     * it means. Only `{}` can; a trailing comment is kept.
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

    private function inserted(YamlLines $lines, int $at, string ...$insert): YamlEditResultDto
    {
        return new YamlEditResultDto(YamlEditOutcomeEnum::Inserted, $lines->withInserted($at, ...$insert)->text());
    }

    /** Whether the handler's own `enabled:` field (at `$fieldIndent`, not a nested one) is off. */
    private function isDisabled(?int $fieldIndent, string ...$block): bool
    {
        foreach ($block as $line) {
            $field = ltrim($line, ' ');
            if ($fieldIndent === \strlen($line) - \strlen($field) && 1 === \Safe\preg_match(self::DISABLED_FIELD, $field)) {
                return true;
            }
        }

        return false;
    }

    private function isManaged(string ...$block): bool
    {
        return array_any($block, static fn (string $line): bool => str_starts_with(ltrim($line), FullQaBlockerBlockRenderer::MARKER));
    }
}
