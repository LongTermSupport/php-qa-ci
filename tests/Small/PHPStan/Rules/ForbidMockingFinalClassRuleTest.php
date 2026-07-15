<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidLooseComparisonRule;
use LTS\PHPQA\PHPStan\Rules\ForbidMockingFinalClassRule;
use LTS\PHPQA\PHPStan\Rules\VendoredCodeDetector;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * Drives the rule directly with a real ReflectionProvider (over real, already
 * autoloadable classes) and a mocked Scope. This keeps the rule's reliance on
 * ClassReflection (which is final and cannot be doubled) honest — the reflection
 * facts (final/abstract/interface/file location) come from real classes — while
 * avoiding any source fixture that the project's own analysis would have to
 * exclude.
 *
 * @internal
 *
 * @extends RuleTestCase<ForbidMockingFinalClassRule>
 */
#[CoversClass(ForbidMockingFinalClassRule::class)]
#[Medium]
#[AllowMockObjectsWithoutExpectations]
final class ForbidMockingFinalClassRuleTest extends RuleTestCase
{
    // A final class shipped by this project (source lives in src/, not vendor/).
    private const string PROJECT_FINAL = ForbidLooseComparisonRule::class;

    #[Test]
    public function getNodeTypeIsMethodCall(): void
    {
        self::assertSame(MethodCall::class, $this->getRule()->getNodeType());
    }

    #[Test]
    public function createMockOnAProjectFinalClassIsFlagged(): void
    {
        $errors = $this->getRule()->processNode(
            $this->mockCall('createMock', self::PROJECT_FINAL),
            $this->scopeResolvingTo(self::PROJECT_FINAL),
        );

        self::assertCount(1, $errors);
        self::assertStringContainsString('Do not createMock() final class ' . self::PROJECT_FINAL, $errors[0]->getMessage());
        self::assertSame(ForbidMockingFinalClassRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function createStubOnAProjectFinalClassIsFlagged(): void
    {
        $errors = $this->getRule()->processNode(
            $this->mockCall('createStub', self::PROJECT_FINAL),
            $this->scopeResolvingTo(self::PROJECT_FINAL),
        );

        self::assertCount(1, $errors);
        self::assertStringContainsString('Do not createStub() final class', $errors[0]->getMessage());
    }

    #[Test]
    public function mockingAnInterfaceIsAllowed(): void
    {
        $errors = $this->getRule()->processNode(
            $this->mockCall('createMock', Rule::class),
            $this->scopeResolvingTo(Rule::class),
        );

        self::assertSame([], $errors);
    }

    #[Test]
    public function mockingAnAbstractClassIsAllowed(): void
    {
        $errors = $this->getRule()->processNode(
            $this->mockCall('createMock', \PhpParser\NodeAbstract::class),
            $this->scopeResolvingTo(\PhpParser\NodeAbstract::class),
        );

        self::assertSame([], $errors);
    }

    #[Test]
    public function mockingANonFinalClassIsAllowed(): void
    {
        $errors = $this->getRule()->processNode(
            $this->mockCall('createMock', \PhpParser\Comment::class),
            $this->scopeResolvingTo(\PhpParser\Comment::class),
        );

        self::assertSame([], $errors);
    }

    #[Test]
    public function mockingAThirdPartyFinalClassInVendorIsAllowed(): void
    {
        // Small is final, but its source lives under vendor/ — we cannot add an
        // interface to a third-party class, so the rule deliberately skips it.
        $errors = $this->getRule()->processNode(
            $this->mockCall('createMock', \PHPUnit\Framework\Attributes\Small::class),
            $this->scopeResolvingTo(\PHPUnit\Framework\Attributes\Small::class),
        );

        self::assertSame([], $errors);
    }

    #[Test]
    public function unknownClassIsSkipped(): void
    {
        $errors = $this->getRule()->processNode(
            $this->mockCall('createMock', 'Acme\DefinitelyNotAReal\Clazz'),
            $this->scopeResolvingTo('Acme\DefinitelyNotAReal\Clazz'),
        );

        self::assertSame([], $errors);
    }

    #[Test]
    public function nonMockMethodIsIgnored(): void
    {
        $errors = $this->getRule()->processNode(
            $this->mockCall('doSomethingElse', self::PROJECT_FINAL),
            $this->scopeResolvingTo(self::PROJECT_FINAL),
        );

        self::assertSame([], $errors);
    }

    #[Test]
    public function dynamicFirstArgumentIsIgnored(): void
    {
        // $this->createMock($var) — the argument is not a ::class fetch.
        $call = new MethodCall(new Variable('this'), new Identifier('createMock'), [new Arg(new Variable('type'))]);

        self::assertSame([], $this->getRule()->processNode($call, $this->scopeResolvingTo(self::PROJECT_FINAL)));
    }

    #[Test]
    public function classConstantOtherThanClassIsIgnored(): void
    {
        // $this->createMock(Foo::SOME_CONST) — not the ::class magic constant.
        $fetch = new ClassConstFetch(new Name(self::PROJECT_FINAL), new Identifier('SOME_CONST'));
        $call  = new MethodCall(new Variable('this'), new Identifier('createMock'), [new Arg($fetch)]);

        self::assertSame([], $this->getRule()->processNode($call, $this->scopeResolvingTo(self::PROJECT_FINAL)));
    }

    #[Test]
    public function aProjectOwnedFinalClassWhoseAbsolutePathContainsVendorIsStillFlagged(): void
    {
        // Regression: when the project root itself lives under a /vendor/ path
        // (developing a package in-place inside a consumer's vendor/, the normal
        // way php-qa-ci is worked on), a project-owned class's absolute path
        // contains '/vendor/' yet the class MUST still be flagged. Anchoring on
        // '<cwd>/vendor/' (here an unrelated root, so the class is NOT under it)
        // rather than a bare '/vendor/' substring is exactly what fixes this.
        $rule = new ForbidMockingFinalClassRule(
            $this->createReflectionProvider(),
            new VendoredCodeDetector('/nonexistent/project/root'),
        );

        $errors = $rule->processNode(
            $this->mockCall('createMock', self::PROJECT_FINAL),
            $this->scopeResolvingTo(self::PROJECT_FINAL),
        );

        self::assertCount(1, $errors);
    }

    protected function getRule(): Rule
    {
        return new ForbidMockingFinalClassRule(
            $this->createReflectionProvider(),
            new VendoredCodeDetector(\dirname(__DIR__, 4)),
        );
    }

    private function mockCall(string $method, string $className): MethodCall
    {
        $fetch = new ClassConstFetch(new Name('\\' . $className), new Identifier('class'));

        return new MethodCall(new Variable('this'), new Identifier($method), [new Arg($fetch)]);
    }

    private function scopeResolvingTo(string $className): CollectedDataEmitter&NodeCallbackInvoker&Scope
    {
        $scope = $this->createMockForIntersectionOfInterfaces([CollectedDataEmitter::class, NodeCallbackInvoker::class, Scope::class]);
        $scope->method('resolveName')->willReturn($className);

        return $scope;
    }
}
