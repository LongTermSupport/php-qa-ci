<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidNullCoalescingEmptyStringRule;
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
#[CoversClass(ForbidNullCoalescingEmptyStringRule::class)]
#[Small]
#[AllowMockObjectsWithoutExpectations]
final class ForbidNullCoalescingEmptyStringRuleTest extends TestCase
{
    private ForbidNullCoalescingEmptyStringRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ForbidNullCoalescingEmptyStringRule();
    }

    #[Test]
    public function getNodeTypeIsCoalesce(): void
    {
        self::assertSame(Coalesce::class, $this->rule->getNodeType());
    }

    #[Test]
    public function coalesceWithEmptyStringDefaultIsFlagged(): void
    {
        $errors = $this->rule->processNode(new Coalesce(new Variable('x'), new String_('')), $this->scope());

        self::assertCount(1, $errors);
        self::assertStringContainsString("Avoid ?? ''", $errors[0]->getMessage());
        self::assertSame(ForbidNullCoalescingEmptyStringRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function coalesceWithMeaningfulStringDefaultIsNotFlagged(): void
    {
        self::assertSame([], $this->rule->processNode(new Coalesce(new Variable('x'), new String_('default')), $this->scope()));
    }

    #[Test]
    public function coalesceWithNonStringDefaultIsNotFlagged(): void
    {
        self::assertSame([], $this->rule->processNode(new Coalesce(new Variable('x'), new ConstFetch(new Name('null'))), $this->scope()));
    }

    private function scope(): CollectedDataEmitter&NodeCallbackInvoker&Scope
    {
        return $this->createMockForIntersectionOfInterfaces([CollectedDataEmitter::class, NodeCallbackInvoker::class, Scope::class]);
    }
}
