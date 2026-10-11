<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassMethodNode;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

/**
 * Collects, for each method of a PHPUnit test case, its own and those it takes
 * from a trait, what RequireProcessStopOnThrowRule needs to tell which
 * receivers the test case's tearDown() and #[After] methods stop. PHPUnit runs
 * both after a test method that throws, so a child held in a property they
 * stop is stopped; the rule keeps only the `$this->` receivers.
 *
 * A stop counts when the method makes it, or when a method of the same class
 * (its own or a trait's) that it calls on `$this`, `self` or `static` makes
 * it: one level deep, so a stop two helpers down is not seen.
 *
 * Only the nearest tearDown() runs, and it runs an ancestor's only through
 * parent::tearDown(); every #[After] method in the hierarchy runs. PHPStan
 * analyses a trait's method in the class that uses it only when the class does
 * not declare one of that name, which is the method PHP runs. The rule
 * assembles each class from these values and walks the hierarchy.
 *
 * Each value is [class name, lower-cased method name, is an #[After] method,
 * calls parent::tearDown(), receivers it stops, lower-cased names of the
 * methods it calls on the class].
 *
 * @internal
 *
 * @implements Collector<InClassMethodNode, array{string, string, bool, bool, list<string>, list<string>}>
 */
final readonly class ProcessStoppingTearDownCollector implements Collector
{
    private ProcessStopCallFinder $stops;

    public function __construct()
    {
        $this->stops = new ProcessStopCallFinder();
    }

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * @return array{string, string, bool, bool, list<string>, list<string>}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $class = $node->getClassReflection();
        if (!\in_array(TestCase::class, $class->getParentClassesNames(), true)) {
            return null;
        }

        $method = $node->getOriginalNode();
        $stmts  = $method->stmts ?? [];

        return [
            $class->getName(),
            $method->name->toLowerString(),
            $this->isAfterHook($method),
            $this->callsParentTearDown($stmts),
            $this->stops->receiversStoppedBy($stmts),
            $this->methodsCalledOnTheClass($stmts),
        ];
    }

    /**
     * @param array<array-key, Node\Stmt> $stmts
     *
     * @return list<string>
     */
    private function methodsCalledOnTheClass(array $stmts): array
    {
        $names = [];
        foreach (new NodeFinder()->find($stmts, static fn (Node $found): bool => $found instanceof CallLike) as $call) {
            $name = $this->nameCalledOnTheClass($call);
            if (null !== $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function nameCalledOnTheClass(Node $call): ?string
    {
        if ($call instanceof StaticCall) {
            $onTheClass = $call->class instanceof Name && \in_array($call->class->toLowerString(), ['self', 'static'], true);
        } elseif ($call instanceof MethodCall || $call instanceof NullsafeMethodCall) {
            $onTheClass = $call->var instanceof Variable && 'this' === $call->var->name;
        } else {
            return null;
        }

        return $onTheClass && $call->name instanceof Identifier ? $call->name->toLowerString() : null;
    }

    /**
     * @param array<array-key, Node\Stmt> $stmts
     */
    private function callsParentTearDown(array $stmts): bool
    {
        return new NodeFinder()->findFirst(
            $stmts,
            static fn (Node $found): bool => $found instanceof StaticCall
                && $found->class instanceof Name
                && 'parent' === $found->class->toLowerString()
                && $found->name instanceof Identifier
                && 'teardown' === $found->name->toLowerString(),
        ) instanceof Node;
    }

    private function isAfterHook(ClassMethod $method): bool
    {
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (After::class === $attribute->name->toString()) {
                    return true;
                }
            }
        }

        return false;
    }
}
