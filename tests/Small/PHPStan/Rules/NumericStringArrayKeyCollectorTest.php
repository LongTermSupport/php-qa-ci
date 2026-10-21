<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\NumericStringArrayKeyCollector;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ClosureUse;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\VariadicPlaceholder;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\IntegerType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * The rule's own test proves the collector against analysed code; this pins its edges.
 *
 * @internal
 */
#[CoversClass(NumericStringArrayKeyCollector::class)]
#[Medium]
final class NumericStringArrayKeyCollectorTest extends PHPStanTestCase
{
    use ScopeStubTrait;

    private const string MAP = 'map';

    #[Test]
    public function aClosureIsRecordedWithTheVariablesItImports(): void
    {
        $closure = new Closure(['uses' => [new ClosureUse(new Variable('path')), new ClosureUse(new Variable(new Variable('dynamic')))]]);

        $records = $this->collector()->processNode($closure, $this->scopeTyping(new StringType()));

        self::assertNotNull($records);
        self::assertSame(NumericStringArrayKeyCollector::CLOSURE, $records[0]['kind']);
        self::assertSame(['path'], $records[0]['uses']);
    }

    #[Test]
    public function aWriteUnderAStringKeyIsRecordedAgainstTheVariableAtItsDepth(): void
    {
        $write = new Assign(new ArrayDimFetch(new ArrayDimFetch(new Variable(self::MAP), new Variable('a')), new Variable('b')), new String_('x'));

        $records = $this->collector()->processNode($write, $this->scopeTyping(new StringType()));

        self::assertNotNull($records);
        self::assertSame([[0], [1]], array_column($records, 'depths'));
        self::assertSame(['$map', '$map[…]'], array_column($records, 'text'));
    }

    #[Test]
    public function aWriteUnderAKeyThatCannotBeAllDigitsIsNotRecorded(): void
    {
        $collector = $this->collector();
        $write     = new Assign(new ArrayDimFetch(new Variable(self::MAP), new Variable('key')), new String_('x'));

        self::assertNull($collector->processNode($write, $this->scopeTyping(new IntegerType())));
        self::assertNull($collector->processNode($write, $this->scopeTyping(new ConstantStringType('2024'))));
        self::assertNull($collector->processNode($write, $this->scopeTyping(new ConstantStringType('src'))));
        self::assertNull($collector->processNode(new Assign(new ArrayDimFetch(new Variable(self::MAP)), new String_('x')), $this->scopeTyping(new StringType())));
    }

    #[Test]
    public function aFirstClassCallableReadsNoKeys(): void
    {
        $callable = new FuncCall(new Name('array_keys'), [new VariadicPlaceholder()]);

        self::assertNull($this->collector()->processNode($callable, $this->scopeTyping(new StringType())));
        self::assertNull($this->collector()->processNode(new Arg(new Variable(self::MAP)), $this->scopeTyping(new StringType())));
    }

    private function collector(): NumericStringArrayKeyCollector
    {
        return new NumericStringArrayKeyCollector(self::createReflectionProvider(), self::getContainer()->getByType(FileTypeMapper::class));
    }

    private function scopeTyping(Type $type): CollectedDataEmitter&DependencyTracker&NodeCallbackInvoker&Scope
    {
        $scope = self::scopeStub();
        $scope->method('getType')->willReturn($type);
        $scope->method('getFunctionName')->willReturn('check');

        return $scope;
    }
}
