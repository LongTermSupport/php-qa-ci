<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids createStub() and createMock() on final classes in PHPUnit tests.
 *
 * PHPUnit 13 throws ClassIsFinalException when you try to stub or mock a final class.
 * Even in older PHPUnit versions, mocking final classes is fragile and violates SOLID.
 *
 * Only flags classes whose source file lives within the project (not in vendor/).
 * Third-party final classes (e.g. Symfony's Security) are outside your control and
 * cannot have interfaces added — those are silently skipped.
 *
 * THE FIX: Create an interface for the class and stub/mock the interface instead.
 * The concrete (final) class implements the interface. Services type-hint the interface.
 * Tests stub/mock the interface. This is proper dependency inversion (SOLID D).
 *
 * @implements Rule<MethodCall>
 */
final class ForbidMockingFinalClassRule implements Rule
{
    /** @var list<string> */
    private const MOCK_METHODS = ['createStub', 'createMock'];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @param MethodCall $node
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Identifier) {
            return [];
        }

        $methodName = $node->name->toString();
        if (!\in_array($methodName, self::MOCK_METHODS, true)) {
            return [];
        }

        if ([] === $node->args) {
            return [];
        }

        $firstArg = $node->args[0];
        if (!$firstArg instanceof Node\Arg) {
            return [];
        }

        if (!$firstArg->value instanceof Node\Expr\ClassConstFetch) {
            return [];
        }

        $classConstFetch = $firstArg->value;
        if (!$classConstFetch->name instanceof Node\Identifier
            || 'class' !== $classConstFetch->name->toString()) {
            return [];
        }

        if (!$classConstFetch->class instanceof Node\Name) {
            return [];
        }

        $className = $scope->resolveName($classConstFetch->class);

        if (!$this->reflectionProvider->hasClass($className)) {
            return [];
        }

        $classReflection = $this->reflectionProvider->getClass($className);

        // Interfaces and abstract classes are fine to mock — that's the whole point
        if ($classReflection->isInterface() || $classReflection->isAbstract()) {
            return [];
        }

        if (!$classReflection->isFinal()) {
            return [];
        }

        // Skip third-party (vendor) final classes — we can't add interfaces to those.
        // Only flag classes whose source file is within the project, not in vendor/.
        $fileName = $classReflection->getFileName();
        if (null === $fileName || str_contains($fileName, '/vendor/')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                \sprintf(
                    'Do not %s() final class %s. Create or find an interface for this class, then %s() the interface instead. '
                    . 'See: Dependency Inversion Principle (SOLID).',
                    $methodName,
                    $className,
                    $methodName,
                ),
            )
                ->identifier('phpqaci.mockFinalClass')
                ->build(),
        ];
    }
}
