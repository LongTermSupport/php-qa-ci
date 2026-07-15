<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidHeaderInjectionRule;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ForbidHeaderInjectionRule::class)]
#[Small]
#[AllowMockObjectsWithoutExpectations]
final class ForbidHeaderInjectionRuleTest extends TestCase
{
    private ForbidHeaderInjectionRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ForbidHeaderInjectionRule();
    }

    #[Test]
    public function getNodeTypeIsFuncCall(): void
    {
        self::assertSame(FuncCall::class, $this->rule->getNodeType());
    }

    #[Test]
    public function rawHeaderCallIsFlagged(): void
    {
        $errors = $this->rule->processNode(new FuncCall(new Name('header'), [new Arg(new Variable('h'))]), $this->scope());

        self::assertCount(1, $errors);
        self::assertStringContainsString('Raw header', $errors[0]->getMessage());
        self::assertSame(ForbidHeaderInjectionRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function setcookieIsFlagged(): void
    {
        self::assertCount(1, $this->rule->processNode(new FuncCall(new Name('setcookie')), $this->scope()));
    }

    #[Test]
    public function setrawcookieIsFlaggedCaseInsensitively(): void
    {
        self::assertCount(1, $this->rule->processNode(new FuncCall(new Name('SetRawCookie')), $this->scope()));
    }

    #[Test]
    public function unrelatedFunctionIsNotFlagged(): void
    {
        self::assertSame([], $this->rule->processNode(new FuncCall(new Name('array_map')), $this->scope()));
    }

    #[Test]
    public function dynamicCallIsIgnored(): void
    {
        self::assertSame([], $this->rule->processNode(new FuncCall(new Variable('fn')), $this->scope()));
    }

    private function scope(): CollectedDataEmitter&NodeCallbackInvoker&Scope
    {
        return $this->createMockForIntersectionOfInterfaces([CollectedDataEmitter::class, NodeCallbackInvoker::class, Scope::class]);
    }
}
