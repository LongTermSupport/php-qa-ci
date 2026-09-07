<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier as IdentifierNode;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Enforces that PHPStan rule identifiers are declared as class constants.
 *
 * Inside any class implementing PHPStan\Rules\Rule, calls to
 * RuleErrorBuilder::identifier() must pass a constant reference —
 * never a string literal.
 *
 * THE PROBLEM THIS SOLVES:
 * ========================
 * Magic-string identifiers are easy to typo, easy to copy-paste from
 * other projects with the wrong prefix (see counselbook.* bug that
 * slipped into php-qa-ci rules), and hard to grep for consistently.
 *
 * THE SOLUTION:
 * =============
 * Declare the identifier once as a constant, composed from a shared
 * prefix constant, and use the constant everywhere:
 *
 *     final class MyRule implements Rule
 *     {
 *         public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.myCheck';
 *
 *         public function processNode(Node $node, Scope $scope): array
 *         {
 *             return [
 *                 RuleErrorBuilder::message('...')
 *                     ->identifier(self::IDENTIFIER)
 *                     ->build(),
 *             ];
 *         }
 *     }
 *
 * This rule fires when ->identifier() is called with a string literal
 * from inside a PHPStan rule class, regardless of the identifier's value.
 *
 * @implements Rule<MethodCall>
 */
final readonly class RequireRuleIdentifierConstantRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.ruleIdentifierMustBeConstant';

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof IdentifierNode) {
            return [];
        }

        if ('identifier' !== $node->name->toString()) {
            return [];
        }

        if ([] === $node->args) {
            return [];
        }

        $firstArg = $node->args[0];
        if (!$firstArg instanceof Arg) {
            return [];
        }

        if (!$firstArg->value instanceof String_) {
            return [];
        }

        $classReflection = $scope->getClassReflection();
        if (!$classReflection instanceof \PHPStan\Reflection\ClassReflection) {
            return [];
        }

        if (!$classReflection->implementsInterface(Rule::class)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                \sprintf(
                    'PHPStan rule identifier "%s" is a magic string. '
                    . 'Declare it as a class constant composed from RuleIdentifierInterface::PREFIX '
                    . "(e.g. public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.something') "
                    . 'and pass the constant to ->identifier() instead.',
                    $firstArg->value->value,
                ),
            )->identifier(self::IDENTIFIER)->build(),
        ];
    }
}
