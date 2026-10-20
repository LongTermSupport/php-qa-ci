<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\Cast\String_ as StringCast;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\ObjectType;
use ReflectionClass;

/**
 * Collects each local variable assigned a class or namespace name, for
 * ForbidUnanchoredNamespaceSubstringCheckRule to follow a substring check made on the variable.
 *
 * A string PHPStan types only as `string` carries no record of where it came from, so a class
 * name is read where the code takes one ({@see self::isClassName()}): a `class-string`
 * (`::class`, `get_class()`, a parameter so typed), the analysed scope's namespace, a reflected
 * class's name or namespace, a parsed node's `namespacedName`, or a concatenation that starts with
 * one of those, as `$scope->getNamespace() . '\\' . $name` does. Only a plain assignment to a
 * named variable is read.
 *
 * Each value is [function key, variable name].
 *
 * @internal
 *
 * @implements Collector<Assign, array{string, string}>
 */
final readonly class ClassNameVariableWriteCollector implements Collector
{
    /** @var array<class-string, list<string>> each type => its methods that return a class or namespace name, lower case */
    private const array NAME_METHODS = [
        Scope::class            => ['getnamespace'],
        ClassReflection::class  => ['getname', 'getdisplayname'],
        ReflectionClass::class  => ['getname', 'getnamespacename'],
    ];

    /** @var list<string> the methods of a PhpParser Name that return it as a string, lower case */
    private const array NAME_TO_STRING = ['tostring', 'tocodestring'];

    /** @var string the property PhpParser's name resolver sets on a declaration to its fully qualified name */
    private const string NAMESPACED_NAME = 'namespacedName';

    /** Variables are grouped by the function that holds them; a closure's share its enclosing function's. */
    public static function functionKey(Scope $scope): string
    {
        return \sprintf('%s::%s', $scope->getClassReflection()?->getName(), $scope->getFunctionName());
    }

    public static function isClassName(Expr $expr, Scope $scope): bool
    {
        if ($scope->getType($expr)->isClassString()->yes()) {
            return true;
        }

        return match (true) {
            $expr instanceof Concat                                                => self::isClassName($expr->left, $scope),
            $expr instanceof StringCast                                            => self::isClassName($expr->expr, $scope),
            $expr instanceof MethodCall, $expr instanceof NullsafeMethodCall       => self::isNameMethodCall($expr, $scope),
            $expr instanceof PropertyFetch, $expr instanceof NullsafePropertyFetch => self::isNamespacedName($expr),
            default                                                                => false,
        };
    }

    public function getNodeType(): string
    {
        return Assign::class;
    }

    /**
     * @return array{string, string}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (!$node->var instanceof Variable || !\is_string($node->var->name)) {
            return null;
        }

        return self::isClassName($node->expr, $scope) ? [self::functionKey($scope), $node->var->name] : null;
    }

    private static function isNameMethodCall(MethodCall|NullsafeMethodCall $call, Scope $scope): bool
    {
        if (!$call->name instanceof Identifier) {
            return false;
        }

        $method = $call->name->toLowerString();
        if (\in_array($method, self::NAME_TO_STRING, true)
            && ($call->var instanceof PropertyFetch || $call->var instanceof NullsafePropertyFetch)) {
            return self::isNamespacedName($call->var);
        }

        $receiver = $scope->getType($call->var);

        return array_any(self::NAME_METHODS, static fn (array $methods, $class): bool => \in_array($method, $methods, true) && new ObjectType($class)->isSuperTypeOf($receiver)->yes());
    }

    private static function isNamespacedName(PropertyFetch|NullsafePropertyFetch $fetch): bool
    {
        return $fetch->name instanceof Identifier && self::NAMESPACED_NAME === $fetch->name->toString();
    }
}
