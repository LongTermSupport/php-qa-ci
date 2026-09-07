<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidMagicStringAssertionRule;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ForbidMagicStringAssertionRule::class)]
#[Small]
final class ForbidMagicStringAssertionRuleTest extends TestCase
{
    use ScopeStubTrait;

    private ForbidMagicStringAssertionRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ForbidMagicStringAssertionRule();
    }

    #[Test]
    public function getNodeTypeIsCallLike(): void
    {
        self::assertSame(CallLike::class, $this->rule->getNodeType());
    }

    #[Test]
    public function identifierLikeLiteralAssertedAgainstAGeneralStringIsFlagged(): void
    {
        // self::assertSame('desk', $x) where $x is a plain string (not yet an enum)
        $call  = $this->staticAssert('assertSame', new String_('desk'), new Variable('x'));
        $scope = $this->scopeReturning(new StringType());

        self::assertCount(1, $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function thisStyleAssertionIsAlsoFlagged(): void
    {
        // $this->assertSame('active', $x)
        $call  = new MethodCall(new Variable('this'), new Identifier('assertSame'), [
            new Arg(new String_('active')),
            new Arg(new Variable('x')),
        ]);
        $scope = $this->scopeReturning(new StringType());

        self::assertCount(1, $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function literalInTheActualPositionIsAlsoFlagged(): void
    {
        // self::assertSame($x, 'desk') — literal second, still a magic-string pin
        $call  = $this->staticAssert('assertSame', new Variable('x'), new String_('desk'));
        $scope = $this->scopeReturning(new StringType());

        self::assertCount(1, $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function assertEqualsIsAlsoCovered(): void
    {
        $call  = $this->staticAssert('assertEquals', new String_('pending'), new Variable('x'));
        $scope = $this->scopeReturning(new StringType());

        self::assertCount(1, $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function literalAgainstAlreadyConstantStringIsNotFlagged(): void
    {
        // The actual is already a constant-string type (e.g. an enum ->value or
        // const) — PHPStan's alreadyNarrowedType built-in handles this; the smell
        // is already fixed. Not our job → no error (no duplication of the built-in).
        $call  = $this->staticAssert('assertSame', new String_('desk'), new Variable('x'));
        $scope = $this->scopeReturning(new ConstantStringType('desk'));

        self::assertSame([], $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function freeTextLiteralIsNotFlagged(): void
    {
        // Not identifier-like (spaces/punctuation) — a legitimate string-output
        // assertion, not a closed-set magic value.
        $call  = $this->staticAssert('assertSame', new String_('Hello, World!'), new Variable('x'));
        $scope = $this->scopeReturning(new StringType());

        self::assertSame([], $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function nonAssertionCallIsIgnored(): void
    {
        $call  = $this->staticAssert('doSomething', new String_('desk'), new Variable('x'));
        $scope = $this->scopeReturning(new StringType());

        self::assertSame([], $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function literalAgainstNonStringIsNotFlagged(): void
    {
        // Actual is an int — not a stringly-typed value; out of scope.
        $call  = $this->staticAssert('assertSame', new String_('desk'), new Variable('x'));
        $scope = $this->scopeReturning(new IntegerType());

        self::assertSame([], $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function bothOperandsLiteralIsNotOurConcern(): void
    {
        // literal == literal is itself always-narrowed; leave to the built-in.
        $call  = $this->staticAssert('assertSame', new String_('desk'), new String_('desk'));
        $scope = $this->scopeReturning(new ConstantStringType('desk'));

        self::assertSame([], $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function reflectionGetNameIsNotFlagged(): void
    {
        // self::assertSame('orgId', $param->getName()) — Reflection metadata accessor.
        $call  = $this->staticAssert(
            'assertSame',
            new String_('orgId'),
            new MethodCall(new Variable('param'), new Identifier('getName')),
        );
        $scope = $this->scopeReturning(new StringType());

        self::assertSame([], $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function psr7GetMethodIsNotFlagged(): void
    {
        // self::assertSame('POST', $request->getMethod()) — PSR-7 metadata accessor.
        $call  = $this->staticAssert(
            'assertSame',
            new String_('POST'),
            new MethodCall(new Variable('request'), new Identifier('getMethod')),
        );
        $scope = $this->scopeReturning(new StringType());

        self::assertSame([], $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function aPlainDomainMethodReturningStringStillFlags(): void
    {
        // self::assertSame('active', $repo->getStatus()) — not a metadata accessor;
        // a general string from domain code → still a smell.
        $call  = $this->staticAssert(
            'assertSame',
            new String_('active'),
            new MethodCall(new Variable('repo'), new Identifier('getStatus')),
        );
        $scope = $this->scopeReturning(new StringType());

        self::assertCount(1, $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function identifierLikeShapeHelper(): void
    {
        self::assertTrue(ForbidMagicStringAssertionRule::isIdentifierLike('desk'));
        self::assertTrue(ForbidMagicStringAssertionRule::isIdentifierLike('GET'));
        self::assertTrue(ForbidMagicStringAssertionRule::isIdentifierLike('cf_bl_order'));
        self::assertFalse(ForbidMagicStringAssertionRule::isIdentifierLike('Hello, World!'));
        self::assertFalse(ForbidMagicStringAssertionRule::isIdentifierLike(''));
        self::assertFalse(ForbidMagicStringAssertionRule::isIdentifierLike('a/b/c'));
    }

    private function staticAssert(string $method, \PhpParser\Node\Expr $arg0, \PhpParser\Node\Expr $arg1): StaticCall
    {
        return new StaticCall(new Name('self'), new Identifier($method), [new Arg($arg0), new Arg($arg1)]);
    }

    private function scopeReturning(Type $type): CollectedDataEmitter&NodeCallbackInvoker&Scope
    {
        // PHPStan's Rule::processNode() widens the $scope parameter to the
        // intersection the analyser actually passes; the double must satisfy all
        // three interfaces or the call is a type error.
        $scope = self::scopeStub();
        $scope->method('getType')->willReturn($type);

        return $scope;
    }
}
