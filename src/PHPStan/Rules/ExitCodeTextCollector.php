<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use LTS\PHPQA\ComposerPlugin\ChildExit;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\Print_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Stmt\Echo_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;

/**
 * Collects what ForbidHandFormattedExitCodeRule matches: each place a
 * ProcessResultDto's exit code goes into text, and each local variable a
 * function assigns one to, so a copy of the code is matched too.
 *
 * Text is built by sprintf(), printf() and fprintf(), by `.` and `.=`, by an
 * interpolated string, a `(string)` cast, strval(), echo and print; and by the
 * ChildExit methods that describe a bare code, and
 * ToolResultDto::fromExitCode(), which describe it without knowing whether a
 * signal killed the process. Only an operand that is the code itself, or a
 * variable, is read. A value PHPStan proves lies outside 129 to 192, the codes
 * a shell gives a signal kill, is not collected: it cannot be read as one.
 * Nothing in ProcessResultDto is collected: there the code is described
 * beside the signal, which says whether one killed the child.
 *
 * Each value lists [ASSIGNED or TEXT, function key, the variable's name (empty
 * for the property itself), line] for each match the node holds.
 *
 * @internal
 *
 * @implements Collector<Node, list<array{string, string, string, int}>>
 */
final readonly class ExitCodeTextCollector implements Collector
{
    /** An entry for a local variable assigned an exit code. */
    public const string ASSIGNED = 'assigned';

    /** An entry for an exit code, or a variable, put into text. */
    public const string TEXT = 'text';

    /** The property holding the code. */
    private const string EXIT_CODE = 'exitCode';

    /** The lowest code a shell gives a signal kill: 128 + 1. */
    private const int LOWEST_SIGNAL_CODE = 129;

    /** The highest: 128 + SIGRTMAX on Linux. */
    private const int HIGHEST_SIGNAL_CODE = 192;

    /** Lower-cased function name => how many leading arguments are not formatted into the text. */
    private const array FORMATTERS = [
        'sprintf' => 1,
        'printf'  => 1,
        'fprintf' => 2,
        'strval'  => 0,
    ];

    /** Lower-cased ChildExit methods that describe a bare exit code. */
    private const array DESCRIBERS = ['describe', 'describephpqacihelper', 'killedby', 'describeexiteditself', 'exiteditself'];

    /** The lower-cased ToolResultDto factory that describes a bare exit code. */
    private const string FROM_EXIT_CODE = 'fromexitcode';

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<array{string, string, string, int}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $class = $scope->getClassReflection()?->getName();
        if (ProcessResultDto::class === $class) {
            return null;
        }

        $key = \sprintf('%s::%s', $class, $scope->getFunctionName());
        if ($node instanceof Assign && $node->var instanceof Variable && \is_string($node->var->name) && $this->isExitCode($node->expr, $scope)) {
            return [[self::ASSIGNED, $key, $node->var->name, $node->getStartLine()]];
        }

        $entries = [];
        foreach ($this->textOperands($node, $scope) as $operand) {
            if (!$this->maySayASignal($operand, $scope)) {
                continue;
            }

            if ($this->isExitCode($operand, $scope)) {
                $entries[] = [self::TEXT, $key, '', $node->getStartLine()];
            } elseif ($operand instanceof Variable && \is_string($operand->name)) {
                $entries[] = [self::TEXT, $key, $operand->name, $node->getStartLine()];
            }
        }

        return [] === $entries ? null : $entries;
    }

    /**
     * The operands the node puts into text.
     *
     * @return list<Expr>
     */
    private function textOperands(Node $node, Scope $scope): array
    {
        return match (true) {
            $node instanceof Concat                                                                  => [$node->left, $node->right],
            $node instanceof AssignOp\Concat, $node instanceof Print_, $node instanceof Cast\String_ => [$node->expr],
            $node instanceof Echo_                                                                   => array_values($node->exprs),
            $node instanceof InterpolatedString                                                      => array_values(array_filter($node->parts, static fn (Node $part): bool => $part instanceof Expr)),
            $node instanceof FuncCall                                                                => $this->formatterOperands($node),
            $node instanceof StaticCall                                                              => $this->describerOperands($node, $scope),
            default                                                                                  => [],
        };
    }

    /** @return list<Expr> */
    private function formatterOperands(FuncCall $call): array
    {
        if (!$call->name instanceof Name || $call->isFirstClassCallable()) {
            return [];
        }

        $skip = self::FORMATTERS[$call->name->toLowerString()] ?? null;

        return null === $skip ? [] : $this->values(...\array_slice($call->getArgs(), $skip));
    }

    /** @return list<Expr> */
    private function describerOperands(StaticCall $call, Scope $scope): array
    {
        if (!$call->class instanceof Name || !$call->name instanceof Identifier || $call->isFirstClassCallable()) {
            return [];
        }

        $class  = $scope->resolveName($call->class);
        $method = $call->name->toLowerString();
        $args   = $call->getArgs();
        if (ChildExit::class === $class && \in_array($method, self::DESCRIBERS, true)) {
            return $this->values(...$args);
        }

        return ToolResultDto::class === $class && self::FROM_EXIT_CODE === $method ? $this->values(...\array_slice($args, 0, 1)) : [];
    }

    /** `$result->exitCode` or `$result?->exitCode` on a ProcessResultDto. */
    private function isExitCode(Expr $expr, Scope $scope): bool
    {
        if ((!$expr instanceof PropertyFetch && !$expr instanceof NullsafePropertyFetch) || !$expr->name instanceof Identifier || self::EXIT_CODE !== $expr->name->toString()) {
            return false;
        }

        return new ObjectType(ProcessResultDto::class)->isSuperTypeOf(TypeCombinator::removeNull($scope->getType($expr->var)))->yes();
    }

    /** False only when PHPStan proves the value is no code a shell gives a signal kill. */
    private function maySayASignal(Expr $expr, Scope $scope): bool
    {
        return !IntegerRangeType::fromInterval(self::LOWEST_SIGNAL_CODE, self::HIGHEST_SIGNAL_CODE)->isSuperTypeOf($scope->getType($expr))->no();
    }

    /** @return list<Expr> */
    private function values(Arg ...$args): array
    {
        return array_values(array_map(static fn (Arg $arg): Expr => $arg->value, $args));
    }
}
