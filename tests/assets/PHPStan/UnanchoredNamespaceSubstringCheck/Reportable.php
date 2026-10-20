<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\UnanchoredNamespaceSubstringCheck;

use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;

final class Reportable
{
    private const array SKIPPED = ['PHPStan', 'Migrations', 'Generated'];

    /** The shape of RequireExplicitDIAttributeRule: a name built from the scope, a word from a constant list. */
    public function builtFromTheScope(Scope $scope, Class_ $node): bool
    {
        $className = $scope->getNamespace() . '\\' . $node->name?->toString();
        foreach (self::SKIPPED as $namespace) {
            if (str_contains($className, $namespace)) {
                return true;
            }
        }

        return false;
    }

    public function reflectedName(ClassReflection $class): bool
    {
        return str_contains($class->getName(), 'Generated');
    }

    public function classConstant(object $object): bool
    {
        return false !== strpos($object::class, 'Migrations');
    }

    /** @param class-string $class */
    public function classStringParameter(string $class): bool
    {
        return false !== stripos($class, 'PHPStan');
    }

    public function namespacedName(Class_ $node): bool
    {
        return str_contains((string) $node->namespacedName?->toString(), 'Generated');
    }

    public function reflectionNamespace(\ReflectionClass $class): bool
    {
        return \str_contains($class->getNamespaceName(), 'Migrations');
    }

    public function assignedInAClosure(Scope $scope): \Closure
    {
        $namespace = (string) $scope->getNamespace();

        return static fn (): bool => str_contains($namespace, 'Generated');
    }
}
