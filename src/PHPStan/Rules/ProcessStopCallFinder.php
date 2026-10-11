<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

/**
 * Finds the receivers of the stop() calls that run as part of a block, printed
 * so they compare with the receivers ProcessLiveCodeCallCollector prints. A
 * call inside a function, closure or class declared in the block does not run
 * there, so it is not counted.
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
            /** @var list<Node\Expr> */
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
}
