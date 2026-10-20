<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\Constant\ConstantStringType;

/**
 * Collects each str_contains(), strpos() or stripos() call looking for a bare namespace-segment
 * word (`Generated`, `PHPStan`: a capital, then letters and digits) anywhere in a subject that is
 * a class or namespace name, or a local variable that may hold one, for
 * ForbidUnanchoredNamespaceSubstringCheckRule.
 *
 * The word is the needle's value when PHPStan knows it: a literal, a constant, or each value of a
 * loop over a constant list. `Tests` is left to ForbidUnanchoredTestsSubstringCheckRule, which
 * reports it with any subject.
 *
 * Each value is [function, the words, sorted, line, function key, the subject variable to follow
 * through ClassNameVariableWriteCollector, or null when the subject is itself a class name].
 *
 * @internal
 *
 * @implements Collector<FuncCall, array{string, non-empty-list<string>, int, string, string|null}>
 */
final readonly class NamespaceWordSubstringCallCollector implements Collector
{
    /** @var list<string> the functions that match anywhere in the subject, where a bare word overmatches */
    private const array SUBSTRING_FUNCTIONS = ['str_contains', 'strpos', 'stripos'];

    /** @var string a namespace segment's shape: a capital, then letters and digits, with no separator */
    private const string SEGMENT_WORD_PATTERN = '~^[A-Z][A-Za-z0-9]*$~';

    /** @var string the word ForbidUnanchoredTestsSubstringCheckRule reports */
    private const string TESTS_WORD = 'Tests';

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @return array{string, non-empty-list<string>, int, string, string|null}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (!$node->name instanceof Name) {
            return null;
        }

        $function = strtolower(ltrim($node->name->toString(), '\\'));
        $subject  = $node->args[0] ?? null;
        $needle   = $node->args[1] ?? null;
        if (!\in_array($function, self::SUBSTRING_FUNCTIONS, true) || !$subject instanceof Arg || !$needle instanceof Arg) {
            return null;
        }

        $words = array_values(array_filter(
            array_map(static fn (ConstantStringType $string): string => $string->getValue(), $scope->getType($needle->value)->getConstantStrings()),
            static fn (string $word): bool => self::TESTS_WORD !== $word && 1 === \Safe\preg_match(self::SEGMENT_WORD_PATTERN, $word),
        ));
        if ([] === $words) {
            return null;
        }

        sort($words);
        $key = ClassNameVariableWriteCollector::functionKey($scope);
        if (ClassNameVariableWriteCollector::isClassName($subject->value, $scope)) {
            return [$function, $words, $node->getStartLine(), $key, null];
        }

        return $subject->value instanceof Variable && \is_string($subject->value->name)
            ? [$function, $words, $node->getStartLine(), $key, $subject->value->name]
            : null;
    }
}
