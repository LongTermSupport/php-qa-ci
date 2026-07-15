<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidLooseComparisonRule;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\Variable;
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
#[CoversClass(ForbidLooseComparisonRule::class)]
#[Small]
#[AllowMockObjectsWithoutExpectations]
final class ForbidLooseComparisonRuleTest extends TestCase
{
    private ForbidLooseComparisonRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ForbidLooseComparisonRule();
    }

    #[Test]
    public function getNodeTypeIsBinaryOp(): void
    {
        self::assertSame(BinaryOp::class, $this->rule->getNodeType());
    }

    #[Test]
    public function looseEqualIsFlagged(): void
    {
        $errors = $this->rule->processNode(new Equal(new Variable('a'), new Variable('b')), $this->scope());

        self::assertCount(1, $errors);
        self::assertStringContainsString('Loose comparison (==) is banned', $errors[0]->getMessage());
        self::assertSame(ForbidLooseComparisonRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function looseNotEqualIsFlagged(): void
    {
        $errors = $this->rule->processNode(new NotEqual(new Variable('a'), new Variable('b')), $this->scope());

        self::assertCount(1, $errors);
        self::assertStringContainsString('Loose comparison (!=) is banned', $errors[0]->getMessage());
    }

    #[Test]
    public function strictIdenticalIsNotFlagged(): void
    {
        self::assertSame([], $this->rule->processNode(new Identical(new Variable('a'), new Variable('b')), $this->scope()));
    }

    #[Test]
    public function strictNotIdenticalIsNotFlagged(): void
    {
        self::assertSame([], $this->rule->processNode(new NotIdentical(new Variable('a'), new Variable('b')), $this->scope()));
    }

    private function scope(): CollectedDataEmitter&NodeCallbackInvoker&Scope
    {
        return $this->createMockForIntersectionOfInterfaces([CollectedDataEmitter::class, NodeCallbackInvoker::class, Scope::class]);
    }
}
