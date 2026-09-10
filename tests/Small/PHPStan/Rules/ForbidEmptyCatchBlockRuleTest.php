<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidEmptyCatchBlockRule;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Nop;
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
#[CoversClass(ForbidEmptyCatchBlockRule::class)]
#[Small]
final class ForbidEmptyCatchBlockRuleTest extends TestCase
{
    use ScopeStubTrait;

    private const string THROWABLE = 'Throwable';

    private ForbidEmptyCatchBlockRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ForbidEmptyCatchBlockRule();
    }

    #[Test]
    public function getNodeTypeIsCatch(): void
    {
        self::assertSame(Catch_::class, $this->rule->getNodeType());
    }

    #[Test]
    public function emptyCatchBlockIsFlagged(): void
    {
        $catch  = new Catch_([new Name(self::THROWABLE)], new Variable('e'), []);
        $errors = $this->rule->processNode($catch, $this->scope());

        self::assertCount(1, $errors);
        self::assertStringContainsString('Empty catch block detected', $errors[0]->getMessage());
        self::assertSame(ForbidEmptyCatchBlockRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function catchWithAStatementIsNotFlagged(): void
    {
        $catch = new Catch_([new Name(self::THROWABLE)], new Variable('e'), [new Return_()]);

        self::assertSame([], $this->rule->processNode($catch, $this->scope()));
    }

    #[Test]
    public function catchContainingOnlyANopStatementIsNotFlaggedBecauseItHasAStatement(): void
    {
        // A Nop (from a lone comment) is still an AST statement, so count > 0.
        $catch = new Catch_([new Name(self::THROWABLE)], new Variable('e'), [new Nop()]);

        self::assertSame([], $this->rule->processNode($catch, $this->scope()));
    }

    private function scope(): CollectedDataEmitter&NodeCallbackInvoker&Scope
    {
        return self::scopeStub();
    }
}
