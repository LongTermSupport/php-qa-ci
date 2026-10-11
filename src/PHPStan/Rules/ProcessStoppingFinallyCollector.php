<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Block;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Finally_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\VirtualNode;

/**
 * Collects each try statement whose finally calls stop() on something: the file
 * range the finally guards, the spans inside it that do not run there, the
 * printed receivers the finally stops, and the start() call that is the
 * statement immediately before the try, when the finally stops its receiver.
 *
 * The guarded range runs from the `try` keyword to the `finally` keyword, so it
 * holds the try block and every catch block: PHP runs the finally after a catch
 * block too, whether the catch completes or throws. The finally block itself is
 * outside it, because code there that throws skips the rest of the finally.
 *
 * A function, closure or class declared inside the range does not run there,
 * so its span is recorded as excluded.
 *
 * PHPStan nodes carry no sibling links, so the statement before a try is found
 * by reading statement lists: the file's, and those of each node that owns one.
 * An if, a try and a switch read their else, catch, finally and case blocks
 * themselves, so each list is read once.
 *
 * Each value lists, for each try statement with a finally in the lists the
 * node owns, [range start, range end (exclusive), excluded spans as
 * [start, end] inclusive, stopped receivers, start offset of the start() call
 * immediately before the try, or null].
 *
 * @internal
 *
 * @implements Collector<Node, list<array{int, int, list<array{int, int}>, list<string>, int|null}>>
 */
final readonly class ProcessStoppingFinallyCollector implements Collector
{
    private ProcessStopCallFinder $stops;

    private Standard $printer;

    public function __construct()
    {
        $this->stops   = new ProcessStopCallFinder();
        $this->printer = new Standard();
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<array{int, int, list<array{int, int}>, list<string>, int|null}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $guards = [];
        foreach ($this->statementLists($node) as $stmts) {
            $previous = null;
            foreach ($stmts as $stmt) {
                if ($stmt instanceof TryCatch && $stmt->finally instanceof Finally_) {
                    $guards[] = $this->guard($stmt, $stmt->finally, $previous);
                }

                $previous = $stmt;
            }
        }

        return [] === $guards ? null : $guards;
    }

    /**
     * @return array{int, int, list<array{int, int}>, list<string>, int|null}
     */
    private function guard(TryCatch $try, Finally_ $finally, mixed $previous): array
    {
        $excluded     = [];
        $declarations = new NodeFinder()->find(
            [...$try->stmts, ...$try->catches],
            static fn (Node $found): bool => $found instanceof FunctionLike || $found instanceof ClassLike,
        );
        foreach ($declarations as $declaration) {
            $excluded[] = [$declaration->getStartFilePos(), $declaration->getEndFilePos()];
        }

        $stopped = $this->stops->receiversStoppedBy($finally->stmts);
        $start   = $previous instanceof Expression ? $this->startCall($previous) : null;

        return [
            $try->getStartFilePos(),
            $finally->getStartFilePos(),
            $excluded,
            $stopped,
            null !== $start && \in_array($this->printer->prettyPrintExpr($start->var), $stopped, true)
                ? $start->getStartFilePos()
                : null,
        ];
    }

    private function startCall(Expression $statement): MethodCall|NullsafeMethodCall|null
    {
        $call = $statement->expr;
        if (($call instanceof MethodCall || $call instanceof NullsafeMethodCall)
            && $call->name instanceof Identifier
            && 'start' === $call->name->toLowerString()
            && !$call->isFirstClassCallable()) {
            return $call;
        }

        return null;
    }

    /**
     * A file's top-level statements come as PHPStan's FileNode, recognised by
     * the interface it implements and the method it has: PHPStan does not
     * promise that an instanceof on one of its classes stays true.
     *
     * @return list<array<array-key, mixed>>
     */
    private function statementLists(Node $node): array
    {
        return array_values(match (true) {
            $node instanceof VirtualNode                                                                       => method_exists($node, 'getNodes') && \is_array($nodes = $node->getNodes()) ? [$nodes] : [],
            $node instanceof If_                                                                               => [
                $node->stmts,
                ...array_map(static fn (ElseIf_ $elseIf): array => $elseIf->stmts, $node->elseifs),
                ...($node->else instanceof Else_ ? [$node->else->stmts] : []),
            ],
            $node instanceof TryCatch                                                                          => [
                $node->stmts,
                ...array_map(static fn (Catch_ $catch): array => $catch->stmts, $node->catches),
                ...($node->finally instanceof Finally_ ? [$node->finally->stmts] : []),
            ],
            $node instanceof Switch_                                                                           => array_map(static fn (Case_ $case): array => $case->stmts, $node->cases),
            $node instanceof ClassMethod, $node instanceof Declare_                                            => [$node->stmts ?? []],
            $node instanceof Closure, $node instanceof Function_, $node instanceof For_, $node instanceof Foreach_,
            $node instanceof While_, $node instanceof Do_, $node instanceof Namespace_, $node instanceof Block => [$node->stmts],
            default                                                                                            => [],
        });
    }
}
