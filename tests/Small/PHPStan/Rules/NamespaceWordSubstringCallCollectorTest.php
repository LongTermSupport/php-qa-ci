<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ClassNameVariableWriteCollector;
use LTS\PHPQA\PHPStan\Rules\NamespaceWordSubstringCallCollector;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ClassStringType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The rule's own test proves the collector against analysed code; this pins its edges.
 *
 * @internal
 */
#[CoversClass(NamespaceWordSubstringCallCollector::class)]
#[UsesClass(ClassNameVariableWriteCollector::class)]
#[Small]
final class NamespaceWordSubstringCallCollectorTest extends TestCase
{
    use ScopeStubTrait;

    private const string STR_CONTAINS = 'str_contains';

    private const string HAYSTACK = 'className';

    private const string GENERATED = 'Generated';

    private const string TESTS = 'Tests';

    #[Test]
    public function aWordSearchedForInAClassNameIsCollectedWithNoVariableToFollow(): void
    {
        $call = $this->funcCall(self::STR_CONTAINS, new Variable(self::HAYSTACK), new String_(self::GENERATED));

        self::assertSame(
            [self::STR_CONTAINS, [self::GENERATED], 7, '::check', null],
            new NamespaceWordSubstringCallCollector()->processNode($call, $this->scope(new ClassStringType(), new ConstantStringType(self::GENERATED))),
        );
    }

    #[Test]
    public function aWordSearchedForInAVariableIsCollectedForTheRuleToFollowItsWrites(): void
    {
        $call  = $this->funcCall('\STRPOS', new Variable(self::HAYSTACK), new Variable('word'));
        $words = new UnionType([new ConstantStringType('PHPStan'), new ConstantStringType(self::GENERATED), new ConstantStringType(self::TESTS)]);

        self::assertSame(
            ['strpos', [self::GENERATED, 'PHPStan'], 7, '::check', self::HAYSTACK],
            new NamespaceWordSubstringCallCollector()->processNode($call, $this->scope(new StringType(), $words)),
        );
    }

    #[Test]
    public function aCallThatIsNotAnAnywhereMatchOfABareWordInAPossibleNameIsNotCollected(): void
    {
        $collector = new NamespaceWordSubstringCallCollector();
        $calls     = [
            [$this->funcCall('str_ends_with', new Variable(self::HAYSTACK), new String_(self::GENERATED)), new ConstantStringType(self::GENERATED)],
            [$this->funcCall(self::STR_CONTAINS, new Variable(self::HAYSTACK), new String_('\Generated\\')), new ConstantStringType('\Generated\\')],
            [$this->funcCall(self::STR_CONTAINS, new Variable(self::HAYSTACK), new String_(self::TESTS)), new ConstantStringType(self::TESTS)],
            [$this->funcCall(self::STR_CONTAINS, new Variable(self::HAYSTACK), new Variable('needle')), new StringType()],
            [$this->funcCall(self::STR_CONTAINS, new PropertyFetch(new Variable('this'), 'name'), new String_(self::GENERATED)), new ConstantStringType(self::GENERATED)],
            [$this->funcCall(self::STR_CONTAINS, new Variable(self::HAYSTACK)), new ConstantStringType(self::GENERATED)],
            [new FuncCall(new Variable('function'), [new Arg(new Variable(self::HAYSTACK)), new Arg(new String_(self::GENERATED))]), new ConstantStringType(self::GENERATED)],
        ];

        foreach ($calls as [$call, $needleType]) {
            self::assertNull($collector->processNode($call, $this->scope(new StringType(), $needleType)));
        }
    }

    private function funcCall(string $function, Expr ...$arguments): FuncCall
    {
        return new FuncCall(new Name($function), array_map(static fn (Expr $argument): Arg => new Arg($argument), $arguments), ['startLine' => 7]);
    }

    /** A scope typing the `$className` subject and the needle as given, and a property a plain string. */
    private function scope(Type $haystack, Type $needle): CollectedDataEmitter&DependencyTracker&NodeCallbackInvoker&Scope
    {
        $scope = self::scopeStub();
        $scope->method('getType')->willReturnCallback(
            static fn (Expr $expr): Type => match (true) {
                $expr instanceof PropertyFetch                                 => new StringType(),
                $expr instanceof Variable && self::HAYSTACK === $expr->name    => $haystack,
                default                                                        => $needle,
            },
        );
        $scope->method('getFunctionName')->willReturn('check');

        return $scope;
    }
}
