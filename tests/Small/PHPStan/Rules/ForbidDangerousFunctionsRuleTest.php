<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidDangerousFunctionsRule;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Constructs FuncCall AST nodes whose callee names are the banned functions and
 * asserts the rule flags them. No dangerous function is actually invoked here —
 * the banned names are only ever data passed to the php-parser Name node.
 *
 * @internal
 */
#[CoversClass(ForbidDangerousFunctionsRule::class)]
#[Small]
final class ForbidDangerousFunctionsRuleTest extends TestCase
{
    use ScopeStubTrait;

    private ForbidDangerousFunctionsRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ForbidDangerousFunctionsRule();
    }

    #[Test]
    public function getNodeTypeIsFuncCall(): void
    {
        self::assertSame(FuncCall::class, $this->rule->getNodeType());
    }

    #[Test]
    public function bannedFunctionIsFlagged(): void
    {
        $errors = $this->rule->processNode(new FuncCall(new Name('shell_exec'), [new Arg(new Variable('cmd'))]), $this->scope());

        self::assertCount(1, $errors);
        self::assertStringContainsString('Dangerous function shell_exec', $errors[0]->getMessage());
        self::assertSame(ForbidDangerousFunctionsRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function bannedFunctionIsMatchedCaseInsensitively(): void
    {
        // toLowerString() normalises the callee, so an upper-cased banned name is caught too.
        $errors = $this->rule->processNode(new FuncCall(new Name('PASSTHRU'), [new Arg(new Variable('code'))]), $this->scope());

        self::assertCount(1, $errors);
        self::assertStringContainsString('passthru', $errors[0]->getMessage());
    }

    #[Test]
    public function safeFunctionIsNotFlagged(): void
    {
        self::assertSame([], $this->rule->processNode(new FuncCall(new Name('strlen'), [new Arg(new Variable('s'))]), $this->scope()));
    }

    #[Test]
    public function parseStrWithoutOutputVariableIsFlagged(): void
    {
        $errors = $this->rule->processNode(new FuncCall(new Name('parse_str'), [new Arg(new Variable('query'))]), $this->scope());

        self::assertCount(1, $errors);
        self::assertStringContainsString('parse_str', $errors[0]->getMessage());
    }

    #[Test]
    public function parseStrWithOutputVariableIsNotFlagged(): void
    {
        $call = new FuncCall(new Name('parse_str'), [new Arg(new Variable('query')), new Arg(new Variable('result'))]);

        self::assertSame([], $this->rule->processNode($call, $this->scope()));
    }

    #[Test]
    public function dynamicFunctionCallIsIgnored(): void
    {
        // $fn() — the callee is not a Name, so it cannot be statically checked.
        self::assertSame([], $this->rule->processNode(new FuncCall(new Variable('fn')), $this->scope()));
    }

    private function scope(): CollectedDataEmitter&NodeCallbackInvoker&Scope
    {
        return self::scopeStub();
    }
}
