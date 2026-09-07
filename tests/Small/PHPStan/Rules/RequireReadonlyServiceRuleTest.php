<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\RequireReadonlyServiceRule;
use PhpParser\Modifiers;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Name;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises every branch reachable with a mocked Scope. The excluded-interface
 * branch relies on scope->getClassReflection() returning a real
 * PHPStan\Reflection\ClassReflection, which is final and cannot be doubled, so
 * that single skip path is not covered here (mirrors FactorySealedRuleTest's
 * documented ClassReflection limitation).
 *
 * @internal
 */
#[CoversClass(RequireReadonlyServiceRule::class)]
#[Small]
final class RequireReadonlyServiceRuleTest extends TestCase
{
    use ScopeStubTrait;

    private RequireReadonlyServiceRule $rule;

    protected function setUp(): void
    {
        $this->rule = new RequireReadonlyServiceRule();
    }

    #[Test]
    public function getNodeTypeIsClass(): void
    {
        self::assertSame(Class_::class, $this->rule->getNodeType());
    }

    #[Test]
    public function finalMutableServiceIsFlagged(): void
    {
        $errors = $this->rule->processNode($this->class('Widget', Modifiers::FINAL), $this->scope('App\Domain'));

        self::assertCount(1, $errors);
        self::assertStringContainsString('should be "final readonly class"', $errors[0]->getMessage());
        self::assertSame(RequireReadonlyServiceRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function finalReadonlyServiceIsNotFlagged(): void
    {
        $class = $this->class('Widget', Modifiers::FINAL | Modifiers::READONLY);

        self::assertSame([], $this->rule->processNode($class, $this->scope('App\Domain')));
    }

    #[Test]
    public function nonFinalClassIsNotFlagged(): void
    {
        self::assertSame([], $this->rule->processNode($this->class('Widget', 0), $this->scope('App\Domain')));
    }

    #[Test]
    public function abstractClassIsNotFlagged(): void
    {
        $class = $this->class('Widget', Modifiers::ABSTRACT);

        self::assertSame([], $this->rule->processNode($class, $this->scope('App\Domain')));
    }

    #[Test]
    public function classWithExcludedSuffixIsNotFlagged(): void
    {
        $class = $this->class('WidgetController', Modifiers::FINAL);

        self::assertSame([], $this->rule->processNode($class, $this->scope('App\Domain')));
    }

    #[Test]
    public function classInExcludedNamespaceSegmentIsNotFlagged(): void
    {
        // FQCN App\Controller\Widget contains the "\Controller\" excluded segment.
        $class = $this->class('Widget', Modifiers::FINAL);

        self::assertSame([], $this->rule->processNode($class, $this->scope('App\Controller')));
    }

    #[Test]
    public function doctrineEntityIsNotFlagged(): void
    {
        $class = $this->class('Widget', Modifiers::FINAL, attributeNames: ['ORM\Entity']);

        self::assertSame([], $this->rule->processNode($class, $this->scope('App\Domain')));
    }

    #[Test]
    public function classThatExtendsAnotherIsNotFlagged(): void
    {
        $class = $this->class('Widget', Modifiers::FINAL, extends: new Name('BaseWidget'));

        self::assertSame([], $this->rule->processNode($class, $this->scope('App\Domain')));
    }

    #[Test]
    public function classWithAMutablePropertyIsNotFlagged(): void
    {
        $property = new Property(0, [new PropertyItem('cache')]);
        $class    = $this->class('Widget', Modifiers::FINAL, stmts: [$property]);

        self::assertSame([], $this->rule->processNode($class, $this->scope('App\Domain')));
    }

    #[Test]
    public function classWithNullNamespaceIsNotFlagged(): void
    {
        self::assertSame([], $this->rule->processNode($this->class('Widget', Modifiers::FINAL), $this->scope(null)));
    }

    /**
     * @param list<string>               $attributeNames
     * @param list<\PhpParser\Node\Stmt> $stmts
     */
    private function class(string $name, int $flags, array $attributeNames = [], ?Name $extends = null, array $stmts = []): Class_
    {
        $attrGroups = [];
        foreach ($attributeNames as $attributeName) {
            $attrGroups[] = new AttributeGroup([new Attribute(new Name($attributeName))]);
        }

        return new Class_($name, [
            'flags'      => $flags,
            'attrGroups' => $attrGroups,
            'extends'    => $extends,
            'stmts'      => $stmts,
        ]);
    }

    private function scope(?string $namespace): CollectedDataEmitter&NodeCallbackInvoker&Scope
    {
        $scope = self::scopeStub();
        $scope->method('getNamespace')->willReturn($namespace);
        $scope->method('getClassReflection')->willReturn(null);

        return $scope;
    }
}
