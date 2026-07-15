<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidNullCoalescingFalseRule;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
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
#[CoversClass(ForbidNullCoalescingFalseRule::class)]
#[Small]
#[AllowMockObjectsWithoutExpectations]
final class ForbidNullCoalescingFalseRuleTest extends TestCase
{
    private ForbidNullCoalescingFalseRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ForbidNullCoalescingFalseRule();
    }

    #[Test]
    public function getNodeTypeIsCoalesce(): void
    {
        self::assertSame(Coalesce::class, $this->rule->getNodeType());
    }

    #[Test]
    public function coalesceWithFalseDefaultIsFlagged(): void
    {
        $errors = $this->rule->processNode(new Coalesce(new Variable('x'), new ConstFetch(new Name('false'))), $this->scope());

        self::assertCount(1, $errors);
        self::assertStringContainsString('Avoid ?? false', $errors[0]->getMessage());
        self::assertSame(ForbidNullCoalescingFalseRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function coalesceWithUppercaseFalseIsAlsoFlagged(): void
    {
        // The rule lower-cases the constant name, so FALSE / False are covered too.
        $errors = $this->rule->processNode(new Coalesce(new Variable('x'), new ConstFetch(new Name('FALSE'))), $this->scope());

        self::assertCount(1, $errors);
    }

    #[Test]
    public function coalesceWithTrueDefaultIsNotFlagged(): void
    {
        self::assertSame([], $this->rule->processNode(new Coalesce(new Variable('x'), new ConstFetch(new Name('true'))), $this->scope()));
    }

    #[Test]
    public function coalesceWithNonConstDefaultIsNotFlagged(): void
    {
        self::assertSame([], $this->rule->processNode(new Coalesce(new Variable('x'), new String_('false')), $this->scope()));
    }

    private function scope(): CollectedDataEmitter&NodeCallbackInvoker&Scope
    {
        return $this->createMockForIntersectionOfInterfaces([CollectedDataEmitter::class, NodeCallbackInvoker::class, Scope::class]);
    }
}
