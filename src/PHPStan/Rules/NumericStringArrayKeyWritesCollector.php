<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
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
 * name@-1 for a variable PHPStan cannot follow (a reference, `global`,
 * `static`, a by-reference parameter); its key is '*' where any variable may
 * be written unseen (a goto, a variable variable, func_get_args()). A write of
 * an item of an array literal is the literal's own write, and is left out.
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
        $writes   = [];
        foreach ($node->getWrites() as $write) {
            if (null === $write->getParentId()) {
                $name     = $write->getVariableName();
                $writes[] = $name . '@' . ($node->isUntracked($name) ? NumericStringArrayKeyCollector::UNTRACKED : $write->getNode()->getStartFilePos());
            }
        }

        $opaque = $node->isOpaque() || $node->areAllVariableNamesReferenced()
                                    || new NodeFinder()->findFirst($function->getStmts() ?? [], static fn (Node $inner): bool => $inner instanceof Variable && !\is_string($inner->name)) instanceof Node;

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
}
