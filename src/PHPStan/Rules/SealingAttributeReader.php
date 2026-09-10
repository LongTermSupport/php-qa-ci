<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use ReflectionClass;

/**
 * Resolves the authorised factory FQCN for a factory-sealed class by reading its
 * sealing attribute via native reflection.
 *
 * Extracted from {@see FactorySealedRule} so the reflection logic can be
 * unit-tested against real fixture classes, independently of PHPStan's Scope.
 *
 * The factory is read from the attribute's FIRST ARGUMENT (positional 0, or the
 * named `factory` argument) via {@see \ReflectionAttribute::getArguments()} — the
 * attribute is never instantiated. This means the rule recognises ANY sealing
 * attribute whose first argument is the factory class-string, so a project may
 * define its own attribute in its production namespace rather than depending on
 * the package's reference {@see \LTS\PHPQA\PHPStan\Attribute\FactorySealedBy}.
 */
final readonly class SealingAttributeReader
{
    /**
     * @param string       $className            the class that may be sealed (skipped if it does not resolve to
     *                                           a loadable class)
     * @param class-string ...$sealingAttributes attribute FQCNs that mark a class as sealed
     *
     * @return string|null the authorised factory FQCN as written in the attribute's first argument,
     *                     or null when the class carries none of the configured attributes
     */
    public function factoryFor(string $className, string ...$sealingAttributes): ?string
    {
        if (!class_exists($className)) {
            return null;
        }

        $reflection = new ReflectionClass($className);

        foreach ($sealingAttributes as $sealingAttribute) {
            $attributes = $reflection->getAttributes($sealingAttribute);

            if ([] === $attributes) {
                continue;
            }

            $arguments = $attributes[0]->getArguments();
            $factory   = $arguments[0] ?? $arguments['factory'] ?? null;

            if (\is_string($factory) && '' !== $factory) {
                return $factory;
            }
        }

        return null;
    }
}
