<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Symfony-specific rule enforcing explicit dependency injection declaration for all classes.
 *
 * THE PROBLEM THIS SOLVES:
 * ========================
 * In Symfony projects using the common configuration pattern:
 *
 *     services:
 *         App\:
 *             resource: '../src/'
 *
 * EVERY class in src/ is automatically registered as a service in the DI container.
 * This causes several serious issues:
 *
 * 1. MEMORY BLOAT: DTOs, value objects, exceptions, and entities are unnecessarily
 *    instantiated and stored in the container, wasting memory.
 *
 * 2. PERFORMANCE DEGRADATION: The container must process and manage hundreds of
 *    classes that should never be services, slowing down compilation and runtime.
 *
 * 3. ARCHITECTURAL VIOLATIONS: Domain objects (DTOs, entities, value objects) should
 *    be created with specific data, not injected as services. Having them in the
 *    container violates Domain-Driven Design principles.
 *
 * 4. CONFUSION AND BUGS: Developers may accidentally inject DTOs or exceptions as
 *    dependencies, leading to subtle bugs and architectural decay.
 *
 * 5. HIDDEN DEPENDENCIES: Without explicit declaration, it's unclear which classes
 *    are meant to be services vs domain objects, making the codebase harder to understand.
 *
 * THE SOLUTION:
 * =============
 * This rule REQUIRES every concrete class to explicitly declare its DI status:
 *
 * - #[Autoconfigure] - "YES, this is a service, register it in the container"
 * - #[Exclude] - "NO, this is NOT a service, keep it OUT of the container"
 *
 * Benefits:
 * - Self-documenting code: DI intent is visible at the class level
 * - Prevents accidental service registration of domain objects
 * - Enforces architectural boundaries between services and domain objects
 * - Reduces container size and improves performance
 * - Makes dependency injection errors visible at compile time via PHPStan
 *
 * USAGE:
 * ======
 * Services (use #[Autoconfigure]):
 * - Controllers, Commands, Event Listeners
 * - Repositories, Factories, Builders
 * - Services, Managers, Handlers
 * - Adapters, Transformers, Normalizers
 *
 * Non-Services (use #[Exclude]):
 * - DTOs (Data Transfer Objects)
 * - Entities, Models, Domain Objects
 * - Value Objects, Collections
 * - Exceptions, Events, Messages
 * - Enums, Constants classes
 *
 * Example:
 *     #[Exclude]
 *     final readonly class UserDTO { }  // DTO should NOT be in container
 *
 *     #[Autoconfigure]
 *     final class UserService { }       // Service SHOULD be in container
 *
 * This rule works in conjunction with excluding patterns in services.yaml:
 *     services:
 *         App\:
 *             resource: '../src/'
 *             exclude:
 *                 - '../DTO/'
 *
 * @implements Rule<Node\Stmt\Class_>
 */
final class RequireExplicitDIAttributeRule implements Rule
{
    public const string IDENTIFIER_REQUIRE_EXPLICIT_DI_ATTRIBUTE = RuleIdentifierInterface::PREFIX . '.requireExplicitDIAttribute';
    public const string IDENTIFIER_CONFLICTING_DI_ATTRIBUTES     = RuleIdentifierInterface::PREFIX . '.conflictingDIAttributes';

    private const array ALLOWED_NAMESPACES_WITHOUT_ATTRIBUTE = [
        'PHPStan',
        'Tests',
        'Migrations',
    ];

    public function getNodeType(): string
    {
        return Node\Stmt\Class_::class;
    }

    /**
     * @param Node\Stmt\Class_ $node
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // Skip abstract classes and anonymous classes
        if ($node->isAbstract() || null === $node->name) {
            return [];
        }

        $className = $scope->getNamespace() . '\\' . $node->name->toString();

        // Skip test classes and PHPStan rules
        foreach (self::ALLOWED_NAMESPACES_WITHOUT_ATTRIBUTE as $namespace) {
            if (str_contains($className, $namespace)) {
                return [];
            }
        }

        // Skip Kernel.php
        if (str_ends_with($className, 'Kernel')) {
            return [];
        }

        $hasAutoconfigure    = false;
        $hasExclude          = false;
        $hasAsCommand        = false;
        $hasAutoConfigureTag = false;

        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $name = $attr->name->toString();

                // Check for full namespace or just class name
                if ('Autoconfigure'         === $name
                    || Autoconfigure::class === $name
                    || str_ends_with($name, '\Autoconfigure')) {
                    $hasAutoconfigure = true;
                }

                if ('Exclude'         === $name
                    || Exclude::class === $name
                    || str_ends_with($name, '\Exclude')) {
                    $hasExclude = true;
                }

                // AsCommand implies it's a service
                if (str_ends_with($name, '\AsCommand')) {
                    $hasAsCommand = true;
                }

                // AutoconfigureTag implies it's a service
                if (str_ends_with($name, '\AutoconfigureTag')) {
                    $hasAutoConfigureTag = true;
                }
            }
        }

        // AsCommand and AutoconfigureTag count as explicit service declaration
        $hasServiceDeclaration = $hasAutoconfigure || $hasAsCommand || $hasAutoConfigureTag;

        if (!$hasServiceDeclaration && !$hasExclude) {
            $shortName = $node->name->toString();

            // Provide helpful hints based on class name patterns
            $hint = $this->getHintForClass($shortName);

            return [
                RuleErrorBuilder::message(
                    \sprintf(
                        'Class %s must explicitly declare DI status with either #[Autoconfigure] (for services) or #[Exclude] (for DTOs, entities, value objects). %s',
                        $shortName,
                        $hint
                    )
                )->identifier(self::IDENTIFIER_REQUIRE_EXPLICIT_DI_ATTRIBUTE)->build(),
            ];
        }

        if (($hasAutoconfigure || $hasAutoConfigureTag) && $hasExclude) {
            return [
                RuleErrorBuilder::message(
                    \sprintf(
                        'Class %s cannot have both service registration (#[Autoconfigure]/#[AutoconfigureTag]) and #[Exclude] attributes',
                        $node->name->toString()
                    )
                )->identifier(self::IDENTIFIER_CONFLICTING_DI_ATTRIBUTES)->build(),
            ];
        }

        return [];
    }

    /**
     * Provides contextual hints based on class naming patterns to help developers
     * choose the correct attribute.
     *
     * This method analyzes the class name and suggests whether it should be a service
     * (use #[Autoconfigure]) or excluded from DI (use #[Exclude]).
     *
     * The suggestions are based on common Symfony naming conventions:
     * - Classes ending in 'Service', 'Controller', 'Factory' etc. are typically services
     * - Classes ending in 'DTO', 'Exception', or containing 'Entity' are typically NOT services
     * - Value objects, domain models, and data containers should be excluded
     *
     * @param string $className The short name of the class being analyzed
     *
     * @return string A helpful hint message suggesting which attribute to use
     */
    private function getHintForClass(string $className): string
    {
        // DTOs and Value Objects - These hold data and should NOT be services
        // They are instantiated with specific data, not injected
        if (str_ends_with($className, 'DTO')
            || str_ends_with($className, 'Message')
            || str_contains($className, 'Validated')
            || str_ends_with($className, 'Collection')) {
            return 'This appears to be a DTO/value object - use #[Exclude]. DTOs should not be in the DI container.';
        }

        // Services - These perform actions and SHOULD be in the container
        // They are stateless and can be injected as dependencies
        if (str_ends_with($className, 'Service')
            || str_ends_with($className, 'Factory')
            || str_ends_with($className, 'Repository')
            || str_ends_with($className, 'Controller')
            || str_ends_with($className, 'Handler')
            || str_ends_with($className, 'Listener')
            || str_ends_with($className, 'Subscriber')) {
            return 'This appears to be a service - use #[Autoconfigure] to register it in the DI container.';
        }

        // Exceptions - These represent error states and should NEVER be services
        // They are thrown, not injected
        if (str_ends_with($className, 'Exception')) {
            return 'Exceptions should use #[Exclude]. Exceptions are thrown, not injected as services.';
        }

        // Commands (console commands) - Special case
        // If they have #[AsCommand], they're automatically services
        if (str_ends_with($className, 'Command')) {
            return 'Console commands with #[AsCommand] are automatically services. Otherwise, use #[Autoconfigure].';
        }

        // Default message when pattern doesn't match known conventions
        return 'Determine if this should be a service (#[Autoconfigure]) or not (#[Exclude]). ' .
               'Services are injected, domain objects are instantiated with data.';
    }
}
