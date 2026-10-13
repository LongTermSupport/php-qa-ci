<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Static_;
use PhpParser\Node\StaticVar;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ReflectionProvider;

/**
 * Collects every write to a local variable, and whether what it writes is a
 * pattern the code owns ({@see RegexPatternOwnership}), so a PCRE call given
 * the variable can be judged by everything the variable could hold.
 *
 * An assignment or a `.=` / `??=` of an owned value is owned, given the local
 * variables it is built from are. A write whose value cannot be read here is
 * not: by reference, by destructuring, by a foreach, by `static` or `global`,
 * or by any other compound assignment.
 *
 * Each value lists [function key, variable name, the local variables the
 * written value is built from, or null when it is not owned] for each variable
 * the node writes.
 *
 * @internal
 *
 * @implements Collector<Node, list<array{string, string, list<string>|null}>>
 */
final readonly class RegexPatternVariableWriteCollector implements Collector
{
    private RegexPatternOwnership $ownership;

    public function __construct(ReflectionProvider $reflectionProvider)
    {
        $this->ownership = new RegexPatternOwnership($reflectionProvider);
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<array{string, string, list<string>|null}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $writes = match (true) {
            $node instanceof Assign           => $node->var instanceof Variable
                ? $this->write($node->var, $this->ownership->dependencies($node->expr, $scope, false))
                : $this->unownedWrites($node->var),
            $node instanceof AssignOp\Concat, $node instanceof AssignOp\Coalesce => $this->write(
                $node->var,
                $this->ownership->dependencies($node->expr, $scope, false),
            ),
            $node instanceof AssignOp, $node instanceof AssignRef => $this->write($node->var, null),
            $node instanceof Foreach_         => [...$this->unownedWrites($node->keyVar), ...$this->unownedWrites($node->valueVar)],
            $node instanceof Static_          => array_merge(...array_map(fn (StaticVar $var): array => $this->write($var->var, null), $node->vars)),
            $node instanceof Global_          => array_merge(...array_map(fn (Expr $var): array => $this->write($var, null), $node->vars)),
            default                           => [],
        };
        if ([] === $writes) {
            return null;
        }

        $key = RegexPatternOwnership::functionKey($scope);

        return array_map(static fn (array $write): array => [$key, $write[0], $write[1]], $writes);
    }

    /**
     * @param list<string>|null $dependencies
     *
     * @return list<array{string, list<string>|null}>
     */
    private function write(Expr $var, ?array $dependencies): array
    {
        return $var instanceof Variable && \is_string($var->name) ? [[$var->name, $dependencies]] : [];
    }

    /**
     * Every variable a destructuring target writes, or the variable itself.
     *
     * @return list<array{string, list<string>|null}>
     */
    private function unownedWrites(?Expr $target): array
    {
        if (!$target instanceof List_ && !$target instanceof Array_) {
            return null === $target ? [] : $this->write($target, null);
        }

        $writes = [];
        foreach ($target->items as $item) {
            if ($item instanceof ArrayItem) {
                $writes = [...$writes, ...$this->unownedWrites($item->value)];
            }
        }

        return $writes;
    }
}
