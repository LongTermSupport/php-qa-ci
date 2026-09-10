<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidInsecureFunctionsRule;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Builds FuncCall nodes whose callee names are the banned functions. Nothing is
 * invoked; the names are only ever data handed to a php-parser Name node.
 *
 * @internal
 */
#[CoversClass(ForbidInsecureFunctionsRule::class)]
#[Small]
final class ForbidInsecureFunctionsRuleTest extends TestCase
{
    use ScopeStubTrait;

    private const string HASH = 'hash';

    private ForbidInsecureFunctionsRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ForbidInsecureFunctionsRule();
    }

    #[Test]
    public function getNodeTypeIsFuncCall(): void
    {
        self::assertSame(FuncCall::class, $this->rule->getNodeType());
    }

    #[Test]
    public function aBrokenHashIsReportedWithThePasswordHashAdvice(): void
    {
        $errors = $this->rule->processNode(new FuncCall(new Name('md5'), [new Arg(new Variable('v'))]), $this->scope());

        self::assertCount(1, $errors);
        self::assertStringContainsString('SHA-256', $errors[0]->getMessage());
        self::assertSame(ForbidInsecureFunctionsRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function predictableRandomnessIsReported(): void
    {
        foreach (['rand', 'mt_rand', 'lcg_value', 'uniqid'] as $function) {
            $errors = $this->rule->processNode(new FuncCall(new Name($function), []), $this->scope());

            self::assertCount(1, $errors, $function . ' should be reported');
            self::assertStringContainsString('cryptographically secure', $errors[0]->getMessage());
        }
    }

    #[Test]
    public function theUnparameterisedMysqlQueryFunctionsAreReported(): void
    {
        $errors = $this->rule->processNode(new FuncCall(new Name('mysqli_query'), [new Arg(new Variable('sql'))]), $this->scope());

        self::assertCount(1, $errors);
        self::assertStringContainsString('prepared statement', $errors[0]->getMessage());
    }

    #[Test]
    public function hashWithAWeakLiteralAlgorithmIsReported(): void
    {
        $call = new FuncCall(new Name(self::HASH), [new Arg(new String_('MD5')), new Arg(new Variable('v'))]);

        $errors = $this->rule->processNode($call, $this->scope());

        self::assertCount(1, $errors, 'the algorithm is matched case-insensitively');
    }

    #[Test]
    public function hashWithAStrongLiteralAlgorithmIsNotReported(): void
    {
        $call = new FuncCall(new Name(self::HASH), [new Arg(new String_('sha256')), new Arg(new Variable('v'))]);

        self::assertSame([], $this->rule->processNode($call, $this->scope()));
    }

    #[Test]
    public function hashWithAVariableAlgorithmIsNotReportedBecauseTheValueIsUnknown(): void
    {
        $call = new FuncCall(new Name(self::HASH), [new Arg(new Variable('algo')), new Arg(new Variable('v'))]);

        self::assertSame([], $this->rule->processNode($call, $this->scope()), 'guessing would report code that may be correct');
    }

    #[Test]
    public function aSafeFunctionIsNotReported(): void
    {
        self::assertSame([], $this->rule->processNode(new FuncCall(new Name('random_int'), []), $this->scope()));
    }

    #[Test]
    public function aDynamicCallIsIgnored(): void
    {
        self::assertSame([], $this->rule->processNode(new FuncCall(new Variable('fn')), $this->scope()));
    }

    private function scope(): CollectedDataEmitter&NodeCallbackInvoker&Scope
    {
        return self::scopeStub();
    }
}
