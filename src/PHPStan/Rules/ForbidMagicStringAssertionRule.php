<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans test assertions that pin a "magic string" against a plain (non-constant)
 * string value — the fingerprint of code that should use a backed enum.
 *
 * WHY THIS IS NOT REDUNDANT WITH `alreadyNarrowedType`:
 *
 * PHPStan's strict-rules `alreadyNarrowedType` fires when an assertion is
 * *provably always true* — e.g. `assertSame('desk', Product::Desk->value)` once
 * `Product` is an enum. But that only fires *after* you have already made the
 * value type-safe. It is a lagging proof, not a warning: it is silent precisely
 * while the value is still a loosely-typed `string`, which is the moment you
 * actually need to be told "this should be an enum".
 *
 * This rule fills that gap. It fires on the *smell* — asserting a closed-set
 * looking literal (`'desk'`, `'active'`, `'GET'`) against a value PHPStan types
 * as a general `string` — i.e. before the enum exists. The fix is to model the
 * value as a backed enum; once you do, this rule goes quiet and (if you keep the
 * now-tautological assertion) `alreadyNarrowedType` tells you to delete it. The
 * two rules are complementary halves of "type safety before tests": this one
 * catches the cause, the built-in confirms the cure.
 *
 * Deliberately scoped to *identifier-like* literals (a single token, no
 * whitespace/punctuation) so legitimate string-output assertions (rendered HTML,
 * messages, formatted text) are never flagged. Opinionated — opt-in via
 * rules-optional.neon.
 *
 * Two fixes, depending on what the string is:
 *
 * WRONG — a domain value (closed set) pinned with a literal:
 *   self::assertSame('desk', $portal->getProductType());   // getProductType(): string
 * RIGHT — model the closed set; the type guarantees it:
 *   self::assertSame(ZohoProduct::Desk, $portal->getProductType());  // : ZohoProduct
 *
 * WRONG — test data (a fixture id/key) duplicated as a literal:
 *   $field = new FieldData('cf_bl_order_id', ...);
 *   self::assertSame('cf_bl_order_id', $field->apiKey);
 * RIGHT — define it once as a test-level const and reference it both sides:
 *   private const string ORDER_ID_KEY = 'cf_bl_order_id';
 *   $field = new FieldData(self::ORDER_ID_KEY, ...);
 *   self::assertSame(self::ORDER_ID_KEY, $field->apiKey);
 *
 * @implements Rule<CallLike>
 */
final class ForbidMagicStringAssertionRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.magicStringAssertion';

    /**
     * Equality assertions whose expected value is commonly a hardcoded literal.
     *
     * @var list<string>
     */
    private const array ASSERTION_METHODS = ['assertSame', 'assertEquals', 'assertNotSame', 'assertNotEquals'];

    private const int MAX_IDENTIFIER_LENGTH = 40;

    /**
     * Metadata accessors whose string return is a structural contract, not a
     * domain value (Reflection / PSR-7). See {@see isStructuralMetadataOrEnumDerived}.
     *
     * @var list<string>
     */
    private const array METADATA_ACCESSORS = ['getName', 'getMethod', 'getShortName'];

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof MethodCall && !$node instanceof StaticCall) {
            return [];
        }

        $methodName = $node->name;
        if (!$methodName instanceof Node\Identifier) {
            return [];
        }

        if (!\in_array($methodName->toString(), self::ASSERTION_METHODS, true)) {
            return [];
        }

        $args = $node->getArgs();
        if (\count($args) < 2) {
            return [];
        }

        $first  = $args[0]->value;
        $second = $args[1]->value;

        // Identify the single string-literal operand and the "other" operand.
        // Both-literals (literal == literal) is itself always-narrowed — leave
        // that to the alreadyNarrowedType built-in.
        if ($first instanceof String_ && !$second instanceof String_) {
            $literalValue = $first->value;
            $otherExpr    = $second;
        } elseif ($second instanceof String_ && !$first instanceof String_) {
            $literalValue = $second->value;
            $otherExpr    = $first;
        } else {
            return [];
        }

        if (!self::isIdentifierLike($literalValue)) {
            return [];
        }

        // Over-fire allowlist: skip operands that are structural-metadata
        // accessors (Reflection `getName()`, PSR-7 `getMethod()`, …). These return
        // contract strings, not domain values, so they are not a
        // "should-be-an-enum" smell.
        if ($this->isMetadataAccessor($otherExpr)) {
            return [];
        }

        // Non-redundant gate: only when the other operand is a GENERAL string.
        // A constant-string actual is exactly what alreadyNarrowedType reports —
        // we must not duplicate the built-in. A general string is the
        // not-yet-enum'd magic-string zone where the built-in is silent.
        $otherType = $scope->getType($otherExpr);
        if (!$otherType->isString()->yes()) {
            return [];
        }

        if ([] !== $otherType->getConstantStrings()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf(
                'Asserting the magic string "%s" against a plain string value — a test is bridging certainty that a '
                . 'type or a single definition should provide. Fix it one of two ways: '
                . '(1) DOMAIN value (a closed set like a status, type, or action): model it as a backed enum (or a '
                . 'domain constant) in production and assert the enum case / constant — the type system then '
                . 'guarantees it and the assertion becomes unnecessary; '
                . '(2) TEST DATA (a fixture id, key, or sample value): hoist "%s" to a test-level private const and '
                . 'reference it from both the arrange step and the assertion, so the value is defined once instead of '
                . 'duplicated. Either way, do not pin a raw magic string in the assertion.',
                $literalValue,
                $literalValue,
            ))->identifier(self::IDENTIFIER)->build(),
        ];
    }

    /**
     * Whether the operand is a structural-metadata accessor whose string return
     * is a contract, not a domain value — `ReflectionParameter::getName()`,
     * `ReflectionNamedType::getName()`, `RequestInterface::getMethod()`, etc.
     *
     * Matched by method name only: these accessors are reliably identified that
     * way, it needs no type reflection (which is unavailable in PHPStan rule unit
     * tests), and a domain method that happens to share the name returning a
     * closed-set string is vanishingly rare for an opt-in rule.
     */
    private function isMetadataAccessor(Node\Expr $expr): bool
    {
        return $expr instanceof MethodCall
            && $expr->name instanceof Node\Identifier
            && \in_array($expr->name->toString(), self::METADATA_ACCESSORS, true);
    }

    /**
     * A single identifier-like token (no whitespace/punctuation) — the shape of
     * a closed-set value (slug, code, key), as opposed to free text.
     */
    public static function isIdentifierLike(string $value): bool
    {
        if ('' === $value || \strlen($value) > self::MAX_IDENTIFIER_LENGTH) {
            return false;
        }

        return 1 === preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $value);
    }
}
