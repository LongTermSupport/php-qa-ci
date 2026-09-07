<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\ComposerPlugin\PhpStanGuardPlugin;
use LTS\PHPQA\PHPStan\Rules\ForbidLooseComparisonRule;
use LTS\PHPQA\PHPStan\Rules\RequireRuleIdentifierConstantRule;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * Drives the rule directly with real ClassReflections (obtained from a real
 * ReflectionProvider over existing classes) supplied through a mocked Scope.
 * ClassReflection is final and cannot be doubled, so the enclosing-class facts
 * (does it implement PHPStan's Rule interface?) come from genuine classes: a
 * real bundled rule for the positive path and a real non-rule class for the
 * negative.
 *
 * @internal
 *
 * @extends RuleTestCase<RequireRuleIdentifierConstantRule>
 */
#[CoversClass(RequireRuleIdentifierConstantRule::class)]
#[Medium]
final class RequireRuleIdentifierConstantRuleTest extends RuleTestCase
{
    use ScopeStubTrait;

    #[Test]
    public function getNodeTypeIsMethodCall(): void
    {
        self::assertSame(MethodCall::class, $this->getRule()->getNodeType());
    }

    #[Test]
    public function stringLiteralIdentifierInsideARuleClassIsFlagged(): void
    {
        $call   = $this->identifierCall(new String_('phpqaci.somethingCustom'));
        $errors = $this->getRule()->processNode($call, $this->scopeInClass(ForbidLooseComparisonRule::class));

        self::assertCount(1, $errors);
        self::assertStringContainsString('"phpqaci.somethingCustom" is a magic string', $errors[0]->getMessage());
        self::assertSame(RequireRuleIdentifierConstantRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function constantIdentifierArgumentIsNotFlagged(): void
    {
        // ->identifier(self::IDENTIFIER) — the argument is not a string literal.
        $constArg = new ClassConstFetch(new Name('self'), new Identifier('IDENTIFIER'));

        self::assertSame([], $this->getRule()->processNode($this->identifierCall($constArg), $this->scopeInClass(ForbidLooseComparisonRule::class)));
    }

    #[Test]
    public function aDifferentMethodCallIsIgnored(): void
    {
        $call = new MethodCall(new Variable('builder'), new Identifier('build'), [new Arg(new String_('phpqaci.x'))]);

        self::assertSame([], $this->getRule()->processNode($call, $this->scopeInClass(ForbidLooseComparisonRule::class)));
    }

    #[Test]
    public function identifierCallWithNoArgumentsIsIgnored(): void
    {
        $call = new MethodCall(new Variable('builder'), new Identifier('identifier'), []);

        self::assertSame([], $this->getRule()->processNode($call, $this->scopeInClass(ForbidLooseComparisonRule::class)));
    }

    #[Test]
    public function stringLiteralIdentifierOutsideARuleClassIsIgnored(): void
    {
        // A non-rule class (a Composer plugin) — the ->identifier() convention only
        // binds inside PHPStan rules, so this is not flagged.
        self::assertSame([], $this->getRule()->processNode($this->identifierCall(new String_('phpqaci.x')), $this->scopeInClass(PhpStanGuardPlugin::class)));
    }

    #[Test]
    public function callWithNoEnclosingClassIsIgnored(): void
    {
        self::assertSame([], $this->getRule()->processNode($this->identifierCall(new String_('phpqaci.x')), $this->scopeInClass(null)));
    }

    protected function getRule(): Rule
    {
        return new RequireRuleIdentifierConstantRule();
    }

    private function identifierCall(\PhpParser\Node\Expr $argValue): MethodCall
    {
        return new MethodCall(new Variable('builder'), new Identifier('identifier'), [new Arg($argValue)]);
    }

    private function scopeInClass(?string $className): CollectedDataEmitter&NodeCallbackInvoker&Scope
    {
        $reflection = null;
        if (null !== $className) {
            $reflection = $this->reflectionFor($className);
        }

        $scope = self::scopeStub();
        $scope->method('getClassReflection')->willReturn($reflection);

        return $scope;
    }

    private function reflectionFor(string $className): ClassReflection
    {
        return $this->createReflectionProvider()->getClass($className);
    }
}
