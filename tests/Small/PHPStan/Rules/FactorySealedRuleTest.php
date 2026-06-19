<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\FactorySealedRule;
use LTS\PHPQA\Tests\Assets\PHPStan\FactorySealed\SealedByPackageAttribute;
use LTS\PHPQA\Tests\Assets\PHPStan\FactorySealed\UnsealedClass;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the rule's glue (AST facts → reader → detector → error) with a
 * mocked Scope + ReflectionProvider. Mocking the Scope lets us control the
 * analysed file path directly, so a sealed construction can be proven to flag
 * outside the authorised factory without the fixture's real `tests/` path
 * triggering the detector's test-exemption.
 *
 * @internal
 */
#[CoversClass(FactorySealedRule::class)]
#[Small]
final class FactorySealedRuleTest extends TestCase
{
    public function testGetNodeType(): void
    {
        self::assertSame(New_::class, $this->rule()->getNodeType());
    }

    public function testItFlagsConstructionOfASealedTypeOutsideTheAuthorisedFactory(): void
    {
        $errors = $this->rule()->processNode(
            new New_(new Name('SealedByPackageAttribute')),
            $this->scope(
                resolvesTo: SealedByPackageAttribute::class,
                file: '/project/src/Consumer.php',
                enclosingClass: null,
            ),
        );

        self::assertCount(1, $errors);
        self::assertStringContainsString('factory-sealed', $errors[0]->getMessage());
        self::assertStringContainsString('Acme\Widget\WidgetFactory', $errors[0]->getMessage());
    }

    public function testItDoesNotFlagConstructionInsideATestFile(): void
    {
        // The detector exempts any path containing /tests/ before it even
        // compares the enclosing class, so this holds regardless of where the
        // `new` sits. (The enclosing-class-IS-the-factory exemption is covered
        // purely in FactorySealedDetectorTest; PHPStan's ClassReflection is final
        // and cannot be doubled, so the rule-level test stays on the file-path
        // and unsealed paths.)
        $errors = $this->rule()->processNode(
            new New_(new Name('SealedByPackageAttribute')),
            $this->scope(
                resolvesTo: SealedByPackageAttribute::class,
                file: '/project/tests/ConsumerTest.php',
                enclosingClass: null,
            ),
        );

        self::assertSame([], $errors);
    }

    public function testItDoesNotFlagConstructionOfAnUnsealedType(): void
    {
        $errors = $this->rule()->processNode(
            new New_(new Name('UnsealedClass')),
            $this->scope(
                resolvesTo: UnsealedClass::class,
                file: '/project/src/Consumer.php',
                enclosingClass: null,
            ),
        );

        self::assertSame([], $errors);
    }

    public function testItSkipsDynamicConstruction(): void
    {
        // new $var — the class is not statically known, so it cannot be checked.
        $errors = $this->rule()->processNode(
            new New_(new Variable('class')),
            $this->scope(resolvesTo: SealedByPackageAttribute::class, file: '/project/src/Consumer.php', enclosingClass: null),
        );

        self::assertSame([], $errors);
    }

    private function rule(): FactorySealedRule
    {
        $reflectionProvider = $this->createMock(ReflectionProvider::class);
        $reflectionProvider->method('hasClass')->willReturn(true);

        return new FactorySealedRule($reflectionProvider);
    }

    /**
     * Builds a Scope test double. `enclosingClass` is null in every call site
     * because PHPStan's ClassReflection is final (so the non-null branch cannot
     * be doubled); the enclosing-class-equals-factory exemption is asserted in
     * FactorySealedDetectorTest instead.
     *
     * @param class-string $resolvesTo
     *
     * @return CollectedDataEmitter&NodeCallbackInvoker&Scope
     */
    private function scope(string $resolvesTo, string $file, ?string $enclosingClass): Scope
    {
        self::assertNull($enclosingClass, 'rule-level tests use a null enclosing class (ClassReflection is final)');

        $scope = $this->createMockForIntersectionOfInterfaces([CollectedDataEmitter::class, NodeCallbackInvoker::class, Scope::class]);
        $scope->method('resolveName')->willReturn($resolvesTo);
        $scope->method('getFile')->willReturn($file);
        $scope->method('getClassReflection')->willReturn(null);

        return $scope;
    }
}
