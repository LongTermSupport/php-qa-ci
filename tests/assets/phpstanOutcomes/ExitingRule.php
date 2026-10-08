<?php

declare(strict_types=1);

namespace PhpstanOutcomes;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * A rule that ends its process on the first function call, so the parallel worker running it
 * dies the way a segfaulting or OOM-killed worker does, and PHPStan abandons the analysis.
 *
 * @implements Rule<FuncCall>
 */
final class ExitingRule implements Rule
{
    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        exit(3);
    }
}
