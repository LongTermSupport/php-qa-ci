<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans instantiation of mutable DateTime in favour of DateTimeImmutable.
 *
 * Mutable DateTime objects cause subtle bugs when passed between services:
 * a callee can modify a date the caller still references. DateTimeImmutable
 * eliminates this entire bug class by returning new instances from mutation
 * methods.
 *
 * WRONG:
 *   $now = new DateTime();
 *
 * RIGHT:
 *   $now = new DateTimeImmutable();
 *
 * Skips test files since tests may legitimately create DateTime objects.
 *
 * @implements Rule<New_>
 */
final class ForbidNewDateTimeRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.newDateTime';

    public function getNodeType(): string
    {
        return New_::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->class instanceof Name) {
            return [];
        }

        if ($this->isTestContext($scope)) {
            return [];
        }

        $resolvedName = $scope->resolveName($node->class);

        if ('DateTime' !== $resolvedName) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Use DateTimeImmutable instead of DateTime. Mutable date/time objects cause subtle bugs '
                . 'when shared between services. Replace "new DateTime()" with "new DateTimeImmutable()".',
            )->identifier(self::IDENTIFIER)->build(),
        ];
    }

    private function isTestContext(Scope $scope): bool
    {
        $namespace = $scope->getNamespace();
        if (null !== $namespace && str_contains($namespace, 'Tests')) {
            return true;
        }

        return str_contains($scope->getFile(), '/tests/');
    }
}
