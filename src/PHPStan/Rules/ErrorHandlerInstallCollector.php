<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

/**
 * Collects each set_error_handler() call, so a try whose finally restores the
 * handler guards a PCRE call only where its function installed one before it:
 * a finally restoring a handler nobody installed guards nothing.
 *
 * Each value is [function key, call start offset].
 *
 * @internal
 *
 * @implements Collector<FuncCall, array{string, int}>
 */
final readonly class ErrorHandlerInstallCollector implements Collector
{
    private const string INSTALL = 'set_error_handler';

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @return array{string, int}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (!$node->name instanceof Name || self::INSTALL !== $node->name->toLowerString()) {
            return null;
        }

        return [RegexPatternOwnership::functionKey($scope), $node->getStartFilePos()];
    }
}
