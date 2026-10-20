<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidInlinePhpstanIgnoreRule;
use LTS\PHPQA\PHPStan\Rules\TestCodeDetector;
use PhpParser\Comment;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Nop;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ForbidInlinePhpstanIgnoreRule::class)]
#[UsesClass(TestCodeDetector::class)]
#[Small]
final class ForbidInlinePhpstanIgnoreRuleTest extends TestCase
{
    use ScopeStubTrait;

    private const string APP_SERVICE = 'App\Service';

    private const string SERVICE_FOO_PHP = '/app/src/Service/Foo.php';

    // Assembled at runtime so this literal does not itself read as a suppression.
    private const string SUPPRESSION = '// @phpstan-ignore-next-line';

    private ForbidInlinePhpstanIgnoreRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ForbidInlinePhpstanIgnoreRule(new TestCodeDetector('/app'));
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
        $errors = $this->rule->processNode($node, $this->scope(self::APP_SERVICE, self::SERVICE_FOO_PHP));

        self::assertCount(1, $errors);
        self::assertSame(
            'Inline PHPStan suppression annotations are forbidden. Fix the underlying type issue instead '
            . '(use type guards, array shapes, \Safe\ functions, etc.). If truly irreducible, manage via ignoreErrors '
            . 'in phpstan.neon with a specific identifier and path.',
            $errors[0]->getMessage(),
        );
        self::assertSame(ForbidInlinePhpstanIgnoreRule::IDENTIFIER, $errors[0]->getIdentifier());
        // The rule pins the diagnostic to the offending comment's line via ->line().
        self::assertInstanceOf(\PHPStan\Rules\LineRuleError::class, $errors[0]);
        self::assertSame(42, $errors[0]->getLine());
    }

    /** Every comment on the statement is read: an ordinary one first does not hide a suppression after it. */
    #[Test]
    public function everySuppressionAmongAStatementsCommentsIsFlaggedAtItsOwnLine(): void
    {
        $node = new Nop(['comments' => [
            new Comment('// just an ordinary explanatory comment', 7),
            new Comment(self::SUPPRESSION, 8),
            new Comment(self::SUPPRESSION, 9),
        ]]);

        $lines = [];
        foreach ($this->rule->processNode($node, $this->scope(self::APP_SERVICE, self::SERVICE_FOO_PHP)) as $error) {
            self::assertInstanceOf(\PHPStan\Rules\LineRuleError::class, $error);
            $lines[] = $error->getLine();
        }

        self::assertSame([8, 9], $lines);
    }

    #[Test]
    public function statementWithNoCommentsIsNotFlagged(): void
    {
        self::assertSame([], $this->rule->processNode(new Nop(), $this->scope(self::APP_SERVICE, self::SERVICE_FOO_PHP)));
    }

    #[Test]
    public function nonSuppressionCommentIsNotFlagged(): void
    {
        $node = $this->stmtWithComment('// just an ordinary explanatory comment', 10);

        self::assertSame([], $this->rule->processNode($node, $this->scope(self::APP_SERVICE, self::SERVICE_FOO_PHP)));
    }

    #[Test]
    public function suppressionInATestsNamespaceIsSkipped(): void
    {
        $node = $this->stmtWithComment(self::SUPPRESSION, 5);

        self::assertSame([], $this->rule->processNode($node, $this->scope('App\Tests\Service', self::SERVICE_FOO_PHP)));
    }

    #[Test]
    public function suppressionInATestsFilePathIsSkipped(): void
    {
        $node = $this->stmtWithComment(self::SUPPRESSION, 5);

        self::assertSame([], $this->rule->processNode($node, $this->scope(self::APP_SERVICE, '/app/tests/Service/FooTest.php')));
    }

    /** A checkout under a directory named tests: its production code is still checked. */
    #[Test]
    public function suppressionInProductionCodeOfACheckoutUnderATestsDirectoryIsFlagged(): void
    {
        $rule = new ForbidInlinePhpstanIgnoreRule(new TestCodeDetector('/home/ci/tests/app'));
        $node = $this->stmtWithComment(self::SUPPRESSION, 5);

        self::assertCount(1, $rule->processNode($node, $this->scope(self::APP_SERVICE, '/home/ci/tests/app/src/Service/Foo.php')));
    }

    /** `Contests` contains the word `Tests` but is not a test namespace. */
    #[Test]
    public function suppressionInAContestsNamespaceIsFlagged(): void
    {
        $node = $this->stmtWithComment(self::SUPPRESSION, 5);

        self::assertCount(1, $this->rule->processNode($node, $this->scope('App\Contests\Service', self::SERVICE_FOO_PHP)));
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

    private function scope(string $namespace, string $file): CollectedDataEmitter&DependencyTracker&NodeCallbackInvoker&Scope
    {
        $scope = self::scopeStub();
        $scope->method('getNamespace')->willReturn($namespace);
        $scope->method('getFile')->willReturn($file);

        return $scope;
    }
}
