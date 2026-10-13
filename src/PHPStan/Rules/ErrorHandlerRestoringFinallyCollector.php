<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Finally_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

/**
 * Collects each try statement whose finally calls restore_error_handler():
 * the code it guards runs under an error handler the function installed for
 * it, which is where a pattern that does not compile is turned into an error
 * that names it.
 *
 * The guarded range runs from the `try` keyword to the `finally` keyword, so
 * it holds the try block and every catch block, all of which the finally
 * follows. A function, closure or class declared inside the range runs
 * elsewhere, whenever it is called, so its span is recorded as excluded.
 *
 * Each value is [range start, range end (exclusive), excluded spans as
 * [start, end] inclusive].
 *
 * @internal
 *
 * @implements Collector<TryCatch, array{int, int, list<array{int, int}>}>
 */
final readonly class ErrorHandlerRestoringFinallyCollector implements Collector
{
    private const string RESTORE = 'restore_error_handler';

    public function getNodeType(): string
    {
        return TryCatch::class;
    }

    /**
     * @return array{int, int, list<array{int, int}>}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (!$node->finally instanceof Finally_ || !$this->restoresTheErrorHandler($node->finally)) {
            return null;
        }

        $excluded = [];
        foreach (new NodeFinder()->find([...$node->stmts, ...$node->catches], static fn (Node $found): bool => $found instanceof FunctionLike || $found instanceof ClassLike) as $declaration) {
            $excluded[] = [$declaration->getStartFilePos(), $declaration->getEndFilePos()];
        }

        return [$node->getStartFilePos(), $node->finally->getStartFilePos(), $excluded];
    }

    /** A call in a function or class declared in the finally runs elsewhere, so it does not count. */
    private function restoresTheErrorHandler(Finally_ $finally): bool
    {
        $visitor = new class(self::RESTORE) extends NodeVisitorAbstract {
            public bool $found = false;

            public function __construct(private readonly string $restore)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof FunctionLike || $node instanceof ClassLike) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof FuncCall && $node->name instanceof Name && $this->restore === $node->name->toLowerString()) {
                    $this->found = true;

                    return NodeVisitor::STOP_TRAVERSAL;
                }

                return null;
            }
        };
        new NodeTraverser($visitor)->traverse($finally->stmts);

        return $visitor->found;
    }
}
