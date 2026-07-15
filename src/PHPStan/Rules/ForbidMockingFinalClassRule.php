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
 * Only flags classes the analysed project OWNS — i.e. whose source file lives
 * within the project but NOT under the project's own vendor/ directory.
 * Third-party final classes (e.g. Symfony's Security) are outside your control and
 * cannot have interfaces added — those are silently skipped.
 *
 * "Third-party" is decided by {@see VendoredCodeDetector} against the analysed
 * project's OWN vendor directory, NOT a bare `/vendor/` substring of the
 * absolute path — see that class for why the substring test is wrong when a
 * package is developed in-place inside a consumer's vendor/.
 *
 * THE FIX: Create an interface for the class and stub/mock the interface instead.
 * The concrete (final) class implements the interface. Services type-hint the interface.
 * Tests stub/mock the interface. This is proper dependency inversion (SOLID D).
 *
 * @implements Rule<MethodCall>
 */
final readonly class ForbidMockingFinalClassRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.mockFinalClass';

    /** @var list<string> */
    private const array MOCK_METHODS = ['createStub', 'createMock'];

    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private VendoredCodeDetector $vendoredCodeDetector,
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

        // Skip third-party (vendored) final classes — we can't add interfaces to
        // those. Only flag classes the analysed project owns (see VendoredCodeDetector).
        if ($this->vendoredCodeDetector->isVendoredCode($classReflection->getFileName())) {
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
                ->identifier(self::IDENTIFIER)
                ->build(),
        ];
    }
}
