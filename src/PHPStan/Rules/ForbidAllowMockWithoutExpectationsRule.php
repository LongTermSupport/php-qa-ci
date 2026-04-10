<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans #[AllowMockObjectsWithoutExpectations] attribute.
 *
 * Use createStub() for dependencies without expectations instead.
 *
 * @implements Rule<Attribute>
 */
final class ForbidAllowMockWithoutExpectationsRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.forbiddenAttribute';

    public function getNodeType(): string
    {
        return Attribute::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $name = $node->name->toString();

        if (\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations::class === $name
            || 'AllowMockObjectsWithoutExpectations'                                 === $name
        ) {
            return [
                RuleErrorBuilder::message(
                    '#[AllowMockObjectsWithoutExpectations] is banned. Use createStub() for dependencies that have no expectations.',
                )->identifier(self::IDENTIFIER)->build(),
            ];
        }

        return [];
    }
}
