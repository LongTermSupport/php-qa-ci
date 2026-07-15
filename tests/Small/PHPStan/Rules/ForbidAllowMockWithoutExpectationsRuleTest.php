<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidAllowMockWithoutExpectationsRule;
use PhpParser\Node\Attribute;
use PhpParser\Node\Name;
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
#[CoversClass(ForbidAllowMockWithoutExpectationsRule::class)]
#[Small]
#[AllowMockObjectsWithoutExpectations]
final class ForbidAllowMockWithoutExpectationsRuleTest extends TestCase
{
    private ForbidAllowMockWithoutExpectationsRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ForbidAllowMockWithoutExpectationsRule();
    }

    #[Test]
    public function getNodeTypeIsAttribute(): void
    {
        self::assertSame(Attribute::class, $this->rule->getNodeType());
    }

    #[Test]
    public function fullyQualifiedForbiddenAttributeIsFlagged(): void
    {
        $attr   = new Attribute(new Name(AllowMockObjectsWithoutExpectations::class));
        $errors = $this->rule->processNode($attr, $this->scope());

        self::assertCount(1, $errors);
        self::assertStringContainsString('#[AllowMockObjectsWithoutExpectations] is banned', $errors[0]->getMessage());
        self::assertSame(ForbidAllowMockWithoutExpectationsRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function shortNameForbiddenAttributeIsFlagged(): void
    {
        $attr = new Attribute(new Name('AllowMockObjectsWithoutExpectations'));

        self::assertCount(1, $this->rule->processNode($attr, $this->scope()));
    }

    #[Test]
    public function unrelatedAttributeIsNotFlagged(): void
    {
        self::assertSame([], $this->rule->processNode(new Attribute(new Name('CoversClass')), $this->scope()));
    }

    private function scope(): CollectedDataEmitter&NodeCallbackInvoker&Scope
    {
        return $this->createMockForIntersectionOfInterfaces([CollectedDataEmitter::class, NodeCallbackInvoker::class, Scope::class]);
    }
}
