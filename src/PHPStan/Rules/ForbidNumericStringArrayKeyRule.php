<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * An array keyed by a string that may be all digits is not string-keyed, and
 * must not be taken as if it were.
 *
 * PHP stores a key that is a decimal integer string as an int: `$map['2024']`
 * has the key 2024. PHPStan nonetheless types `$map[$string] = …` as
 * `array<string, …>`, so a map keyed by a path, a file name or any other name
 * hands back an int wherever it is declared string-keyed, and under
 * strict_types that int throws a TypeError at the first string parameter it
 * reaches. The analysedPaths lane crashed that way on
 * `withUnanalysedPath('2024', …)`.
 *
 * NumericStringArrayKeyCollector records each write under such a key, each
 * place an array is taken as string-keyed, how arrays are handed from one
 * variable to another and at what offset of depth, and what PHPStan knew of
 * each variable where it was written. A write into a property declared
 * string-keyed is reported as it stands. A write into a local variable is
 * reported when the same body takes that variable as string-keyed at the depth
 * of the key, directly or through variables it was handed to (a copy, a
 * reference, an element, a foreach value), however many hand-overs away; the
 * first such place is named. A body is a function, or the innermost closure
 * around the record that does not import the variable; a variable named by
 * `global`, reached through `$GLOBALS` or written in code outside any function
 * is one body across every analysed file.
 *
 * Neither is reported when the key is the key variable of a foreach over an
 * array around the write that nothing in the loop writes: that variable holds
 * a key PHP made, and PHP leaves no all-digit string as a key. Keys an
 * iterator_to_array() takes from a variable are dropped when every write of
 * that variable is a filesystem iterator keyed by path name and nothing else
 * touches it. An inline `@var` on a variable the statement does not bind is
 * reported unless every write of the variable that may reach it was typed
 * string-keyed at the asserted depth. A use or report whose span the collector
 * marked unread (a foreach or array_keys() whose keys reach no code where an
 * int could misbehave) is dropped.
 *
 * See: docs/phpstan-rules/forbid-numeric-string-array-key.md.
 *
 * @phpstan-import-type NumericStringArrayKeyRecord from NumericStringArrayKeyCollector
 *
 * @phpstan-type FiledRecord array{kind: string, functionKey: string, variable: string, key: string, depths: list<int>, line: int, start: int, end: int, text: string, uses: list<string>, unless: string, file: string}
 * @phpstan-type JoinIndex array{
 *     closures: array<array-key, list<FiledRecord>>,
 *     globals: array<array-key, true>,
 *     keyBindings: list<FiledRecord>,
 *     unread: array<array-key, true>,
 *     uses: array<array-key, list<FiledRecord>>,
 *     copies: array<array-key, list<array{string, int}>>,
 *     writes: array<array-key, list<FiledRecord>>,
 *     bindings: array<array-key, list<FiledRecord>>,
 *     loops: array<array-key, list<FiledRecord>>,
 *     escapes: array<array-key, list<int>>
 * }
 *
 * @implements Rule<CollectedDataNode>
 */
final readonly class ForbidNumericStringArrayKeyRule implements Rule
{
    /** The identifier every finding carries, which rule-doc resolves to the page. */
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.numericStringArrayKey';

    /** A finding: what is keyed so, then where it is taken as string-keyed. */
    private const string MESSAGE = '%s is keyed by a string that may be all digits, which PHP stores as an int key, but %s. '
        . 'Declare its keys array-key and cast a key to string where one is read, or key it by a value that cannot be all digits. '
        . 'See docs/phpstan-rules/forbid-numeric-string-array-key.md.';

    /** How a body shared by every function naming the variable starts. */
    private const string GLOBAL_BODY = 'global$';

    /** The functionKey of code outside any class and function. */
    private const string TOP_LEVEL = '::';

    /** How far a chain of hand-overs may shift a depth before it is no longer followed. */
    private const int MAX_SHIFT = 8;

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $records = [];
        foreach ($node->get(NumericStringArrayKeyCollector::class) as $file => $collected) {
            foreach ($collected as $list) {
                foreach ($list as $record) {
                    $records[] = [...$record, 'file' => $file];
                }
            }
        }

        foreach ($node->get(NumericStringArrayKeyWritesCollector::class) as $file => $collected) {
            foreach ($collected as $record) {
                $records[] = [...$record, 'file' => $file];
            }
        }

        $index  = $this->index(...$records);
        $errors = [];
        foreach ($records as $record) {
            $judged = NumericStringArrayKeyCollector::WRITE === $record['kind'] || NumericStringArrayKeyCollector::DECLARED === $record['kind'];
            if ($judged && ($this->isBoundKey($record, $index) || isset($index['unread'][$this->span($record)]) || $this->isQuietIterator($record, $index))) {
                continue;
            }

            $reason = match ($record['kind']) {
                NumericStringArrayKeyCollector::DECLARED, NumericStringArrayKeyCollector::ASSERTED => $record['text'],
                NumericStringArrayKeyCollector::HELD                                               => $this->isRestated($record, $index) ? null : $record['text'],
                NumericStringArrayKeyCollector::WRITE                                              => $this->firstUse($record, $index),
                default                                                                            => null,
            };
            if (null === $reason) {
                continue;
            }

            $target   = NumericStringArrayKeyCollector::WRITE === $record['kind'] ? $record['text'] : $record['variable'];
            $errors[] = RuleErrorBuilder::message(\sprintf(self::MESSAGE, $target, $reason))
                ->identifier(self::IDENTIFIER)
                ->file($record['file'])
                ->line($record['line'])
                ->build()
            ;
        }

        return $errors;
    }

    /**
     * The records the join reads, sorted by what they are looked up by.
     *
     * @param FiledRecord ...$records
     *
     * @return JoinIndex
     */
    private function index(array ...$records): array
    {
        $index = ['closures' => [], 'globals' => [], 'keyBindings' => [], 'unread' => [], 'uses' => [], 'copies' => [], 'writes' => [], 'bindings' => [], 'loops' => [], 'escapes' => []];
        foreach ($records as $record) {
            match ($record['kind']) {
                NumericStringArrayKeyCollector::CLOSURE     => $index['closures'][$record['file']][]                                                        = $record,
                NumericStringArrayKeyCollector::KEY_BINDING => $index['keyBindings'][]                                                                      = $record,
                NumericStringArrayKeyCollector::UNREAD      => $index['unread'][$this->span($record)]                                                       = true,
                NumericStringArrayKeyCollector::WRITES      => $index['writes'][$record['file']][]                                                          = $record,
                NumericStringArrayKeyCollector::LOOP        => $index['loops'][$record['file']][]                                                           = $record,
                NumericStringArrayKeyCollector::BINDING     => $index['bindings'][$this->writeAt($record['file'], $record['variable'], $record['start'])][] = $record,
                NumericStringArrayKeyCollector::ESCAPE      => $index['escapes'][$record['file'] . '|' . $record['variable']][]                             = $record['start'],
                default                                     => null,
            };
        }

        foreach ($records as $record) {
            if (NumericStringArrayKeyCollector::GLOBAL === $record['kind']) {
                $index['globals'][$this->localBody($record['variable'], $record, $index)] = true;
            }
        }

        foreach ($records as $record) {
            if (NumericStringArrayKeyCollector::USE === $record['kind'] && !isset($index['unread'][$this->span($record)])) {
                $index['uses'][$this->body($record['variable'], $record, $index)][] = $record;
            } elseif (NumericStringArrayKeyCollector::COPY === $record['kind']) {
                $index['copies'][$this->body($record['variable'], $record, $index)][] = [$this->body($record['key'], $record, $index), $record['depths'][0] ?? 0];
            }
        }

        return $index;
    }

    /**
     * Where in which file a record's node is.
     *
     * @param FiledRecord $record
     */
    private function span(array $record): string
    {
        return "{$record['file']}:{$record['start']}:{$record['end']}";
    }

    /** A write of a variable at a position in a file, as BINDING records are looked up. */
    private function writeAt(string $file, string $variable, int $position): string
    {
        return "{$file}|{$variable}@{$position}";
    }

    /**
     * A variable as named where a record is, in the body holding it: one body
     * for a global variable, or else its function, or the innermost closure
     * around it that does not import it.
     *
     * @param FiledRecord $record
     * @param JoinIndex   $index
     */
    private function body(string $variable, array $record, array $index): string
    {
        if (str_starts_with($variable, NumericStringArrayKeyCollector::GLOBALS)) {
            return self::GLOBAL_BODY . substr($variable, \strlen(NumericStringArrayKeyCollector::GLOBALS));
        }

        $local = $this->localBody($variable, $record, $index);
        if (isset($index['globals'][$local]) || "{$record['file']}|" . self::TOP_LEVEL . "@-1\${$variable}" === $local) {
            return self::GLOBAL_BODY . $variable;
        }

        return $local;
    }

    /**
     * @param FiledRecord $record
     * @param JoinIndex   $index
     */
    private function localBody(string $variable, array $record, array $index): string
    {
        $start = -1;
        foreach ($index['closures'][$record['file']] ?? [] as $closure) {
            if ($record['start'] > $closure['start'] && $record['start'] <= $closure['end'] && $closure['start'] > $start
                                                     && !\in_array($variable, $closure['uses'], true)) {
                $start = $closure['start'];
            }
        }

        return "{$record['file']}|{$record['functionKey']}@{$start}\${$variable}";
    }

    /**
     * Whether the write's key is the key variable of a foreach around it in the same body.
     *
     * @param FiledRecord $record
     * @param JoinIndex   $index
     */
    private function isBoundKey(array $record, array $index): bool
    {
        if ('' === $record['key']) {
            return false;
        }

        $body = $this->body($record['key'], $record, $index);

        return array_any(
            $index['keyBindings'],
            fn (array $binding): bool => $record['key'] === $binding['variable'] && $record['file'] === $binding['file']
                                                                                 && $record['start'] > $binding['start'] && $record['start'] <= $binding['end']
                                                                                 && $this->body($binding['variable'], $binding, $index) === $body,
        );
    }

    /**
     * The first place the written variable, or a variable it was handed to,
     * is taken as string-keyed at the depth of the key, or null.
     *
     * @param FiledRecord $write
     * @param JoinIndex   $index
     */
    private function firstUse(array $write, array $index): ?string
    {
        $depth    = $write['depths'][0] ?? 0;
        $matching = [];
        foreach ($this->usesThroughCopies($this->body($write['variable'], $write, $index), $index) as [$use, $shift]) {
            if (array_any($use['depths'], static fn (int $used): bool => $used + $shift === $depth)) {
                $matching[] = $use;
            }
        }

        if ([] === $matching) {
            return null;
        }

        usort($matching, static fn (array $a, array $b): int => [$a['file'] !== $write['file'], $a['file'], $a['start']] <=> [$b['file'] !== $write['file'], $b['file'], $b['start']]);

        return \sprintf('line %d %s', $matching[0]['line'], $matching[0]['text']);
    }

    /**
     * The uses of a variable and of every variable handed it, however many
     * hand-overs away, each with the sum of the offsets of depth on the way:
     * a use at depth u of a variable reached at shift s is a use at depth
     * u + s of the first. Each variable is taken once per shift.
     *
     * @param JoinIndex $index
     *
     * @return list<array{FiledRecord, int}>
     */
    private function usesThroughCopies(string $body, array $index): array
    {
        $reached = [];
        $pending = [[$body, 0]];
        $found   = [];
        while ([] !== $pending) {
            [$current, $shift] = array_pop($pending);
            if (isset($reached["{$shift}#{$current}"])) {
                continue;
            }

            $reached["{$shift}#{$current}"] = true;
            foreach ($index['uses'][$current] ?? [] as $use) {
                $found[] = [$use, $shift];
            }

            foreach ($index['copies'][$current] ?? [] as [$copy, $offset]) {
                if (abs($shift + $offset) <= self::MAX_SHIFT) {
                    $pending[] = [$copy, $shift + $offset];
                }
            }
        }

        return $found;
    }

    /**
     * The innermost function-like body around a record, as its WRITES record.
     *
     * @param FiledRecord $record
     * @param JoinIndex   $index
     *
     * @return FiledRecord|null
     */
    private function enclosingWrites(array $record, array $index): ?array
    {
        $found = null;
        foreach ($index['writes'][$record['file']] ?? [] as $writes) {
            if ($writes['start'] <= $record['start'] && $record['start'] <= $writes['end'] && (null === $found || $writes['start'] > $found['start'])) {
                $found = $writes;
            }
        }

        return $found;
    }

    /**
     * The positions of each write of a variable in a body; null where a write
     * of it, or of any variable, cannot be followed.
     *
     * @param FiledRecord $writes
     *
     * @return list<int>|null
     */
    private function writePositions(array $writes, string $variable): ?array
    {
        if (NumericStringArrayKeyCollector::ANY === $writes['key']) {
            return null;
        }

        $positions = [];
        foreach ($writes['uses'] as $write) {
            if (str_starts_with($write, $variable . '@')) {
                $position = (int)substr($write, \strlen($variable) + 1);
                if (NumericStringArrayKeyCollector::UNTRACKED === $position) {
                    return null;
                }

                $positions[] = $position;
            }
        }

        return $positions;
    }

    /**
     * Whether the keys a record judges are those iterator_to_array() takes
     * from a variable that every write makes a filesystem iterator keyed by
     * path name, and that nothing else in the body touches.
     *
     * @param FiledRecord $record
     * @param JoinIndex   $index
     */
    private function isQuietIterator(array $record, array $index): bool
    {
        $variable = $record['unless'];
        if ('' === $variable) {
            return false;
        }

        $writes    = $this->enclosingWrites($record, $index);
        $positions = null === $writes ? null : $this->writePositions($writes, $variable);
        if (null === $writes || null === $positions || [] === $positions) {
            return false;
        }

        $escapes = $index['escapes'][$record['file'] . '|' . $variable] ?? [];
        if (array_any($escapes, static fn (int $start): bool => $writes['start'] <= $start && $start <= $writes['end'])) {
            return false;
        }

        return array_all(
            $positions,
            fn (int $position): bool => array_any(
                $index['bindings'][$this->writeAt($record['file'], $variable, $position)] ?? [],
                static fn (array $binding): bool => NumericStringArrayKeyCollector::PATH_NAMES === $binding['text'],
            ),
        );
    }

    /**
     * Whether an inline `@var` on a variable the statement does not bind only
     * restates what PHPStan knew: every write of the variable that may reach
     * the statement, before it or later in a loop around it, was typed
     * string-keyed at the asserted depth.
     *
     * @param FiledRecord $held
     * @param JoinIndex   $index
     */
    private function isRestated(array $held, array $index): bool
    {
        $writes    = $this->enclosingWrites($held, $index);
        $positions = null === $writes ? null : $this->writePositions($writes, $held['key']);
        if (null === $positions) {
            return false;
        }

        $loops    = $index['loops'][$held['file']] ?? [];
        $reaching = array_filter(
            $positions,
            static fn (int $position): bool => $position < $held['start'] || array_any(
                $loops,
                static fn (array $loop): bool => $loop['start'] < $held['start'] && $held['start'] <= $loop['end'] && $loop['start'] <= $position && $position <= $loop['end'],
            ),
        );

        $depth = $held['depths'][0] ?? 0;

        return [] !== $reaching && array_all(
            $reaching,
            fn (int $position): bool => array_any(
                $index['bindings'][$this->writeAt($held['file'], $held['key'], $position)] ?? [],
                static fn (array $binding): bool => \in_array($depth, $binding['depths'], true),
            ),
        );
    }
}
