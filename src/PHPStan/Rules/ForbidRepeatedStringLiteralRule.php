<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The same string literal written three or more times in one class is a
 * constant that never got declared: the value has no single definition, an
 * edit to one occurrence leaves stale siblings, and the reader cannot tell
 * what it means or whether two occurrences are meant to be equal.
 *
 * Not counted (each is a value that does not carry that hazard): array keys
 * and array-dimension indexes (the spelling of an array shape), attribute
 * arguments (declarative metadata), literals shorter than three characters or
 * made only of whitespace, and literals inside a nested class-like (counted by
 * its own class).
 *
 * See: docs/phpstan-rules/forbid-repeated-string-literal.md
 *
 * @implements Rule<InClassNode>
 */
final readonly class ForbidRepeatedStringLiteralRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.repeatedStringLiteral';

    public const int MIN_OCCURRENCES = 3;

    public const int MIN_LENGTH = 3;

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $collector = new RepeatedStringLiteralCollector($node->getOriginalNode());
        $traverser = new NodeTraverser($collector);
        $traverser->traverse($node->getOriginalNode()->stmts);

        $errors = [];
        foreach ($collector->occurrences() as $value => $lines) {
            if (\count($lines) < self::MIN_OCCURRENCES) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(\sprintf(
                'String literal %s appears %d times in this class (lines %s); declare it once as a class constant.',
                var_export($value, true),
                \count($lines),
                implode(', ', $lines),
            ))->line($lines[0])->identifier(self::IDENTIFIER)->build();
        }

        return $errors;
    }
}
