<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

/**
 * Collects each try statement whose finally calls stop() on something: the file
 * range the finally guards, the spans inside it that do not run there, and the
 * printed receivers the finally stops.
 *
 * The guarded range runs from the `try` keyword to the `finally` keyword, so it
 * holds the try block and every catch block: PHP runs the finally after a catch
 * block too, whether the catch completes or throws. The finally block itself is
 * outside it, because code there that throws skips the rest of the finally.
 *
 * A function, closure or class declared inside the range does not run there,
 * so its span is recorded as excluded.
 *
 * Each value is [range start, range end (exclusive), excluded spans as
 * [start, end] inclusive, stopped receivers].
 *
 * @internal
 *
 * @implements Collector<TryCatch, array{int, int, list<array{int, int}>, list<string>}>
 */
final readonly class ProcessStoppingFinallyCollector implements Collector
{
    private ProcessStopCallFinder $stops;

    public function __construct()
    {
        $this->stops = new ProcessStopCallFinder();
    }

    public function getNodeType(): string
    {
        return TryCatch::class;
    }

    /**
     * @return array{int, int, list<array{int, int}>, list<string>}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (null === $node->finally) {
            return null;
        }

        $excluded     = [];
        $declarations = new NodeFinder()->find(
            [...$node->stmts, ...$node->catches],
            static fn (Node $found): bool => $found instanceof FunctionLike || $found instanceof ClassLike,
        );
        foreach ($declarations as $declaration) {
            $excluded[] = [$declaration->getStartFilePos(), $declaration->getEndFilePos()];
        }

        return [
            $node->getStartFilePos(),
            $node->finally->getStartFilePos(),
            $excluded,
            $this->stops->receiversStoppedBy($node->finally->stmts),
        ];
    }
}
