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
use PHPStan\Node\FileNode;

/**
 * Collects each function, method, closure and arrow function, and the file's
 * top-level code, with where each set_error_handler() call directly in its body
 * starts. A try whose finally restores the handler guards a PCRE call only
 * where the innermost function holding the call installed one before it: a
 * finally restoring a handler nobody installed guards nothing, and one
 * installed in a closure runs only when that closure is called.
 *
 * Each value is [body start, body end (inclusive), set_error_handler() start
 * offsets]; the top-level code spans the whole file.
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
        return match (true) {
            $node instanceof FunctionLike => [$node->getStartFilePos(), $node->getEndFilePos(), $this->installs($node->getStmts() ?? [])],
            $node instanceof FileNode     => [0, \PHP_INT_MAX, $this->installs($node->getNodes())],
            default                       => null,
        };
    }

    /**
     * A call in a function or class declared inside runs elsewhere, so it is that one's.
     *
     * @param array<Node> $stmts
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
