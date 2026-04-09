<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Enforces naming conventions for interfaces, enums, and traits:
 *   - Interfaces must end with "Interface"
 *   - Enums must end with "Enum" (unless backed enum used as value object)
 *   - Traits must end with "Trait"
 *
 * Skips generated code (paths containing /Generated/). Vendor code is not
 * excluded here because PHPStan's analysis paths never include vendor/ in
 * any sane configuration — vendor files are never passed to this rule.
 *
 * @implements Rule<ClassLike>
 */
final class RequireTypeSuffixRule implements Rule
{
    /**
     * Path substrings that cause a file to be skipped (matched against the absolute path).
     *
     * @var list<string>
     */
    private const array EXCLUDED_PATH_SEGMENTS = ['/Generated/'];

    public function getNodeType(): string
    {
        return ClassLike::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // Only check interfaces, enums, and traits
        if (!$node instanceof Interface_ && !$node instanceof Enum_ && !$node instanceof Trait_) {
            return [];
        }

        // Anonymous classes/interfaces have no name
        if (!$node->name instanceof Node\Identifier) {
            return [];
        }

        $fileName = $scope->getFile();
        foreach (self::EXCLUDED_PATH_SEGMENTS as $segment) {
            if (str_contains($fileName, $segment)) {
                return [];
            }
        }

        $name = $node->name->toString();

        if ($node instanceof Interface_ && !str_ends_with($name, 'Interface')) {
            return [
                RuleErrorBuilder::message(
                    \sprintf(
                        'Interface "%s" must be suffixed with "Interface" (e.g. "%sInterface").',
                        $name,
                        $name,
                    ),
                )
                    ->identifier('phpqaci.interfaceSuffix')
                    ->build(),
            ];
        }

        if ($node instanceof Enum_ && !str_ends_with($name, 'Enum')) {
            return [
                RuleErrorBuilder::message(
                    \sprintf(
                        'Enum "%s" must be suffixed with "Enum" (e.g. "%sEnum").',
                        $name,
                        $name,
                    ),
                )
                    ->identifier('phpqaci.enumSuffix')
                    ->build(),
            ];
        }

        if ($node instanceof Trait_ && !str_ends_with($name, 'Trait')) {
            return [
                RuleErrorBuilder::message(
                    \sprintf(
                        'Trait "%s" must be suffixed with "Trait" (e.g. "%sTrait").',
                        $name,
                        $name,
                    ),
                )
                    ->identifier('phpqaci.traitSuffix')
                    ->build(),
            ];
        }

        return [];
    }
}
