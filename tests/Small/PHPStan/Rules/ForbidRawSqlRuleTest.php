<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidRawSqlRule;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\VariadicPlaceholder;
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
#[CoversClass(ForbidRawSqlRule::class)]
#[Small]
final class ForbidRawSqlRuleTest extends TestCase
{
    use ScopeStubTrait;

    private ForbidRawSqlRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ForbidRawSqlRule();
    }

    #[Test]
    public function getNodeTypeIsMethodCall(): void
    {
        self::assertSame(MethodCall::class, $this->rule->getNodeType());
    }

    #[Test]
    public function concatenationInABannedMethodArgumentIsFlagged(): void
    {
        // $conn->executeQuery($a . $b)
        $call   = $this->methodCall('executeQuery', [new Arg(new Concat(new Variable('a'), new Variable('b')))]);
        $errors = $this->rule->processNode($call, $this->scope());

        self::assertCount(1, $errors);
        self::assertStringContainsString('String concatenation in executeQuery', $errors[0]->getMessage());
        self::assertSame(ForbidRawSqlRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function nestedConcatenationDeeperInTheArgumentIsAlsoFlagged(): void
    {
        // $conn->prepare(foo($a . $b)) — concat is nested inside a call, found via NodeFinder.
        $nested = new FuncCall(new \PhpParser\Node\Name('foo'), [new Arg(new Concat(new Variable('a'), new Variable('b')))]);
        $call   = $this->methodCall('prepare', [new Arg($nested)]);

        self::assertCount(1, $this->rule->processNode($call, $this->scope()));
    }

    #[Test]
    public function bannedMethodMatchesCaseInsensitively(): void
    {
        $call = $this->methodCall('ExecuteStatement', [new Arg(new Concat(new Variable('a'), new Variable('b')))]);

        self::assertCount(1, $this->rule->processNode($call, $this->scope()));
    }

    #[Test]
    public function bannedMethodWithoutConcatenationIsNotFlagged(): void
    {
        $call = $this->methodCall('executeQuery', [new Arg(new Variable('preparedSql'))]);

        self::assertSame([], $this->rule->processNode($call, $this->scope()));
    }

    #[Test]
    public function unrelatedMethodWithConcatenationIsNotFlagged(): void
    {
        $call = $this->methodCall('doThing', [new Arg(new Concat(new Variable('a'), new Variable('b')))]);

        self::assertSame([], $this->rule->processNode($call, $this->scope()));
    }

    #[Test]
    public function dynamicMethodNameIsIgnored(): void
    {
        $call = new MethodCall(new Variable('conn'), new Variable('method'), [new Arg(new Concat(new Variable('a'), new Variable('b')))]);

        self::assertSame([], $this->rule->processNode($call, $this->scope()));
    }

    #[Test]
    public function variadicPlaceholderArgumentIsSkippedWithoutError(): void
    {
        // $conn->executeQuery(...) first-class callable syntax — the arg is a
        // VariadicPlaceholder, which must be skipped rather than inspected.
        $call = new MethodCall(new Variable('conn'), new Identifier('executeQuery'), [new VariadicPlaceholder()]);

        self::assertSame([], $this->rule->processNode($call, $this->scope()));
    }

    /**
     * @param list<Arg|VariadicPlaceholder> $args
     */
    private function methodCall(string $method, array $args): MethodCall
    {
        return new MethodCall(new Variable('conn'), new Identifier($method), $args);
    }

    private function scope(): CollectedDataEmitter&NodeCallbackInvoker&Scope
    {
        return self::scopeStub();
    }
}
