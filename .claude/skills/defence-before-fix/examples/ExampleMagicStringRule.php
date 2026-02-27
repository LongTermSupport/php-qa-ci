<?php

/**
 * EXAMPLE RULE - NOT FOR DIRECT USE
 * ==================================
 * This is a reference example for the phpstan-rule-creator agent.
 * Copy and adapt for your project's specific needs.
 *
 * Pattern: Magic string constants in attributes
 * Detects: String literals used where class constants are expected
 * Why: Magic strings bypass refactoring tools, can drift from the authoritative
 *      constant definition, and make it impossible to find all usages via
 *      static analysis. When the constant value changes, magic strings silently
 *      become stale.
 *
 * This is a simplified version of the RoutePathMustUseConstantsRule pattern.
 * The real-world rule in this project checks Route attribute paths specifically.
 */

declare(strict_types=1);

namespace QaConfig\PHPStan\Rules\Examples;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Detects magic string arguments in Symfony Route attributes where constants are expected.
 *
 * THE PROBLEM THIS SOLVES:
 * ========================
 * When Route attributes use magic strings instead of class constants:
 * 1. INCONSISTENCY: Strings can drift from constant definitions
 * 2. REFACTORING RISK: Changing a value requires finding all string occurrences
 * 3. SECURITY: Authentication prefixes can be accidentally wrong
 * 4. MAINTAINABILITY: Values should be defined once and referenced everywhere
 *
 * THE SOLUTION:
 * =============
 * Use class constants for all Route attribute arguments:
 *
 * WRONG:
 *   #[Route('/checkout/payment/setup', name: 'app_payment_setup')]
 *
 * RIGHT:
 *   #[Route(path: self::ROUTE_SETUP, name: self::ROUTE_SETUP_NAME)]
 *
 * @implements Rule<Attribute>
 */
final class ExampleMagicStringRule implements Rule
{
    /** @var list<string> */
    private const array ROUTE_ATTRIBUTE_CLASSES = [
        'Route',
        'Symfony\Component\Routing\Attribute\Route',
    ];

    /** @var list<string> argument names that should use constants */
    private const array ARGS_REQUIRING_CONSTANTS = [
        'name',
    ];

    public function getNodeType(): string
    {
        return Attribute::class;
    }

    /**
     * @param Attribute $node
     *
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $attributeName = $node->name->toString();

        // Check if this is a Route attribute
        $isRouteAttribute = false;
        foreach (self::ROUTE_ATTRIBUTE_CLASSES as $routeClass) {
            if ($attributeName === $routeClass || str_ends_with($attributeName, '\Route')) {
                $isRouteAttribute = true;

                break;
            }
        }

        if (!$isRouteAttribute) {
            return [];
        }

        $errors = [];

        foreach ($node->args as $arg) {
            if (null === $arg->name) {
                continue;
            }

            $argName = $arg->name->name;

            if (!\in_array($argName, self::ARGS_REQUIRING_CONSTANTS, true)) {
                continue;
            }

            if ($arg->value instanceof String_) {
                $errors[] = RuleErrorBuilder::message(
                    \sprintf(
                        'Route "%s" argument must use a class constant, not magic string "%s". ' .
                        'Define a constant and use self::CONSTANT_NAME instead.',
                        $argName,
                        $arg->value->value
                    )
                )->identifier('magicString.routeAttribute')->build();
            }
        }

        return $errors;
    }
}
