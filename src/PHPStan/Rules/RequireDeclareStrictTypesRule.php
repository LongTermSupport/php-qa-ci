<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Declare_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Requires declare(strict_types=1) in every PHP file.
 *
 * Strict types prevent silent type coercion, catching bugs at the call site.
 * This is mandatory per CLAUDE.md convention — this rule enforces it.
 *
 * See: docs/phpstan-rules/require-declare-strict-types.md for fix documentation.
 *
 * @implements Rule<FileNode>
 */
final class RequireDeclareStrictTypesRule implements Rule
{
    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $nodes = $node->getNodes();

        foreach ($nodes as $stmt) {
            if ($stmt instanceof Declare_) {
                foreach ($stmt->declares as $declare) {
                    if ($declare->key->toString() === 'strict_types') {
                        return [];
                    }
                }
            }
        }

        return [
            RuleErrorBuilder::message(
                'Missing declare(strict_types=1) at the top of the file. '
                . 'All PHP files must declare strict types.',
            )->identifier('counselbook.missingStrictTypes')->build(),
        ];
    }
}
