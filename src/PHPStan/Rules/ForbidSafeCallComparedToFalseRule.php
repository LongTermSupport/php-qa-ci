<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\BinaryOp\LogicalXor;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\ErrorSuppress;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\While_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;

/**
 * Forbids testing a Safe\ function's result for the false it never returns.
 *
 * Rector's Safe set rewrites a native function that returns false on failure,
 * such as realpath(), into its Safe\ form, which throws a Safe\Exceptions
 * exception instead. A `false === ...` check written for the native function
 * is then dead, and the code throws on the very case the check exists for.
 *
 * Reported:
 * - the call compared with false by === or !==, in either operand order,
 *   where the Safe function's declared return type cannot be false;
 * - the call used as a condition (if, elseif, while, do-while, for, a ternary
 *   or short ternary, !, &&, ||, and, or, xor), or compared with false by ==
 *   or !=, which test the same truthiness, where its declared return type is
 *   always truthy or a non-empty string, so the condition can only have been
 *   asking whether it failed.
 *
 * The call is seen through @ and an assignment of it, in any combination. The
 * type judged is the one the function declares, never the call's type in the
 * scope, which PHPStan narrows after an earlier check of the same call.
 *
 * A Safe function whose result can itself be falsy (preg_match's 0, an empty
 * string) is not reported as a condition: the condition still tests a value
 * it returns.
 *
 * See: docs/phpstan-rules/forbid-safe-call-compared-to-false.md
 *
 * @implements Rule<Node>
 */
final readonly class ForbidSafeCallComparedToFalseRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.safeCallComparedToFalse';

    private const string SAFE_NAMESPACE = 'safe';

    private const string ADVICE = ' Catch the Safe\Exceptions exception, or use an API that reports failure without '
        . 'throwing. See docs/phpstan-rules/forbid-safe-call-compared-to-false.md';

    public function __construct(private ReflectionProvider $reflectionProvider)
    {
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof Identical || $node instanceof NotIdentical) {
            return $this->strictComparison($node, $scope);
        }

        if ($node instanceof Equal || $node instanceof NotEqual) {
            $operand = $this->operandComparedWithFalse($node);

            return $operand instanceof Expr ? $this->conditions($scope, $operand) : [];
        }

        return $this->conditions($scope, ...$this->conditionsOf($node));
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function strictComparison(BinaryOp $node, Scope $scope): array
    {
        $operand = $this->operandComparedWithFalse($node);
        if (!$operand instanceof Expr) {
            return [];
        }

        $call     = $this->unwrapCall($operand);
        $function = $call instanceof FuncCall ? $this->safeFunction($call, $scope) : null;
        if (!$call instanceof FuncCall || !$function instanceof FunctionReflection) {
            return [];
        }

        if (!$this->declaredReturnType($function, $call, $scope)->isFalse()->no()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                $function->getName() . '() never returns false: it throws a Safe\Exceptions exception on failure, so this '
                . 'comparison with false can never hold and the failure it was written for throws instead.'
                . self::ADVICE,
            )->identifier(self::IDENTIFIER)->build(),
        ];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function conditions(Scope $scope, Expr ...$conditions): array
    {
        $errors = [];
        foreach ($conditions as $condition) {
            $call     = $this->unwrapCall($condition);
            $function = $call instanceof FuncCall ? $this->safeFunction($call, $scope) : null;
            if (!$call instanceof FuncCall || !$function instanceof FunctionReflection) {
                continue;
            }

            if (!$this->isTruthyUnlessFailed($this->declaredReturnType($function, $call, $scope))) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(
                $function->getName() . '() never returns false: it throws a Safe\Exceptions exception on failure, so this '
                . 'condition holds whenever the call returns and never sees the failure it was written for.'
                . self::ADVICE,
            )->identifier(self::IDENTIFIER)->line($condition->getStartLine())->build();
        }

        return $errors;
    }

    /**
     * The expressions a node tests for truthiness.
     *
     * @return list<Expr>
     */
    private function conditionsOf(Node $node): array
    {
        return match (true) {
            $node instanceof If_, $node instanceof ElseIf_, $node instanceof While_, $node instanceof Do_ => [$node->cond],
            $node instanceof For_                                                                         => array_values($node->cond),
            $node instanceof Ternary                                                                      => [$node->cond],
            $node instanceof BooleanNot                                                                   => [$node->expr],
            $node instanceof BooleanAnd, $node instanceof BooleanOr, $node instanceof LogicalAnd,
            $node instanceof LogicalOr, $node instanceof LogicalXor                                       => [$node->left, $node->right],
            default                                                                                       => [],
        };
    }

    /**
     * Whether a truthiness test of a result of this type can only have been
     * asking whether the call failed: the type is always truthy, or it is a
     * non-empty string, falsy only as "0", which no Safe function returns to
     * signal anything (Safe\realpath() is typed non-empty-string).
     */
    private function isTruthyUnlessFailed(Type $type): bool
    {
        if ($type->toBoolean()->isTrue()->yes()) {
            return true;
        }

        return $type->isString()->yes() && $type->isNonEmptyString()->yes();
    }

    /**
     * The operand of a comparison whose other side is the constant false.
     */
    private function operandComparedWithFalse(BinaryOp $node): ?Expr
    {
        if ($this->isFalse($node->right)) {
            return $node->left;
        }

        if ($this->isFalse($node->left)) {
            return $node->right;
        }

        return null;
    }

    private function isFalse(Expr $expr): bool
    {
        return $expr instanceof ConstFetch && 'false' === $expr->name->toLowerString();
    }

    /**
     * The function call the expression makes, looking through an assignment of
     * the call and the @ operator in any combination. Parentheses leave no
     * node, so they need no unwrapping.
     */
    private function unwrapCall(Expr $expr): ?FuncCall
    {
        while ($expr instanceof Assign || $expr instanceof ErrorSuppress) {
            $expr = $expr->expr;
        }

        return $expr instanceof FuncCall ? $expr : null;
    }

    /**
     * The Safe\ function the call names, or null when it names none.
     */
    private function safeFunction(FuncCall $call, Scope $scope): ?FunctionReflection
    {
        if (!$call->name instanceof Name || !$this->reflectionProvider->hasFunction($call->name, $scope)) {
            return null;
        }

        $function = $this->reflectionProvider->getFunction($call->name, $scope);
        $segments = explode('\\', $function->getName());

        if (\count($segments) < 2 || self::SAFE_NAMESPACE !== strtolower($segments[0])) {
            return null;
        }

        return $function;
    }

    /**
     * The return type the function declares for these arguments. The call's
     * own type in the scope is not used: PHPStan narrows a repeated call after
     * an earlier check of it, and the narrowed type says nothing about what
     * the function can return.
     */
    private function declaredReturnType(FunctionReflection $function, FuncCall $call, Scope $scope): Type
    {
        return ParametersAcceptorSelector::selectFromArgs($scope, $call->getArgs(), $function->getVariants())
            ->getReturnType()
        ;
    }
}
