<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassNode;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

/**
 * Collects, for each PHPUnit test case, the receivers its tearDown() and its
 * #[After] methods stop. PHPUnit runs both after a test method that throws, so
 * a child held in a property they stop is stopped; the rule keeps only the
 * `$this->` receivers.
 *
 * Only the nearest tearDown() runs, and it runs an ancestor's only through
 * parent::tearDown(); every #[After] method in the hierarchy runs. So each
 * value records whether the parent's tearDown() still runs (it does when the
 * class declares none), and RequireProcessStopOnThrowRule walks the hierarchy
 * with it.
 *
 * Each value is [class name, the parent's tearDown runs, receivers its
 * tearDown stops, receivers its #[After] methods stop].
 *
 * @internal
 *
 * @implements Collector<InClassNode, array{string, bool, list<string>, list<string>}>
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
        return InClassNode::class;
    }

    /**
     * @return array{string, bool, list<string>, list<string>}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $class = $node->getClassReflection();
        if (!\in_array(TestCase::class, $class->getParentClassesNames(), true)) {
            return null;
        }

        // A class that declares no tearDown() runs its parent's.
        $runsParentTearDown = true;
        $tearDownStops      = [];
        $afterStops         = [];
        foreach ($node->getOriginalNode()->getMethods() as $method) {
            $stmts = $method->stmts ?? [];
            if ('teardown' === $method->name->toLowerString()) {
                $runsParentTearDown = $this->callsParentTearDown($stmts);
                $tearDownStops      = $this->stops->receiversStoppedBy($stmts);
            }

            if ($this->isAfterHook($method)) {
                $afterStops = [...$afterStops, ...$this->stops->receiversStoppedBy($stmts)];
            }
        }

        return [$class->getName(), $runsParentTearDown, $tearDownStops, $afterStops];
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
