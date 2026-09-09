<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidDebugOutputFunctionsRule;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ConstFetch;
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
 * @internal
 */
#[CoversClass(ForbidDebugOutputFunctionsRule::class)]
#[Small]
final class ForbidDebugOutputFunctionsRuleTest extends TestCase
{
    use ScopeStubTrait;

    private const string VAR_EXPORT = 'var_export';

    private const string PRINT_R = 'print_r';

    private ForbidDebugOutputFunctionsRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ForbidDebugOutputFunctionsRule();
    }

    #[Test]
    public function getNodeTypeIsFuncCall(): void
    {
        self::assertSame(FuncCall::class, $this->rule->getNodeType());
    }

    #[Test]
    public function aPrintingDumpIsReported(): void
    {
        $errors = $this->rule->processNode(new FuncCall(new Name('var_dump'), [new Arg(new Variable('x'))]), $this->scope());

        self::assertCount(1, $errors);
        self::assertStringContainsString('prints internal state', $errors[0]->getMessage());
        self::assertSame(ForbidDebugOutputFunctionsRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function varDumpIsReportedEvenWithASecondArgumentBecauseItHasNoReturnMode(): void
    {
        $call = new FuncCall(new Name('var_dump'), [new Arg(new Variable('x')), new Arg(new ConstFetch(new Name('true')))]);

        self::assertCount(1, $this->rule->processNode($call, $this->scope()));
    }

    #[Test]
    public function theReturnStringFormsAreNotReported(): void
    {
        foreach ([self::PRINT_R, self::VAR_EXPORT] as $function) {
            $call = new FuncCall(new Name($function), [new Arg(new Variable('x')), new Arg(new ConstFetch(new Name('TRUE')))]);

            self::assertSame([], $this->rule->processNode($call, $this->scope()), $function . ' returning a string is legitimate');
        }
    }

    #[Test]
    public function theSingleArgumentFormsAreReported(): void
    {
        foreach ([self::PRINT_R, self::VAR_EXPORT] as $function) {
            $call = new FuncCall(new Name($function), [new Arg(new Variable('x'))]);

            self::assertCount(1, $this->rule->processNode($call, $this->scope()), $function . ' without the flag prints');
        }
    }

    #[Test]
    public function anExplicitFalseSecondArgumentIsReportedBecauseItStillPrints(): void
    {
        $call = new FuncCall(new Name(self::PRINT_R), [new Arg(new Variable('x')), new Arg(new ConstFetch(new Name('false')))]);

        self::assertCount(1, $this->rule->processNode($call, $this->scope()));
    }

    #[Test]
    public function aVariableSecondArgumentIsReportedSinceItCannotBeShownToReturn(): void
    {
        $call = new FuncCall(new Name(self::VAR_EXPORT), [new Arg(new Variable('x')), new Arg(new Variable('flag'))]);

        self::assertCount(1, $this->rule->processNode($call, $this->scope()));
    }

    #[Test]
    public function aSafeFunctionIsNotReported(): void
    {
        self::assertSame([], $this->rule->processNode(new FuncCall(new Name('json_encode'), []), $this->scope()));
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
