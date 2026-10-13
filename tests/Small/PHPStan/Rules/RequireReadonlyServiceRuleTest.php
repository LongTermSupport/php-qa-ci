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
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises every branch reachable with a mocked Scope and a reflection provider
 * that knows no class. The excluded-interface branch needs a real
 * PHPStan\Reflection\ClassReflection, which is final and cannot be doubled, so
 * RequireReadonlyServiceRuleAnalysisTest pins it against a fixture.
 *
 * @internal
 */
#[CoversClass(RequireReadonlyServiceRule::class)]
#[Small]
final class RequireReadonlyServiceRuleTest extends TestCase
{
    use ScopeStubTrait;

    private const string WIDGET_CLASS = 'Widget';

    private const string APP_DOMAIN = 'App\Domain';

    private RequireReadonlyServiceRule $rule;

    protected function setUp(): void
    {
        $this->rule = new RequireReadonlyServiceRule(self::createStub(ReflectionProvider::class));
    }

    #[Test]
    public function getNodeTypeIsClass(): void
    {
        self::assertSame(Class_::class, $this->rule->getNodeType());
    }

    #[Test]
    public function finalMutableServiceIsFlagged(): void
    {
        $errors = $this->rule->processNode($this->class(self::WIDGET_CLASS, Modifiers::FINAL), $this->scope(self::APP_DOMAIN));

        self::assertCount(1, $errors);
        self::assertSame(
            'Service class Widget should be "final readonly class". Mutable services cause hidden state bugs. If per-call state is needed, extract it into a context DTO.',
            $errors[0]->getMessage(),
        );
        self::assertSame(RequireReadonlyServiceRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function finalClassWhosePropertiesAreAllReadonlyIsFlagged(): void
    {
        $property = new Property(Modifiers::READONLY, [new PropertyItem('clock')]);
        $class    = $this->class(self::WIDGET_CLASS, Modifiers::FINAL, [], null, $property);

        self::assertCount(1, $this->rule->processNode($class, $this->scope(self::APP_DOMAIN)));
    }

    /** Doctrine's attribute is named however it was imported; PHPStan resolves it to its full name. */
    #[Test]
    public function entityAttributeByItsFullNameIsNotFlagged(): void
    {
        $class = $this->class(self::WIDGET_CLASS, Modifiers::FINAL, attributeNames: ['Doctrine\ORM\Mapping\Entity']);

        self::assertSame([], $this->rule->processNode($class, $this->scope(self::APP_DOMAIN)));
    }

    #[Test]
    public function classWithAnAttributeThatIsNotAnEntityIsFlagged(): void
    {
        $class = $this->class(self::WIDGET_CLASS, Modifiers::FINAL, attributeNames: ['App\Attribute\Audited']);

        self::assertCount(1, $this->rule->processNode($class, $this->scope(self::APP_DOMAIN)));
    }

    #[Test]
    public function finalReadonlyServiceIsNotFlagged(): void
    {
        $class = $this->class(self::WIDGET_CLASS, Modifiers::FINAL | Modifiers::READONLY);

        self::assertSame([], $this->rule->processNode($class, $this->scope(self::APP_DOMAIN)));
    }

    #[Test]
    public function nonFinalClassIsNotFlagged(): void
    {
        self::assertSame([], $this->rule->processNode($this->class(self::WIDGET_CLASS, 0), $this->scope(self::APP_DOMAIN)));
    }

    #[Test]
    public function abstractClassIsNotFlagged(): void
    {
        $class = $this->class(self::WIDGET_CLASS, Modifiers::ABSTRACT);

        self::assertSame([], $this->rule->processNode($class, $this->scope(self::APP_DOMAIN)));
    }

    #[Test]
    public function classWithExcludedSuffixIsNotFlagged(): void
    {
        $class = $this->class('WidgetController', Modifiers::FINAL);

        self::assertSame([], $this->rule->processNode($class, $this->scope(self::APP_DOMAIN)));
    }

    #[Test]
    public function classInExcludedNamespaceSegmentIsNotFlagged(): void
    {
        // FQCN App\Controller\Widget contains the "\Controller\" excluded segment.
        $class = $this->class(self::WIDGET_CLASS, Modifiers::FINAL);

        self::assertSame([], $this->rule->processNode($class, $this->scope('App\Controller')));
    }

    #[Test]
    public function doctrineEntityIsNotFlagged(): void
    {
        $class = $this->class(self::WIDGET_CLASS, Modifiers::FINAL, attributeNames: ['ORM\Entity']);

        self::assertSame([], $this->rule->processNode($class, $this->scope(self::APP_DOMAIN)));
    }

    #[Test]
    public function classThatExtendsAnotherIsNotFlagged(): void
    {
        $class = $this->class(self::WIDGET_CLASS, Modifiers::FINAL, extends: new Name('BaseWidget'));

        self::assertSame([], $this->rule->processNode($class, $this->scope(self::APP_DOMAIN)));
    }

    #[Test]
    public function classWithAMutablePropertyIsNotFlagged(): void
    {
        $property = new Property(0, [new PropertyItem('cache')]);
        $class    = $this->class(self::WIDGET_CLASS, Modifiers::FINAL, [], null, $property);

        self::assertSame([], $this->rule->processNode($class, $this->scope(self::APP_DOMAIN)));
    }

    #[Test]
    public function classWithNullNamespaceIsNotFlagged(): void
    {
        self::assertSame([], $this->rule->processNode($this->class(self::WIDGET_CLASS, Modifiers::FINAL), $this->scope(null)));
    }

    /** An anonymous class is never final, since PHP refuses `new final class`, so it never reaches the name. */
    #[Test]
    public function anonymousClassIsNotFlagged(): void
    {
        self::assertSame([], $this->rule->processNode($this->class(null, 0), $this->scope(self::APP_DOMAIN)));
    }

    /** The parser accepts `new final class`, which PHP then refuses to compile; the rule names no class for it. */
    #[Test]
    public function anonymousClassMarkedFinalIsNotFlagged(): void
    {
        self::assertSame([], $this->rule->processNode($this->class(null, Modifiers::FINAL), $this->scope(self::APP_DOMAIN)));
    }

    /** @param list<string> $attributeNames */
    private function class(?string $name, int $flags, array $attributeNames = [], ?Name $extends = null, \PhpParser\Node\Stmt ...$stmts): Class_
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

    private function scope(?string $namespace): CollectedDataEmitter&DependencyTracker&NodeCallbackInvoker&Scope
    {
        $scope = self::scopeStub();
        $scope->method('getNamespace')->willReturn($namespace);
        $scope->method('getClassReflection')->willReturn(null);

        return $scope;
    }
}
