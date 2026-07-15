<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\RequireExplicitDIAttributeRule;
use PhpParser\Modifiers;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
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
#[CoversClass(RequireExplicitDIAttributeRule::class)]
#[Small]
#[AllowMockObjectsWithoutExpectations]
final class RequireExplicitDIAttributeRuleTest extends TestCase
{
    private RequireExplicitDIAttributeRule $rule;

    protected function setUp(): void
    {
        $this->rule = new RequireExplicitDIAttributeRule();
    }

    #[Test]
    public function getNodeTypeIsClass(): void
    {
        self::assertSame(Class_::class, $this->rule->getNodeType());
    }

    #[Test]
    public function classWithNoDiAttributeIsFlagged(): void
    {
        $errors = $this->rule->processNode($this->class('Widget'), $this->scope('App\Domain'));

        self::assertCount(1, $errors);
        self::assertStringContainsString('must explicitly declare DI status', $errors[0]->getMessage());
        self::assertSame(RequireExplicitDIAttributeRule::IDENTIFIER_REQUIRE_EXPLICIT_DI_ATTRIBUTE, $errors[0]->getIdentifier());
    }

    #[Test]
    public function classWithAutoconfigureIsNotFlagged(): void
    {
        $class = $this->class('WidgetService', ['Autoconfigure']);

        self::assertSame([], $this->rule->processNode($class, $this->scope('App\Domain')));
    }

    #[Test]
    public function classWithExcludeIsNotFlagged(): void
    {
        $class = $this->class('WidgetDTO', ['Exclude']);

        self::assertSame([], $this->rule->processNode($class, $this->scope('App\Domain')));
    }

    #[Test]
    public function commandWithAsCommandCountsAsAService(): void
    {
        $class = $this->class('DoThing', ['Symfony\Component\Console\Attribute\AsCommand']);

        self::assertSame([], $this->rule->processNode($class, $this->scope('App\Domain')));
    }

    #[Test]
    public function classWithBothAutoconfigureAndExcludeIsConflicting(): void
    {
        $class  = $this->class('Widget', ['Autoconfigure', 'Exclude']);
        $errors = $this->rule->processNode($class, $this->scope('App\Domain'));

        self::assertCount(1, $errors);
        self::assertStringContainsString('cannot have both', $errors[0]->getMessage());
        self::assertSame(RequireExplicitDIAttributeRule::IDENTIFIER_CONFLICTING_DI_ATTRIBUTES, $errors[0]->getIdentifier());
    }

    #[Test]
    public function abstractClassIsSkipped(): void
    {
        $class = $this->class('AbstractWidget', [], Modifiers::ABSTRACT);

        self::assertSame([], $this->rule->processNode($class, $this->scope('App\Domain')));
    }

    #[Test]
    public function phpAttributeClassIsExempt(): void
    {
        // A class annotated #[Attribute] is a PHP attribute, never a DI service.
        $class = $this->class('MyMarker', ['Attribute']);

        self::assertSame([], $this->rule->processNode($class, $this->scope('App\Domain')));
    }

    #[Test]
    public function classInAnAllowedNamespaceIsSkipped(): void
    {
        // className contains "Tests" → exempt without any attribute.
        $class = $this->class('Widget');

        self::assertSame([], $this->rule->processNode($class, $this->scope('App\Tests\Domain')));
    }

    #[Test]
    public function kernelClassIsSkipped(): void
    {
        $class = $this->class('Kernel');

        self::assertSame([], $this->rule->processNode($class, $this->scope('App')));
    }

    #[Test]
    public function hintReflectsExceptionNaming(): void
    {
        $errors = $this->rule->processNode($this->class('PriceException'), $this->scope('App\Domain'));

        self::assertCount(1, $errors);
        self::assertStringContainsString('Exceptions should use #[Exclude]', $errors[0]->getMessage());
    }

    /**
     * @param list<string> $attributeNames
     */
    private function class(string $name, array $attributeNames = [], int $flags = 0): Class_
    {
        $attrGroups = [];
        foreach ($attributeNames as $attributeName) {
            $attrGroups[] = new AttributeGroup([new Attribute(new Name($attributeName))]);
        }

        return new Class_($name, ['flags' => $flags, 'attrGroups' => $attrGroups]);
    }

    private function scope(string $namespace): CollectedDataEmitter&NodeCallbackInvoker&Scope
    {
        $scope = $this->createMockForIntersectionOfInterfaces([CollectedDataEmitter::class, NodeCallbackInvoker::class, Scope::class]);
        $scope->method('getNamespace')->willReturn($namespace);

        return $scope;
    }
}
