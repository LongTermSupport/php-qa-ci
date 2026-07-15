<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidDeprecatedSerializableRule;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
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
#[CoversClass(ForbidDeprecatedSerializableRule::class)]
#[Small]
#[AllowMockObjectsWithoutExpectations]
final class ForbidDeprecatedSerializableRuleTest extends TestCase
{
    private ForbidDeprecatedSerializableRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ForbidDeprecatedSerializableRule();
    }

    #[Test]
    public function getNodeTypeIsClass(): void
    {
        self::assertSame(Class_::class, $this->rule->getNodeType());
    }

    #[Test]
    public function classImplementingSerializableIsFlagged(): void
    {
        $class  = new Class_('LegacyDto', ['implements' => [new Name('Serializable')]]);
        $errors = $this->rule->processNode($class, $this->scopeResolvingTo('Serializable'));

        self::assertCount(1, $errors);
        self::assertStringContainsString('Class LegacyDto implements the deprecated Serializable interface', $errors[0]->getMessage());
        self::assertSame(ForbidDeprecatedSerializableRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function classImplementingAnotherInterfaceIsNotFlagged(): void
    {
        $class = new Class_('Money', ['implements' => [new Name('Countable')]]);

        self::assertSame([], $this->rule->processNode($class, $this->scopeResolvingTo('Countable')));
    }

    #[Test]
    public function classWithNoInterfacesIsNotFlagged(): void
    {
        $class = new Class_('Plain', []);

        self::assertSame([], $this->rule->processNode($class, $this->scopeResolvingTo('Whatever')));
    }

    #[Test]
    public function anonymousClassImplementingSerializableIsReportedWithAnonymousName(): void
    {
        $class  = new Class_(null, ['implements' => [new Name('Serializable')]]);
        $errors = $this->rule->processNode($class, $this->scopeResolvingTo('Serializable'));

        self::assertCount(1, $errors);
        self::assertStringContainsString('Class anonymous implements', $errors[0]->getMessage());
    }

    private function scopeResolvingTo(string $resolved): CollectedDataEmitter&NodeCallbackInvoker&Scope
    {
        $scope = $this->createMockForIntersectionOfInterfaces([CollectedDataEmitter::class, NodeCallbackInvoker::class, Scope::class]);
        $scope->method('resolveName')->willReturn($resolved);

        return $scope;
    }
}
