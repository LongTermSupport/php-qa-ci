<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\UnanchoredNamespaceSubstringCheck;

use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;

final class NotReportable
{
    /** The correct construction: whole segments compared. */
    public function wholeSegment(ClassReflection $class): bool
    {
        return \in_array('Generated', explode('\\', $class->getName()), true);
    }

    /** A needle bounded by separators is a segment, not a bare word. */
    public function boundedNeedle(ClassReflection $class): bool
    {
        return str_contains($class->getName() . '\\', '\Generated\\');
    }

    /** Anchored at one end: not an anywhere-in-the-name match. */
    public function anchored(ClassReflection $class): bool
    {
        return str_ends_with($class->getName(), 'Kernel') || str_starts_with($class->getName(), 'App');
    }

    /** A plain string the code does not derive from a class name here. */
    public function plainString(string $className, string $message): bool
    {
        return str_contains($className, 'Validated') || str_contains($message, 'Generated');
    }

    /** The word Tests is phpqaci.unanchoredTestsSubstringCheck's, and reported there. */
    public function testsWord(ClassReflection $class): bool
    {
        return str_contains($class->getName(), 'Tests');
    }

    /** Not a namespace-segment word: lower case, or holding a separator or a path. */
    public function notAWord(ClassReflection $class): bool
    {
        return str_contains($class->getName(), 'generated') || str_contains($class->getName(), '\\') || str_contains($class->getName(), 'src/');
    }

    public function assignsAName(Scope $scope): string
    {
        $className = (string) $scope->getNamespace();

        return $className;
    }

    /** The same variable name in another method holds what that method assigns, not a class name. */
    public function sameVariableNameElsewhere(string $className): bool
    {
        return str_contains($className, 'Generated');
    }

    /** A variable no write to which is a class name. */
    public function variableFromElsewhere(string $path): bool
    {
        $subject = $path . '/src';

        return str_contains($subject, 'Generated');
    }
}
