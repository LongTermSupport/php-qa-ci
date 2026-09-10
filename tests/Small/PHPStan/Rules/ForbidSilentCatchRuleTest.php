<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidSilentCatchRule;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
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
#[CoversClass(ForbidSilentCatchRule::class)]
#[Small]
final class ForbidSilentCatchRuleTest extends TestCase
{
    use ScopeStubTrait;

    private const string THROWABLE = 'Throwable';

    private ForbidSilentCatchRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ForbidSilentCatchRule();
    }

    #[Test]
    public function getNodeTypeIsCatch(): void
    {
        self::assertSame(Catch_::class, $this->rule->getNodeType());
    }

    #[Test]
    public function catchWithoutVariableThatDoesNotRethrowIsFlagged(): void
    {
        // catch (Throwable) { return null; }
        $catch  = new Catch_([new Name(self::THROWABLE)], null, [new Return_()]);
        $errors = $this->rule->processNode($catch, $this->scope());

        self::assertCount(1, $errors);
        self::assertStringContainsString('Catch block swallows exception silently', $errors[0]->getMessage());
        self::assertSame(ForbidSilentCatchRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function catchWithoutVariableThatRethrowsIsNotFlagged(): void
    {
        // catch (Throwable) { throw new RuntimeException(); }
        $throw = new Expression(new Throw_(new New_(new Name('RuntimeException'))));
        $catch = new Catch_([new Name(self::THROWABLE)], null, [$throw]);

        self::assertSame([], $this->rule->processNode($catch, $this->scope()));
    }

    #[Test]
    public function catchWhoseVariableIsUsedIsNotFlagged(): void
    {
        // catch (Throwable $e) { return $e->getMessage(); }
        $use   = new Return_(new MethodCall(new Variable('e'), new Identifier('getMessage')));
        $catch = new Catch_([new Name(self::THROWABLE)], new Variable('e'), [$use]);

        self::assertSame([], $this->rule->processNode($catch, $this->scope()));
    }

    #[Test]
    public function catchWithUnusedVariableAndNoHandlingIsFlagged(): void
    {
        // catch (Throwable $e) { return null; } — $e never referenced, no throw, no log
        $catch = new Catch_([new Name(self::THROWABLE)], new Variable('e'), [new Return_()]);

        self::assertCount(1, $this->rule->processNode($catch, $this->scope()));
    }

    #[Test]
    public function catchWithUnusedVariableButLoggingIsNotFlagged(): void
    {
        // catch (Throwable $e) { $this->logger->error('boom'); }
        $log   = new Expression(new MethodCall(new Variable('logger'), new Identifier('error'), [new Arg(new Variable('msg'))]));
        $catch = new Catch_([new Name(self::THROWABLE)], new Variable('e'), [$log]);

        self::assertSame([], $this->rule->processNode($catch, $this->scope()));
    }

    #[Test]
    public function catchWithUnusedVariableButRethrowIsNotFlagged(): void
    {
        $throw = new Expression(new Throw_(new New_(new Name('RuntimeException'))));
        $catch = new Catch_([new Name(self::THROWABLE)], new Variable('e'), [$throw]);

        self::assertSame([], $this->rule->processNode($catch, $this->scope()));
    }

    private function scope(): CollectedDataEmitter&NodeCallbackInvoker&Scope
    {
        return self::scopeStub();
    }
}
