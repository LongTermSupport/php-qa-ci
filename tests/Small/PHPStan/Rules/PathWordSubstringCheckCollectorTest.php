<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\AbsolutePathExpression;
use LTS\PHPQA\PHPStan\Rules\PathWordSubstringCheckCollector;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\MagicConst\Dir;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * What the collector gathers is driven through ForbidPathWordSubstringCheckRuleTest's fixtures;
 * this pins what it ignores before it reads a type.
 *
 * @internal
 */
#[CoversClass(PathWordSubstringCheckCollector::class)]
#[UsesClass(AbsolutePathExpression::class)]
#[Small]
final class PathWordSubstringCheckCollectorTest extends TestCase
{
    use ScopeStubTrait;

    private const string SEARCH = 'str_contains';

    private const string WORD = 'Magento';

    #[Test]
    public function itVisitsEveryNode(): void
    {
        self::assertSame(Node::class, new PathWordSubstringCheckCollector()->getNodeType());
    }

    #[Test]
    public function aCallToAnotherFunctionOrADynamicNameIsNotCollected(): void
    {
        $collector = new PathWordSubstringCheckCollector();

        self::assertNull($collector->processNode(new FuncCall(new Name('strlen'), []), self::scopeStub()));
        self::assertNull($collector->processNode(new FuncCall(new String_(self::SEARCH), []), self::scopeStub()));
    }

    #[Test]
    public function aCallMissingAnArgumentIsNotCollected(): void
    {
        $collector = new PathWordSubstringCheckCollector();

        self::assertNull($collector->processNode(new FuncCall(new Name(self::SEARCH), []), self::scopeStub()));
        self::assertNull($collector->processNode(new FuncCall(new Name(self::SEARCH), [new Arg(new Dir())]), self::scopeStub()));
    }

    #[Test]
    public function aVariableVariableHaystackIsNotCollected(): void
    {
        $call = new FuncCall(new Name(self::SEARCH), [new Arg(new Variable(new Variable('name'))), new Arg(new String_(self::WORD))]);

        self::assertNull(new PathWordSubstringCheckCollector()->processNode($call, self::scopeStub()));
    }

    #[Test]
    public function outsideAnyClassOrFunctionTheKeyIsEmptyAndNoVariableIsAParameter(): void
    {
        $collector = new PathWordSubstringCheckCollector();
        $search    = new FuncCall(new Name(self::SEARCH), [new Arg(new Variable('x')), new Arg(new String_(self::WORD))]);

        self::assertSame(['::', [['x', -1, self::SEARCH, '$x', self::WORD]], []], $collector->processNode($search, self::scopeStub()));
        self::assertSame(['::', [], [['x', true]]], $collector->processNode(new Assign(new Variable('x'), new Dir()), self::scopeStub()));
    }
}
