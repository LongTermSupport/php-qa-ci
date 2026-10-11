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
use PHPStan\Analyser\DependencyTracker;
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

    private const string ASSERT_SAME = 'assertSame';

    private const string FIXTURE_VALUE = 'desk';

    private const string STATUS_VALUE = 'active';

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
        $call  = $this->staticAssert(self::ASSERT_SAME, new String_(self::FIXTURE_VALUE), new Variable('x'));
        $scope = $this->scopeReturning(new StringType());

        self::assertCount(1, $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function thisStyleAssertionIsAlsoFlagged(): void
    {
        // $this->assertSame('active', $x)
        $call  = new MethodCall(new Variable('this'), new Identifier(self::ASSERT_SAME), [
            new Arg(new String_(self::STATUS_VALUE)),
            new Arg(new Variable('x')),
        ]);
        $scope = $this->scopeReturning(new StringType());

        self::assertCount(1, $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function literalInTheActualPositionIsAlsoFlagged(): void
    {
        // self::assertSame($x, 'desk') — literal second, still a magic-string pin
        $call  = $this->staticAssert(self::ASSERT_SAME, new Variable('x'), new String_(self::FIXTURE_VALUE));
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
        $call  = $this->staticAssert(self::ASSERT_SAME, new String_(self::FIXTURE_VALUE), new Variable('x'));
        $scope = $this->scopeReturning(new ConstantStringType(self::FIXTURE_VALUE));

        self::assertSame([], $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function freeTextLiteralIsNotFlagged(): void
    {
        // Not identifier-like (spaces/punctuation) — a legitimate string-output
        // assertion, not a closed-set magic value.
        $call  = $this->staticAssert(self::ASSERT_SAME, new String_('Hello, World!'), new Variable('x'));
        $scope = $this->scopeReturning(new StringType());

        self::assertSame([], $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function nonAssertionCallIsIgnored(): void
    {
        $call  = $this->staticAssert('doSomething', new String_(self::FIXTURE_VALUE), new Variable('x'));
        $scope = $this->scopeReturning(new StringType());

        self::assertSame([], $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function literalAgainstNonStringIsNotFlagged(): void
    {
        // Actual is an int — not a stringly-typed value; out of scope.
        $call  = $this->staticAssert(self::ASSERT_SAME, new String_(self::FIXTURE_VALUE), new Variable('x'));
        $scope = $this->scopeReturning(new IntegerType());

        self::assertSame([], $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function bothOperandsLiteralIsNotOurConcern(): void
    {
        // literal == literal is itself always-narrowed; leave to the built-in.
        $call  = $this->staticAssert(self::ASSERT_SAME, new String_(self::FIXTURE_VALUE), new String_(self::FIXTURE_VALUE));
        $scope = $this->scopeReturning(new ConstantStringType(self::FIXTURE_VALUE));

        self::assertSame([], $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function reflectionGetNameIsNotFlagged(): void
    {
        // self::assertSame('orgId', $param->getName()) — Reflection metadata accessor.
        $call  = $this->staticAssert(
            self::ASSERT_SAME,
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
            self::ASSERT_SAME,
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
            self::ASSERT_SAME,
            new String_(self::STATUS_VALUE),
            new MethodCall(new Variable('repo'), new Identifier('getStatus')),
        );
        $scope = $this->scopeReturning(new StringType());

        self::assertCount(1, $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function theErrorNamesTheLiteralBothWaysAndCarriesTheIdentifier(): void
    {
        $call  = $this->staticAssert(self::ASSERT_SAME, new String_(self::FIXTURE_VALUE), new Variable('x'));
        $scope = $this->scopeReturning(new StringType());

        $errors = $this->rule->processNode($call, $scope);

        self::assertCount(1, $errors);
        self::assertSame(
            'Asserting the magic string "desk" against a plain string value — a test is bridging certainty that a '
            . 'type or a single definition should provide. Fix it one of two ways: '
            . '(1) DOMAIN value (a closed set like a status, type, or action): model it as a backed enum (or a '
            . 'domain constant) in production and assert the enum case / constant — the type system then '
            . 'guarantees it and the assertion becomes unnecessary; '
            . '(2) TEST DATA (a fixture id, key, or sample value): hoist "desk" to a test-level private const and '
            . 'reference it from both the arrange step and the assertion, so the value is defined once instead of '
            . 'duplicated. Either way, do not pin a raw magic string in the assertion.',
            $errors[0]->getMessage(),
        );
        self::assertSame(ForbidMagicStringAssertionRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function bothOperandsLiteralIsNotFlaggedEvenWhenTheScopeSaysGeneralString(): void
    {
        // The literal-pair branch must refuse on the node shape alone, not lean
        // on the constant-string type gate further down.
        $call  = $this->staticAssert(self::ASSERT_SAME, new String_(self::FIXTURE_VALUE), new String_(self::STATUS_VALUE));
        $scope = $this->scopeReturning(new StringType());

        self::assertSame([], $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function noLiteralOperandIsNotFlagged(): void
    {
        $call  = $this->staticAssert(self::ASSERT_SAME, new Variable('expected'), new Variable('x'));
        $scope = $this->scopeReturning(new StringType());

        self::assertSame([], $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function aStaticCallNamedLikeAMetadataAccessorStillFlags(): void
    {
        // Only an instance call is the Reflection / PSR-7 accessor; Status::getName()
        // is a domain value like any other.
        $call  = $this->staticAssert(
            self::ASSERT_SAME,
            new String_(self::STATUS_VALUE),
            new StaticCall(new Name('Status'), new Identifier('getName')),
        );
        $scope = $this->scopeReturning(new StringType());

        self::assertCount(1, $this->rule->processNode($call, $scope));
    }

    #[Test]
    public function anIdentifierIsAtMostFortyCharacters(): void
    {
        self::assertTrue(ForbidMagicStringAssertionRule::isIdentifierLike(str_repeat('a', 40)));
        self::assertFalse(ForbidMagicStringAssertionRule::isIdentifierLike(str_repeat('a', 41)));
    }

    #[Test]
    public function identifierLikeShapeHelper(): void
    {
        self::assertTrue(ForbidMagicStringAssertionRule::isIdentifierLike(self::FIXTURE_VALUE));
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

    private function scopeReturning(Type $type): CollectedDataEmitter&DependencyTracker&NodeCallbackInvoker&Scope
    {
        // PHPStan's Rule::processNode() widens the $scope parameter to the
        // intersection the analyser actually passes; the double must satisfy all
        // three interfaces or the call is a type error.
        $scope = self::scopeStub();
        $scope->method('getType')->willReturn($type);

        return $scope;
    }
}
