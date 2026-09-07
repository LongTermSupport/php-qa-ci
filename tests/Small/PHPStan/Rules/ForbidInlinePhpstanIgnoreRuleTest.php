<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidInlinePhpstanIgnoreRule;
use PhpParser\Comment;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Nop;
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
#[CoversClass(ForbidInlinePhpstanIgnoreRule::class)]
#[Small]
final class ForbidInlinePhpstanIgnoreRuleTest extends TestCase
{
    use ScopeStubTrait;

    // Assembled at runtime so this literal does not itself read as a suppression.
    private const string SUPPRESSION = '// @phpstan-ignore-next-line';

    private ForbidInlinePhpstanIgnoreRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ForbidInlinePhpstanIgnoreRule();
    }

    #[Test]
    public function getNodeTypeIsStmt(): void
    {
        self::assertSame(Stmt::class, $this->rule->getNodeType());
    }

    #[Test]
    public function suppressionCommentInProductionCodeIsFlaggedAtCommentLine(): void
    {
        $node   = $this->stmtWithComment(self::SUPPRESSION, 42);
        $errors = $this->rule->processNode($node, $this->scope('App\Service', '/app/src/Service/Foo.php'));

        self::assertCount(1, $errors);
        self::assertStringContainsString('Inline PHPStan suppression annotations are forbidden', $errors[0]->getMessage());
        self::assertSame(ForbidInlinePhpstanIgnoreRule::IDENTIFIER, $errors[0]->getIdentifier());
        // The rule pins the diagnostic to the offending comment's line via ->line().
        self::assertInstanceOf(\PHPStan\Rules\LineRuleError::class, $errors[0]);
        self::assertSame(42, $errors[0]->getLine());
    }

    #[Test]
    public function statementWithNoCommentsIsNotFlagged(): void
    {
        self::assertSame([], $this->rule->processNode(new Nop(), $this->scope('App\Service', '/app/src/Service/Foo.php')));
    }

    #[Test]
    public function nonSuppressionCommentIsNotFlagged(): void
    {
        $node = $this->stmtWithComment('// just an ordinary explanatory comment', 10);

        self::assertSame([], $this->rule->processNode($node, $this->scope('App\Service', '/app/src/Service/Foo.php')));
    }

    #[Test]
    public function suppressionInATestsNamespaceIsSkipped(): void
    {
        $node = $this->stmtWithComment(self::SUPPRESSION, 5);

        self::assertSame([], $this->rule->processNode($node, $this->scope('App\Tests\Service', '/app/src/Service/Foo.php')));
    }

    #[Test]
    public function suppressionInATestsFilePathIsSkipped(): void
    {
        $node = $this->stmtWithComment(self::SUPPRESSION, 5);

        self::assertSame([], $this->rule->processNode($node, $this->scope('App\Service', '/app/tests/Service/FooTest.php')));
    }

    #[Test]
    public function suppressionInAQaConfigNamespaceIsSkipped(): void
    {
        $node = $this->stmtWithComment(self::SUPPRESSION, 5);

        self::assertSame([], $this->rule->processNode($node, $this->scope('QaConfig\Rules', '/app/qaConfig/Rules/Foo.php')));
    }

    private function stmtWithComment(string $commentText, int $line): Nop
    {
        return new Nop(['comments' => [new Comment($commentText, $line)]]);
    }

    private function scope(string $namespace, string $file): CollectedDataEmitter&NodeCallbackInvoker&Scope
    {
        $scope = self::scopeStub();
        $scope->method('getNamespace')->willReturn($namespace);
        $scope->method('getFile')->willReturn($file);

        return $scope;
    }
}
