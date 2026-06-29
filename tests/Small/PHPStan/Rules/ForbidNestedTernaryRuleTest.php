<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidNestedTernaryRule;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ForbidNestedTernaryRule::class)]
#[\PHPUnit\Framework\Attributes\Small]
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class ForbidNestedTernaryRuleTest extends TestCase
{
    private ForbidNestedTernaryRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ForbidNestedTernaryRule();
    }

    public function testGetNodeType(): void
    {
        self::assertSame(Ternary::class, $this->rule->getNodeType());
    }

    public function testSimpleTernaryProducesNoError(): void
    {
        $ternary = new Ternary(new Variable('a'), new Variable('b'), new Variable('c'));
        $scope   = $this->createMockForIntersectionOfInterfaces([CollectedDataEmitter::class, NodeCallbackInvoker::class, Scope::class]);

        self::assertSame([], $this->rule->processNode($ternary, $scope));
    }

    public function testTernaryInConditionIsFlagged(): void
    {
        $inner = new Ternary(new Variable('a'), new Variable('b'), new Variable('c'));
        $outer = new Ternary($inner, new Variable('d'), new Variable('e'));
        $scope = $this->createMockForIntersectionOfInterfaces([CollectedDataEmitter::class, NodeCallbackInvoker::class, Scope::class]);

        self::assertCount(1, $this->rule->processNode($outer, $scope));
    }

    public function testTernaryInThenBranchIsFlagged(): void
    {
        $inner = new Ternary(new Variable('b'), new Variable('c'), new Variable('d'));
        $outer = new Ternary(new Variable('a'), $inner, new Variable('e'));
        $scope = $this->createMockForIntersectionOfInterfaces([CollectedDataEmitter::class, NodeCallbackInvoker::class, Scope::class]);

        self::assertCount(1, $this->rule->processNode($outer, $scope));
    }

    public function testTernaryInElseBranchIsFlagged(): void
    {
        $inner = new Ternary(new Variable('c'), new Variable('d'), new Variable('e'));
        $outer = new Ternary(new Variable('a'), new Variable('b'), $inner);
        $scope = $this->createMockForIntersectionOfInterfaces([CollectedDataEmitter::class, NodeCallbackInvoker::class, Scope::class]);

        self::assertCount(1, $this->rule->processNode($outer, $scope));
    }

    public function testElvisOperatorWithNullIfProducesNoError(): void
    {
        // Short ternary $a ?: $b has null for the "if" branch — not a nested ternary
        $ternary = new Ternary(new Variable('a'), null, new Variable('b'));
        $scope   = $this->createMockForIntersectionOfInterfaces([CollectedDataEmitter::class, NodeCallbackInvoker::class, Scope::class]);

        self::assertSame([], $this->rule->processNode($ternary, $scope));
    }
}
