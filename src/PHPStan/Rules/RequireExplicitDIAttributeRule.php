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
 * Enforces explicit dependency injection declaration for all classes.
 * 
 * Every concrete class must have either:
 * - #[Autoconfigure] to be registered as a service
 * - #[Exclude] to be excluded from DI container
 * 
 * This prevents accidental service registration of DTOs, value objects, etc.
 * 
 * @implements Rule<Node\Stmt\Class_>
 */
final class RequireExplicitDIAttributeRule implements Rule
{
    private const ALLOWED_NAMESPACES_WITHOUT_ATTRIBUTE = [
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
        if ($node->isAbstract() || $node->name === null) {
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

        $hasAutoconfigure = false;
        $hasExclude = false;
        $hasAsCommand = false;
        $hasAutoConfigureTag = false;

        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $name = $attr->name->toString();
                
                // Check for full namespace or just class name
                if ($name === 'Autoconfigure' || 
                    $name === Autoconfigure::class ||
                    str_ends_with($name, '\\Autoconfigure')) {
                    $hasAutoconfigure = true;
                }
                
                if ($name === 'Exclude' || 
                    $name === Exclude::class ||
                    str_ends_with($name, '\\Exclude')) {
                    $hasExclude = true;
                }
                
                // AsCommand implies it's a service
                if (str_ends_with($name, '\\AsCommand')) {
                    $hasAsCommand = true;
                }
                
                // AutoconfigureTag implies it's a service
                if (str_ends_with($name, '\\AutoconfigureTag')) {
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
                    sprintf(
                        'Class %s must explicitly declare DI status with either #[Autoconfigure] (for services) or #[Exclude] (for DTOs, entities, value objects). %s',
                        $shortName,
                        $hint
                    )
                )->build(),
            ];
        }

        if (($hasAutoconfigure || $hasAutoConfigureTag) && $hasExclude) {
            return [
                RuleErrorBuilder::message(
                    sprintf(
                        'Class %s cannot have both service registration (#[Autoconfigure]/#[AutoconfigureTag]) and #[Exclude] attributes',
                        $node->name->toString()
                    )
                )->build(),
            ];
        }

        return [];
    }

    private function getHintForClass(string $className): string
    {
        // DTOs and Value Objects
        if (str_ends_with($className, 'DTO') || 
            str_ends_with($className, 'Message') ||
            str_contains($className, 'Validated') ||
            str_ends_with($className, 'Collection')) {
            return 'This appears to be a DTO/value object - use #[Exclude].';
        }

        // Services
        if (str_ends_with($className, 'Service') ||
            str_ends_with($className, 'Factory') ||
            str_ends_with($className, 'Repository') ||
            str_ends_with($className, 'Controller') ||
            str_ends_with($className, 'Handler') ||
            str_ends_with($className, 'Listener') ||
            str_ends_with($className, 'Subscriber')) {
            return 'This appears to be a service - use #[Autoconfigure].';
        }

        // Exceptions
        if (str_ends_with($className, 'Exception')) {
            return 'Exceptions should use #[Exclude].';
        }

        // Commands (console commands)
        if (str_ends_with($className, 'Command')) {
            return 'Console commands with #[AsCommand] are automatically services.';
        }

        return 'Determine if this should be a service (#[Autoconfigure]) or not (#[Exclude]).';
    }
}