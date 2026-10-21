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
 * place a local array is taken as string-keyed, each closure and each foreach
 * key binding. A write into a property declared string-keyed is reported as it
 * stands. A write into a local variable is reported when the same function,
 * outside any closure that does not import the variable, takes that variable
 * as string-keyed at the depth of the key, directly or through a variable
 * copied from it (`$copy = $array`); the first such place is named.
 * Neither is reported when the key is the key variable of a foreach over an
 * array around the write that nothing in the loop writes: that variable holds
 * a key PHP made, and PHP leaves no all-digit string as a key. Keys made
 * without an element write (a literal, array_flip(), an inline @var over data
 * PHPStan cannot see into) arrive as writes or as reports that stand. A use or
 * report whose span the collector marked unread (a foreach or array_keys()
 * whose keys reach no code where an int could misbehave) is dropped.
 *
 * See: docs/phpstan-rules/forbid-numeric-string-array-key.md.
 *
 * @phpstan-import-type NumericStringArrayKeyRecord from NumericStringArrayKeyCollector
 *
 * @implements Rule<CollectedDataNode>
 */
final readonly class ForbidNumericStringArrayKeyRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.numericStringArrayKey';

    private const string MESSAGE = '%s is keyed by a string that may be all digits, which PHP stores as an int key, but %s. '
        . 'Declare its keys array-key and cast a key to string where one is read, or key it by a value that cannot be all digits. '
        . 'See docs/phpstan-rules/forbid-numeric-string-array-key.md.';

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        foreach ($node->get(NumericStringArrayKeyCollector::class) as $file => $collected) {
            $records  = array_merge(...$collected);
            $closures = $this->ofKind(NumericStringArrayKeyCollector::CLOSURE, ...$records);
            $bindings = $this->ofKind(NumericStringArrayKeyCollector::KEY_BINDING, ...$records);
            $unread   = array_map($this->span(...), $this->ofKind(NumericStringArrayKeyCollector::UNREAD, ...$records));

            $uses = [];
            foreach ($this->ofKind(NumericStringArrayKeyCollector::USE, ...$records) as $use) {
                if (!\in_array($this->span($use), $unread, true)) {
                    $uses[$this->body($use['variable'], $use, ...$closures)][] = $use;
                }
            }

            $copies = [];
            foreach ($this->ofKind(NumericStringArrayKeyCollector::COPY, ...$records) as $copy) {
                $copies[$this->body($copy['variable'], $copy, ...$closures)][] = $this->body($copy['key'], $copy, ...$closures);
            }

            foreach ($records as $record) {
                if ($this->isBoundKey($record, $closures, ...$bindings) || \in_array($this->span($record), $unread, true)) {
                    continue;
                }

                $reason = match ($record['kind']) {
                    NumericStringArrayKeyCollector::DECLARED => $record['text'],
                    NumericStringArrayKeyCollector::WRITE    => $this->firstUse($record['depths'][0] ?? 0, ...$this->usesThroughCopies($this->body($record['variable'], $record, ...$closures), $uses, $copies)),
                    default                                  => null,
                };
                if (null === $reason) {
                    continue;
                }

                $target   = NumericStringArrayKeyCollector::DECLARED === $record['kind'] ? $record['variable'] : $record['text'];
                $errors[] = RuleErrorBuilder::message(\sprintf(self::MESSAGE, $target, $reason))
                    ->identifier(self::IDENTIFIER)
                    ->file($file)
                    ->line($record['line'])
                    ->build()
                ;
            }
        }

        return $errors;
    }

    /**
     * @param NumericStringArrayKeyRecord ...$records
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function ofKind(string $kind, array ...$records): array
    {
        return array_values(array_filter($records, static fn (array $record): bool => $kind === $record['kind']));
    }

    /**
     * Where in the file a record's node is.
     *
     * @param NumericStringArrayKeyRecord $record
     */
    private function span(array $record): string
    {
        return $record['start'] . ':' . $record['end'];
    }

    /**
     * A variable as named where a record is, in the body holding it: its
     * function, or the innermost closure around it that does not import it.
     *
     * @param NumericStringArrayKeyRecord $record
     * @param NumericStringArrayKeyRecord ...$closures
     */
    private function body(string $variable, array $record, array ...$closures): string
    {
        $start = -1;
        foreach ($closures as $closure) {
            if ($record['start'] > $closure['start'] && $record['start'] <= $closure['end'] && $closure['start'] > $start
                                                     && !\in_array($variable, $closure['uses'], true)) {
                $start = $closure['start'];
            }
        }

        return \sprintf('%s@%d$%s', $record['functionKey'], $start, $variable);
    }

    /**
     * Whether the write's key is the key variable of a foreach around it in the same body.
     *
     * @param NumericStringArrayKeyRecord       $record
     * @param list<NumericStringArrayKeyRecord> $closures
     * @param NumericStringArrayKeyRecord       ...$bindings
     */
    private function isBoundKey(array $record, array $closures, array ...$bindings): bool
    {
        if ('' === $record['key']) {
            return false;
        }

        $body = $this->body($record['key'], $record, ...$closures);

        return array_any(
            $bindings,
            fn (array $binding): bool => $record['key'] === $binding['variable']
                && $record['start'] > $binding['start'] && $record['start'] <= $binding['end']
                && $this->body($binding['variable'], $binding, ...$closures) === $body,
        );
    }

    /**
     * The uses of a variable and of every variable copied from it, however
     * many copies away, each variable taken once.
     *
     * @param array<array-key, list<NumericStringArrayKeyRecord>> $uses   body => its uses
     * @param array<array-key, list<string>>                      $copies body => the bodies copied from it
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function usesThroughCopies(string $body, array $uses, array $copies): array
    {
        $reached = [];
        $pending = [$body];
        $found   = [];
        while ([] !== $pending) {
            $current = array_pop($pending);
            if (isset($reached[$current])) {
                continue;
            }

            $reached[$current] = true;
            $found             = [...$found, ...$uses[$current] ?? []];
            $pending           = [...$pending, ...$copies[$current] ?? []];
        }

        return $found;
    }

    /**
     * The first place the variable is taken as string-keyed at the key's depth, or null.
     *
     * @param NumericStringArrayKeyRecord ...$uses
     */
    private function firstUse(int $depth, array ...$uses): ?string
    {
        $matching = array_values(array_filter($uses, static fn (array $use): bool => \in_array($depth, $use['depths'], true)));
        if ([] === $matching) {
            return null;
        }

        usort($matching, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return \sprintf('line %d %s', $matching[0]['line'], $matching[0]['text']);
    }
}
