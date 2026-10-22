<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ClassNameVariableWriteCollector;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ClassStringType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rule's own test proves the collector against analysed code; this pins its edges.
 *
 * @internal
 */
#[CoversClass(ClassNameVariableWriteCollector::class)]
#[Small]
final class ClassNameVariableWriteCollectorTest extends TestCase
{
    use ScopeStubTrait;

    private const string CLASS_NAME = 'className';

    private const string FUNCTION = 'check';

    #[Test]
    public function aVariableAssignedAClassNameIsCollectedUnderItsFunction(): void
    {
        $assign = new Assign(new Variable(self::CLASS_NAME), new Variable('class'));

        self::assertSame(['::' . self::FUNCTION, self::CLASS_NAME], new ClassNameVariableWriteCollector()->processNode($assign, $this->scopeTyping(new ClassStringType())));
    }

    #[Test]
    public function aConcatenationStartingWithANamespacedNameIsAClassName(): void
    {
        $name   = new Concat(new Concat(new PropertyFetch(new Variable('node'), 'namespacedName'), new String_('\\')), new Variable('short'));
        $assign = new Assign(new Variable(self::CLASS_NAME), $name);

        self::assertNotNull(new ClassNameVariableWriteCollector()->processNode($assign, $this->scopeTyping(new StringType())));
    }

    #[Test]
    public function aVariableAssignedAPlainStringIsNotCollected(): void
    {
        $assign = new Assign(new Variable('path'), new Concat(new Variable('root'), new String_('/src')));

        self::assertNull(new ClassNameVariableWriteCollector()->processNode($assign, $this->scopeTyping(new StringType())));
    }

    #[Test]
    public function aWriteToSomethingOtherThanANamedVariableIsNotCollected(): void
    {
        $collector = new ClassNameVariableWriteCollector();
        $scope     = $this->scopeTyping(new ClassStringType());
        $class     = new Variable('class');

        self::assertNull($collector->processNode(new Assign(new PropertyFetch(new Variable('this'), 'name'), $class), $scope));
        self::assertNull($collector->processNode(new Assign(new Variable(new Variable('dynamic')), $class), $scope));
    }

    private function scopeTyping(Type $type): CollectedDataEmitter&DependencyTracker&NodeCallbackInvoker&Scope
    {
        $scope = self::scopeStub();
        $scope->method('getType')->willReturn($type);
        $scope->method('getFunctionName')->willReturn(self::FUNCTION);

        return $scope;
    }
}
