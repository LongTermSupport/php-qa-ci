<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

/**
 * Finds the receivers of the stop() calls that run as part of a block, printed
 * so they compare with the receivers ProcessLiveCodeCallCollector prints. A
 * call inside a function, closure or class declared in the block does not run
 * there, so it is not counted. Also finds the loops in a block with the
 * variables each sets on every pass, and the stops a block makes once per pass
 * of a foreach, for a process started in a loop.
 *
 * @internal
 */
final readonly class ProcessStopCallFinder
{
    private Standard $printer;

    public function __construct()
    {
        $this->printer = new Standard();
    }

    /**
     * @param array<array-key, Stmt> $stmts
     *
     * @return list<string>
     */
    public function receiversStoppedBy(array $stmts): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<Expr> */
            public array $receivers = [];

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof FunctionLike || $node instanceof ClassLike) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if (($node instanceof MethodCall || $node instanceof NullsafeMethodCall)
                    && $node->name instanceof Identifier
                    && 'stop' === $node->name->toLowerString()
                    && !$node->isFirstClassCallable()) {
                    $this->receivers[] = $node->var;
                }

                return null;
            }
        };
        new NodeTraverser($visitor)->traverse($stmts);

        // Only ever searched with in_array(), so a receiver stopped twice is listed twice.
        return array_map($this->printer->prettyPrintExpr(...), $visitor->receivers);
    }

    /**
     * The receivers a block stops once per pass of a foreach over them: a stop
     * inside a foreach whose key or value variable the receiver is rooted at.
     *
     * @param array<array-key, Stmt> $stmts
     *
     * @return list<string>
     */
    public function receiversStoppedPerIteration(array $stmts): array
    {
        $stopped = [];
        foreach (new NodeFinder()->findInstanceOf($stmts, Foreach_::class) as $foreach) {
            $variables = $this->assignedBy($foreach->keyVar, $foreach->valueVar);
            foreach ($this->receiversStoppedBy($foreach->stmts) as $receiver) {
                if ($this->isRootedAtAny($receiver, ...$variables)) {
                    $stopped[] = $receiver;
                }
            }
        }

        return $stopped;
    }

    /**
     * The loops in a block, as [start, end (inclusive), the variables the loop
     * sets on each pass]: a foreach's key and value, and whatever is assigned
     * anywhere in the loop, its condition included.
     *
     * @param array<array-key, Node> $nodes
     *
     * @return list<array{int, int, list<string>}>
     */
    public function loopsIn(array $nodes): array
    {
        $loops = [];
        foreach (new NodeFinder()->find($nodes, static fn (Node $found): bool => $found instanceof Foreach_
            || $found instanceof For_ || $found instanceof While_ || $found instanceof Do_) as $loop) {
            $assigned = $loop instanceof Foreach_ ? [$loop->keyVar, $loop->valueVar] : [];
            $finder   = new NodeFinder();
            foreach ([...$finder->findInstanceOf([$loop], Assign::class), ...$finder->findInstanceOf([$loop], AssignRef::class)] as $assign) {
                $assigned[] = $assign->var;
            }

            $loops[] = [$loop->getStartFilePos(), $loop->getEndFilePos(), $this->assignedBy(...$assigned)];
        }

        return $loops;
    }

    /** A receiver is rooted at a variable when it is that variable, or a property or element of it. */
    public function isRootedAtAny(string $receiver, string ...$variables): bool
    {
        return array_any($variables, static fn (string $variable): bool => $receiver === $variable
            || str_starts_with($receiver, $variable . '->')
            || str_starts_with($receiver, $variable . '?->')
            || str_starts_with($receiver, $variable . '['));
    }

    /**
     * The printed targets of an assignment, a destructuring list taken apart.
     *
     * @return list<string>
     */
    private function assignedBy(?Expr ...$targets): array
    {
        $printed = [];
        foreach ($targets as $target) {
            if ($target instanceof List_ || $target instanceof Array_) {
                $printed = [...$printed, ...$this->assignedBy(...array_map(
                    static fn (?ArrayItem $item): ?Expr => $item?->value,
                    $target->items,
                ))];
            } elseif ($target instanceof Expr) {
                $printed[] = $this->printer->prettyPrintExpr($target);
            }
        }

        return $printed;
    }
}
