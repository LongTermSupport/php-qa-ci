<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\RequireExplicitDIAttributeRule;
use LTS\PHPQA\PHPStan\Rules\TestCodeDetector;
use PhpParser\Modifiers;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
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
#[CoversClass(RequireExplicitDIAttributeRule::class)]
#[UsesClass(TestCodeDetector::class)]
#[Small]
final class RequireExplicitDIAttributeRuleTest extends TestCase
{
    use ScopeStubTrait;

    private const string WIDGET_CLASS = 'Widget';

    private const string APP_DOMAIN = 'App\Domain';

    private const string ROOT = '/app';

    private RequireExplicitDIAttributeRule $rule;

    protected function setUp(): void
    {
        $this->rule = new RequireExplicitDIAttributeRule(new TestCodeDetector(self::ROOT));
    }

    #[Test]
    public function getNodeTypeIsClass(): void
    {
        self::assertSame(Class_::class, $this->rule->getNodeType());
    }

    #[Test]
    public function classWithNoDiAttributeIsFlagged(): void
    {
        $errors = $this->rule->processNode($this->class(self::WIDGET_CLASS), $this->scope(self::APP_DOMAIN));

        self::assertCount(1, $errors);
        self::assertStringContainsString('must explicitly declare DI status', $errors[0]->getMessage());
        self::assertSame(RequireExplicitDIAttributeRule::IDENTIFIER_REQUIRE_EXPLICIT_DI_ATTRIBUTE, $errors[0]->getIdentifier());
    }

    #[Test]
    public function classWithAutoconfigureIsNotFlagged(): void
    {
        $class = $this->class('WidgetService', ['Autoconfigure']);

        self::assertSame([], $this->rule->processNode($class, $this->scope(self::APP_DOMAIN)));
    }

    #[Test]
    public function classWithExcludeIsNotFlagged(): void
    {
        $class = $this->class('WidgetDTO', ['Exclude']);

        self::assertSame([], $this->rule->processNode($class, $this->scope(self::APP_DOMAIN)));
    }

    #[Test]
    public function commandWithAsCommandCountsAsAService(): void
    {
        $class = $this->class('DoThing', [\Symfony\Component\Console\Attribute\AsCommand::class]);

        self::assertSame([], $this->rule->processNode($class, $this->scope(self::APP_DOMAIN)));
    }

    #[Test]
    public function classWithBothAutoconfigureAndExcludeIsConflicting(): void
    {
        $class  = $this->class(self::WIDGET_CLASS, ['Autoconfigure', 'Exclude']);
        $errors = $this->rule->processNode($class, $this->scope(self::APP_DOMAIN));

        self::assertCount(1, $errors);
        self::assertStringContainsString('cannot have both', $errors[0]->getMessage());
        self::assertSame(RequireExplicitDIAttributeRule::IDENTIFIER_CONFLICTING_DI_ATTRIBUTES, $errors[0]->getIdentifier());
    }

    #[Test]
    public function abstractClassIsSkipped(): void
    {
        $class = $this->class('AbstractWidget', [], Modifiers::ABSTRACT);

        self::assertSame([], $this->rule->processNode($class, $this->scope(self::APP_DOMAIN)));
    }

    #[Test]
    public function phpAttributeClassIsExempt(): void
    {
        // A class annotated #[Attribute] is a PHP attribute, never a DI service.
        $class = $this->class('MyMarker', ['Attribute']);

        self::assertSame([], $this->rule->processNode($class, $this->scope(self::APP_DOMAIN)));
    }

    #[Test]
    public function classInAnAllowedNamespaceIsSkipped(): void
    {
        // A namespace segment ending in "Tests" → exempt without any attribute.
        foreach (['App\Tests\Domain', 'App\FunctionalTests\Domain'] as $namespace) {
            self::assertSame([], $this->rule->processNode($this->class(self::WIDGET_CLASS), $this->scope($namespace)), $namespace);
        }
    }

    /** A namespace one of whose segments is exactly PHPStan, Migrations or Generated is exempt. */
    #[Test]
    public function classInAnExemptNamespaceSegmentIsSkipped(): void
    {
        foreach (['App\Migrations', 'Vendor\Pkg\PHPStan', 'App\Generated\Api', 'Generated'] as $namespace) {
            self::assertSame([], $this->rule->processNode($this->class(self::WIDGET_CLASS), $this->scope($namespace)), $namespace);
        }
    }

    /** A segment that only contains the word, or differs in case, is not the exempt namespace. */
    #[Test]
    public function classInANamespaceThatOnlyContainsAnExemptWordIsFlagged(): void
    {
        foreach (['App\GeneratedReports', 'App\PHPStanAdapter', 'App\DataMigrations', 'App\generated', 'App\Billing'] as $namespace) {
            self::assertCount(1, $this->rule->processNode($this->class(self::WIDGET_CLASS), $this->scope($namespace)), $namespace);
        }
    }

    /** The class's own name is not a namespace segment. */
    #[Test]
    public function classNamedLikeAnExemptNamespaceIsFlagged(): void
    {
        foreach (['Generated', 'PHPStanRuleHelper', 'MigrationsRunner'] as $name) {
            self::assertCount(1, $this->rule->processNode($this->class($name), $this->scope(self::APP_DOMAIN)), $name);
        }
    }

    #[Test]
    public function classInTheGlobalNamespaceIsChecked(): void
    {
        self::assertCount(1, $this->rule->processNode($this->class(self::WIDGET_CLASS), $this->scope(null)));
    }

    #[Test]
    public function classInAFileUnderTheProjectsTestsIsSkipped(): void
    {
        self::assertSame([], $this->rule->processNode($this->class(self::WIDGET_CLASS), $this->scope(self::APP_DOMAIN, self::ROOT . '/tests/Widget.php')));
    }

    /** `TestsHelper` contains `Tests` but no segment ends with it; `Contests` ends in lower-case `tests`. */
    #[Test]
    public function classInANamespaceThatOnlyContainsTheWordIsFlagged(): void
    {
        foreach (['App\TestsHelper\Domain', 'App\Contests\Domain'] as $namespace) {
            self::assertCount(1, $this->rule->processNode($this->class(self::WIDGET_CLASS), $this->scope($namespace)), $namespace);
        }
    }

    /** A checkout under a directory named tests: its production classes are still checked. */
    #[Test]
    public function classInProductionCodeOfACheckoutUnderATestsDirectoryIsFlagged(): void
    {
        $rule = new RequireExplicitDIAttributeRule(new TestCodeDetector('/home/ci/tests/app'));

        self::assertCount(1, $rule->processNode($this->class(self::WIDGET_CLASS), $this->scope(self::APP_DOMAIN, '/home/ci/tests/app/src/Domain/Widget.php')));
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
        $errors = $this->rule->processNode($this->class('PriceException'), $this->scope(self::APP_DOMAIN));

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

    private function scope(?string $namespace, string $file = self::ROOT . '/src/Domain/Widget.php'): CollectedDataEmitter&DependencyTracker&NodeCallbackInvoker&Scope
    {
        $scope = self::scopeStub();
        $scope->method('getNamespace')->willReturn($namespace);
        $scope->method('getFile')->willReturn($file);

        return $scope;
    }
}
