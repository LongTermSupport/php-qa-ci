<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports `PHP_ZTS` or `PHP_DEBUG` read as it is, wherever the result can depend on its type.
 * Their type depends on the PHP version (a bool since PHP 8.4, an int before, and PHPStan types
 * them 0|1 everywhere), so a strict comparison, a string conversion, a type test, a stored or
 * passed value and a comparison with any value other than 0, 1, true, false, '0' or '1' behave
 * differently by version, and PHPStan cannot say so. `(bool) \PHP_ZTS` is right on every version.
 *
 * Not reported: a use that reads only the constant's truth or its number, which is the same for
 * 0/1 and false/true (a condition, `!`, `&&`, `(bool)`, `(int)`, arithmetic, an array key, a
 * comparison whose result the evaluation below shows to be the same on both types).
 *
 * See: docs/phpstan-rules/forbid-build-constant-type-dependent-use.md
 *
 * @implements Rule<FileNode>
 */
final readonly class ForbidBuildConstantTypeDependentUseRule implements Rule
{
    /** The identifier every finding carries. */
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.buildConstantTypeDependentUse';

    /** The constants whose type changed from int to bool in PHP 8.4. */
    private const array CONSTANTS = ['PHP_ZTS', 'PHP_DEBUG'];

    /** Parents that read only their operand's truth or number. */
    private const array TYPE_BLIND_PARENTS = [
        Cast\Bool_::class, Cast\Int_::class, Cast\Double::class, BooleanNot::class, UnaryMinus::class,
        UnaryPlus::class, Empty_::class, Expression::class,
        BinaryOp\BooleanAnd::class, BinaryOp\BooleanOr::class, BinaryOp\LogicalAnd::class,
        BinaryOp\LogicalOr::class, BinaryOp\LogicalXor::class,
        BinaryOp\Plus::class, BinaryOp\Minus::class, BinaryOp\Mul::class, BinaryOp\Div::class,
        BinaryOp\Mod::class, BinaryOp\Pow::class, BinaryOp\BitwiseAnd::class, BinaryOp\BitwiseOr::class,
        BinaryOp\BitwiseXor::class, BinaryOp\ShiftLeft::class, BinaryOp\ShiftRight::class,
        AssignOp\Plus::class, AssignOp\Minus::class, AssignOp\Mul::class, AssignOp\Div::class,
        AssignOp\Mod::class, AssignOp\Pow::class, AssignOp\BitwiseAnd::class, AssignOp\BitwiseOr::class,
        AssignOp\BitwiseXor::class, AssignOp\ShiftLeft::class, AssignOp\ShiftRight::class,
    ];

    /** Functions that read only their argument's truth or number. */
    private const array TYPE_BLIND_FUNCTIONS = ['boolval', 'intval', 'floatval'];

    /** Compared with `===` or `!==`: alike when `===` gives the same for both types. */
    private const string STRICT = 'strict';

    /** Compared with `==`, `!=` or `<=>`: alike when `<=>` gives the same for both types. */
    private const string ORDER = 'order';

    /** The constant `<` the other, or the negation: alike when `<` gives the same for both types. */
    private const string BELOW = 'below';

    /** The constant `<=` the other, or the negation: alike when `<=` gives the same for both types. */
    private const string AT_MOST = 'atMost';

    /**
     * Each comparison's family with the constant on its left, then on its right: `2 > C` is
     * `C < 2`, `2 < C` is `!(C <= 2)`, and a negation is alike exactly when what it negates is.
     */
    private const array COMPARISONS = [
        BinaryOp\Identical::class      => [self::STRICT, self::STRICT],
        BinaryOp\NotIdentical::class   => [self::STRICT, self::STRICT],
        BinaryOp\Equal::class          => [self::ORDER, self::ORDER],
        BinaryOp\NotEqual::class       => [self::ORDER, self::ORDER],
        BinaryOp\Spaceship::class      => [self::ORDER, self::ORDER],
        BinaryOp\Smaller::class        => [self::BELOW, self::AT_MOST],
        BinaryOp\GreaterOrEqual::class => [self::BELOW, self::AT_MOST],
        BinaryOp\SmallerOrEqual::class => [self::AT_MOST, self::BELOW],
        BinaryOp\Greater::class        => [self::AT_MOST, self::BELOW],
    ];

    /** The constant's two values, each as [PHP 8.3 and earlier, PHP 8.4 on]. */
    private const array VALUES = [[0, false], [1, true]];

    /** The keyword constants a literal can be, boxed so that null is a value. */
    private const array KEYWORDS = ['true' => [true], 'false' => [false], 'null' => [null]];

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $visitor = new class(self::CONSTANTS) extends NodeVisitorAbstract {
            /** @var list<array{ConstFetch, list<Node>}> each use, with its ancestors, outermost first */
            public array $uses = [];

            /** @var list<Node> the nodes entered and not yet left, innermost last */
            private array $path = [];

            /** @param list<string> $constants */
            public function __construct(private readonly array $constants)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof ConstFetch && \in_array($node->name->toString(), $this->constants, true)) {
                    $this->uses[] = [$node, $this->path];
                }

                $this->path[] = $node;

                return null;
            }

            public function leaveNode(Node $node): null
            {
                array_pop($this->path);

                return null;
            }
        };
        new NodeTraverser($visitor)->traverse($node->getNodes());

        $errors = [];
        foreach ($visitor->uses as [$constant, $ancestors]) {
            if ($this->endsReadOnlyForTruthOrNumber($constant, ...$ancestors)) {
                continue;
            }

            $name     = $constant->name->toString();
            $errors[] = RuleErrorBuilder::message(\sprintf(
                '%s is read as it is, but it is a bool since PHP 8.4 and an int before (PHPStan types it 0|1 on every version), so this use behaves differently by version. Write (bool) \%s.',
                $name,
                $name,
            ))->line($constant->getStartLine())->identifier(self::IDENTIFIER)->build();
        }

        return $errors;
    }

    /**
     * Follows the constant's value up through the nodes that hand it on unchanged, then asks
     * whether the node it ends in reads only its truth or number. $ancestors run outermost first.
     */
    private function endsReadOnlyForTruthOrNumber(ConstFetch $constant, Node ...$ancestors): bool
    {
        $value = $constant;
        $index = \count($ancestors) - 1;
        while ($this->handsOn($ancestors[$index], $value)) {
            $value = $ancestors[$index];
            --$index;
        }

        return $this->readsOnlyTruthOrNumber($value, $ancestors[$index], $ancestors[$index - 1] ?? null);
    }

    /** Whether $parent's own value is $value unchanged, when $value is what it yields. */
    private function handsOn(Node $parent, Node $value): bool
    {
        return match (true) {
            $parent instanceof BinaryOp\Coalesce => true,
            $parent instanceof Ternary           => $value !== $parent->cond || !$parent->if instanceof Expr,
            $parent instanceof MatchArm          => $value === $parent->body,
            $parent instanceof Match_            => $value !== $parent->cond,
            default                              => false,
        };
    }

    private function readsOnlyTruthOrNumber(Node $value, Node $parent, ?Node $grandparent): bool
    {
        if (\in_array($parent::class, self::TYPE_BLIND_PARENTS, true)) {
            return true;
        }

        if ($parent instanceof BinaryOp && isset(self::COMPARISONS[$parent::class])) {
            [$onLeft, $onRight] = self::COMPARISONS[$parent::class];

            return $value === $parent->left
                ? $this->comparesAlike($onLeft, $parent->right)
                : $this->comparesAlike($onRight, $parent->left);
        }

        // A ternary that did not hand the value on has it as the condition of a full ternary.
        return match (true) {
            $parent instanceof Ternary                                => true,
            $parent instanceof If_, $parent instanceof ElseIf_,
            $parent instanceof While_, $parent instanceof Do_         => $value === $parent->cond,
            $parent instanceof For_                                   => \in_array($value, $parent->cond, true),
            $parent instanceof ArrayItem                              => $value === $parent->key,
            $parent instanceof ArrayDimFetch                          => $value === $parent->dim,
            $parent instanceof Switch_                                => $this->casesCompareAlike($parent),
            $parent instanceof Case_                                  => $this->subjectComparesAlike($grandparent),
            $parent instanceof Arg                                    => $this->isTypeBlindCall($grandparent),
            default                                                   => false,
        };
    }

    /** Whether every case of a switch on the constant compares alike on both types. */
    private function casesCompareAlike(Switch_ $switch): bool
    {
        return array_all($switch->cases, fn (Case_ $case): bool => !$case->cond instanceof Expr || $this->comparesAlike(self::ORDER, $case->cond));
    }

    /** Whether the subject of the switch whose case the constant is compares alike with it. */
    private function subjectComparesAlike(?Node $switch): bool
    {
        \assert($switch instanceof Switch_);

        return $this->comparesAlike(self::ORDER, $switch->cond);
    }

    /**
     * Whether comparing the constant with $other gives the same result whether the constant is
     * 0/1 or false/true: $other is the other build constant, or a literal for which it does. The
     * comparison is the constant on the left of $family's operator.
     */
    private function comparesAlike(string $family, Expr $other): bool
    {
        if ($other instanceof ConstFetch && \in_array($other->name->toString(), self::CONSTANTS, true)) {
            return true;
        }

        $literal = $this->literal($other);
        if (null === $literal) {
            return false;
        }

        $value = $literal[0];

        return array_all(self::VALUES, static fn (array $pair): bool => match ($family) {
            self::STRICT  => ($pair[0] === $value) === ($pair[1] === $value),
            self::ORDER   => ($pair[0] <=> $value) === ($pair[1] <=> $value),
            self::BELOW   => ($pair[0] < $value)   === ($pair[1] < $value),
            default       => ($pair[0] <= $value)  === ($pair[1] <= $value),
        });
    }

    /**
     * The value of a literal, boxed so that null is a value; null when $expr is not a literal.
     *
     * @return array{bool|float|int|string|null}|null
     */
    private function literal(Expr $expr): ?array
    {
        return match (true) {
            $expr instanceof Int_, $expr instanceof Float_, $expr instanceof String_  => [$expr->value],
            $expr instanceof ConstFetch                                               => self::KEYWORDS[$expr->name->toLowerString()] ?? null,
            default                                                                   => null,
        };
    }

    private function isTypeBlindCall(?Node $call): bool
    {
        return $call instanceof FuncCall
            && $call->name instanceof Name
            && \in_array($call->name->toLowerString(), self::TYPE_BLIND_FUNCTIONS, true);
    }
}
