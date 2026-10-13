<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

/**
 * Collects each function, method, closure and arrow function with where each
 * set_error_handler() call directly in its body starts, and each such call in
 * the file's top-level code. A try whose finally restores the handler guards a
 * PCRE call only where the innermost function holding the call installed one
 * before it: a finally restoring a handler nobody installed guards nothing, and
 * one installed in a closure runs only when that closure is called.
 *
 * Each value is [body start, body end (inclusive), set_error_handler() start
 * offsets]; a top-level call is recorded alone, as a body spanning the whole file.
 *
 * @internal
 *
 * @implements Collector<Node, array{int, int, list<int>}>
 */
final readonly class ErrorHandlerInstallCollector implements Collector
{
    private const string INSTALL = 'set_error_handler';

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return array{int, int, list<int>}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if ($node instanceof FunctionLike) {
            return [$node->getStartFilePos(), $node->getEndFilePos(), $this->installs($node->getStmts() ?? [])];
        }

        $topLevel = null === $scope->getFunctionName() && !$scope->isInAnonymousFunction();

        return $topLevel && $this->isInstall($node) ? [0, \PHP_INT_MAX, [$node->getStartFilePos()]] : null;
    }

    private function isInstall(Node $node): bool
    {
        return $node instanceof FuncCall && $node->name instanceof Name && self::INSTALL === $node->name->toLowerString();
    }

    /**
     * A call in a function or class declared inside runs elsewhere, so it is that one's.
     *
     * @param array<array-key, Node> $stmts
     *
     * @return list<int>
     */
    private function installs(array $stmts): array
    {
        $visitor = new class(self::INSTALL) extends NodeVisitorAbstract {
            /** @var list<int> */
            public array $found = [];

            public function __construct(private readonly string $install)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof FunctionLike || $node instanceof ClassLike) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof FuncCall && $node->name instanceof Name && $this->install === $node->name->toLowerString()) {
                    $this->found[] = $node->getStartFilePos();
                }

                return null;
            }
        };
        new NodeTraverser($visitor)->traverse($stmts);

        return $visitor->found;
    }
}
