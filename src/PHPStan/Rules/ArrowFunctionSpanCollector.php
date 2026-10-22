<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

/**
 * Collects, for ForbidNumericStringArrayKeyRule, where each arrow function
 * starts and ends in its file. PHPStan records no body for an arrow function,
 * so the rule cannot see from the writes of the enclosing body that a call in
 * one is the arrow function's own: it takes every variable of the enclosing
 * body by value, so a call in it that takes one by reference writes a copy,
 * never the enclosing body's variable.
 *
 * @internal
 *
 * @implements Collector<ArrowFunction, array{start: int, end: int}>
 */
final readonly class ArrowFunctionSpanCollector implements Collector
{
    public function getNodeType(): string
    {
        return ArrowFunction::class;
    }

    /**
     * @return array{start: int, end: int}
     */
    public function processNode(Node $node, Scope $scope): array
    {
        return ['start' => $node->getStartFilePos(), 'end' => $node->getEndFilePos()];
    }
}
