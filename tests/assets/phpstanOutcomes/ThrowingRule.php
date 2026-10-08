<?php

declare(strict_types=1);

namespace PhpstanOutcomes;

use PhpParser\Node;
use PhpParser\Node\Stmt\Return_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use RuntimeException;

/**
 * A rule that throws on every return statement, so PHPStan reports an internal error and
 * abandons the analysis.
 *
 * @implements Rule<Return_>
 */
final class ThrowingRule implements Rule
{
    public function getNodeType(): string
    {
        return Return_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        throw new RuntimeException('thrown by the phpstanOutcomes fixture rule');
    }
}
