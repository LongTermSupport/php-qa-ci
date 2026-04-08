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
 * Skips generated code (paths containing /Generated/) and vendor code.
 *
 * @implements Rule<ClassLike>
 */
final class RequireTypeSuffixRule implements Rule
{
    /**
     * Absolute path substrings to exclude (match anywhere in the absolute file path).
     *
     * @var list<string>
     */
    private const array EXCLUDED_ABSOLUTE_SEGMENTS = ['/Generated/'];

    /**
     * Relative path prefixes to exclude (relative to getcwd()).
     *
     * Using a relative prefix avoids false-exclusions when the project itself
     * is installed inside a parent vendor/ directory (which would make every
     * absolute path contain "/vendor/").
     *
     * @var list<string>
     */
    private const array EXCLUDED_RELATIVE_PREFIXES = ['vendor/'];

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
        if (null === $node->name) {
            return [];
        }

        $fileName = $scope->getFile();
        foreach (self::EXCLUDED_ABSOLUTE_SEGMENTS as $segment) {
            if (str_contains($fileName, $segment)) {
                return [];
            }
        }
        $cwd = getcwd();
        if (false !== $cwd && str_starts_with($fileName, $cwd . '/')) {
            $relative = substr($fileName, \strlen($cwd) + 1);
            foreach (self::EXCLUDED_RELATIVE_PREFIXES as $prefix) {
                if (str_starts_with($relative, $prefix)) {
                    return [];
                }
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
