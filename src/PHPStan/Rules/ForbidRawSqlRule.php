<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans string concatenation in DBAL method arguments that accept SQL.
 *
 * String concatenation in executeQuery(), executeStatement(), or prepare() calls
 * enables SQL injection (OWASP A03 Injection). Use parameterised queries instead.
 *
 * See: docs/phpstan-rules/forbid-raw-sql.md for fix documentation.
 *
 * @implements Rule<MethodCall>
 */
final class ForbidRawSqlRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.rawSql';

    /**
     * @var list<string>
     */
    private const array BANNED_METHODS = [
        'executequery',
        'executestatement',
        'prepare',
    ];

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }

        $methodName = strtolower($node->name->toString());

        if (!\in_array($methodName, self::BANNED_METHODS, true)) {
            return [];
        }

        foreach ($node->args as $arg) {
            if ($arg instanceof Node\VariadicPlaceholder) {
                continue;
            }

            if ($this->containsConcat($arg->value)) {
                return [
                    RuleErrorBuilder::message(
                        \sprintf(
                            'String concatenation in %s() argument is banned (OWASP A03 SQL Injection). '
                            . 'Use parameterised queries with placeholders instead. '
                            . 'See docs/phpstan-rules/forbid-raw-sql.md for safe alternatives.',
                            $node->name->toString(),
                        ),
                    )->identifier(self::IDENTIFIER)->build(),
                ];
            }
        }

        return [];
    }

    private function containsConcat(Node $node): bool
    {
        if ($node instanceof Concat) {
            return true;
        }

        $finder = new NodeFinder();

        return $finder->findFirst($node, static fn (Node $n): bool => $n instanceof Concat) instanceof Node;
    }
}
