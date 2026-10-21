<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\StaticVar;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\VariableWritesNode;

/**
 * Collects, for ForbidNumericStringArrayKeyRule, each write of a local
 * variable in a function-like body where PHPStan records it, so the rule can
 * tell which writes may reach an inline `@var` on a variable already held, or
 * an iterator handed to iterator_to_array(), and join each with what
 * NumericStringArrayKeyCollector's BINDING records say PHPStan knew there.
 *
 * One WRITES record per body: in its uses, each write as name@position, or
 * name@-1 for a variable PHPStan cannot follow because it may be aliased (a
 * reference, `global`, `static`, a by-reference parameter); and name@-2 for
 * one PHPStan stops following only because a call takes it by reference,
 * whose writes there NumericStringArrayKeyCollector's BY_REFERENCE records
 * give. Its key is '*' where any variable may be written unseen (a goto, a
 * variable variable, func_get_args()). A write of an item of an array
 * literal is the literal's own write, and is left out.
 *
 * @internal
 *
 * @phpstan-import-type NumericStringArrayKeyRecord from NumericStringArrayKeyCollector
 *
 * @implements Collector<VariableWritesNode, NumericStringArrayKeyRecord>
 */
final readonly class NumericStringArrayKeyWritesCollector implements Collector
{
    public function getNodeType(): string
    {
        return VariableWritesNode::class;
    }

    /**
     * @return NumericStringArrayKeyRecord
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $function = $node->getFunctionLike();
        $aliased  = $this->aliased($function);
        $writes   = [];
        foreach ($node->getWrites() as $write) {
            if (null === $write->getParentId()) {
                $name     = $write->getVariableName();
                $writes[] = $name . '@' . ($node->isUntracked($name) && (null === $aliased || isset($aliased[$name])) ? NumericStringArrayKeyCollector::UNTRACKED : $write->getNode()->getStartFilePos());
            }
        }

        $finder = new NodeFinder();
        $names  = [];
        foreach ($finder->findInstanceOf([...$function->getParams(), ...$function->getStmts() ?? []], Variable::class) as $variable) {
            if (\is_string($variable->name)) {
                $names[] = $variable->name;
            }
        }

        foreach (array_unique($names) as $name) {
            if (null !== $aliased && !isset($aliased[$name]) && $node->isUntracked($name)) {
                $writes[] = $name . '@' . NumericStringArrayKeyCollector::PASSED_BY_REFERENCE;
            }
        }

        $opaque = $node->isOpaque() || $node->areAllVariableNamesReferenced()
                                    || $finder->findFirst($function->getStmts() ?? [], static fn (Node $inner): bool => $inner instanceof Variable && !\is_string($inner->name)) instanceof Node;

        return [
            'kind'        => NumericStringArrayKeyCollector::WRITES,
            'functionKey' => \sprintf('%s::%s', $scope->getClassReflection()?->getName(), $scope->getFunctionName()),
            'variable'    => '',
            'key'         => $opaque ? NumericStringArrayKeyCollector::ANY : '',
            'depths'      => [],
            'line'        => $function->getStartLine(),
            'start'       => $function->getStartFilePos(),
            'end'         => $function->getEndFilePos(),
            'text'        => '',
            'uses'        => $writes,
            'unless'      => '',
        ];
    }

    /**
     * The variables a body may alias, whose writes PHPStan cannot follow
     * whatever the collector records: a parameter or closure import by
     * reference or a promoted one, either side of `=&`, a reference in an
     * array literal or destructuring (and what such a literal is assigned
     * to), a `foreach` by reference, `global` and `static`; null for every
     * variable where the body returns by reference. A variable PHPStan stops
     * following for none of these is one passed by reference to a call.
     *
     * @return array<array-key, true>|null
     */
    private function aliased(FunctionLike $function): ?array
    {
        if ($function->returnsByRef()) {
            return null;
        }

        $roots = [];
        foreach ($function->getParams() as $param) {
            if ($param->byRef || 0 !== $param->flags) {
                $roots[] = $param->var;
            }
        }

        $finder = new NodeFinder();
        foreach ($finder->find([...$function instanceof Closure ? $function->uses : [], ...$function->getStmts() ?? []], static fn (Node $inner): bool => true) as $inner) {
            $roots = [...$roots, ...match (true) {
                $inner instanceof ClosureUse => $inner->byRef ? [$inner->var] : [],
                $inner instanceof AssignRef  => [$inner->var, $inner->expr],
                $inner instanceof ArrayItem  => $inner->byRef ? [$inner->value] : [],
                $inner instanceof Assign     => $finder->findFirst([$inner->expr], static fn (Node $item): bool => $item instanceof ArrayItem && $item->byRef) instanceof Node ? [$inner->var] : [],
                $inner instanceof Foreach_   => $inner->byRef ? [$inner->valueVar, $inner->expr] : [],
                $inner instanceof Global_    => $inner->vars,
                $inner instanceof StaticVar  => [$inner->var],
                default                      => [],
            }];
        }

        $names = [];
        foreach ($roots as $root) {
            while ($root instanceof ArrayDimFetch) {
                $root = $root->var;
            }

            if ($root instanceof Variable && \is_string($root->name)) {
                $names[$root->name] = true;
            }
        }

        return $names;
    }
}
