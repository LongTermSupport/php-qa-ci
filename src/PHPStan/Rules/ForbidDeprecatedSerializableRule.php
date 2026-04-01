<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans the deprecated Serializable interface.
 *
 * The Serializable interface was deprecated in PHP 8.1. Classes should use
 * the __serialize() and its counterpart magic methods instead, which provide
 * better type safety and performance.
 *
 * @implements Rule<Class_>
 */
final class ForbidDeprecatedSerializableRule implements Rule
{
    public function getNodeType(): string
    {
        return Class_::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $className = null !== $node->name ? $node->name->name : 'anonymous';

        foreach ($node->implements as $interface) {
            $resolved = $scope->resolveName($interface);

            if ('Serializable' === $resolved) {
                return [
                    RuleErrorBuilder::message(
                        \sprintf(
                            'Class %s implements the deprecated Serializable interface (PHP 8.1). '
                            . 'Use __serialize() and its counterpart magic methods instead. '
                            . 'Remove "implements Serializable" and the legacy serialization methods.',
                            $className,
                        ),
                    )->identifier('phpqaci.deprecatedSerializable')->build(),
                ];
            }
        }

        return [];
    }
}
