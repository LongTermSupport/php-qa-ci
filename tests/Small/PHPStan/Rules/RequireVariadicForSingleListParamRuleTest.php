<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\RequireVariadicForSingleListParamRule;
use PhpParser\Comment\Doc;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversClass(RequireVariadicForSingleListParamRule::class)]
#[\PHPUnit\Framework\Attributes\Small]
final class RequireVariadicForSingleListParamRuleTest extends TestCase
{
    use ScopeStubTrait;

    private const string METHOD_RENDER = 'render';

    private const string PARAM_ITEMS = 'items';

    private const string PARAM_LIST_STRING_ITEMS = '/** @param list<string> $items */';

    private const string TYPE_ARRAY = 'array';

    private RequireVariadicForSingleListParamRule $rule;

    protected function setUp(): void
    {
        $this->rule = new RequireVariadicForSingleListParamRule();
    }

    public function testGetNodeType(): void
    {
        self::assertSame(ClassMethod::class, $this->rule->getNodeType());
    }

    public function testSingleArrayParamWithListDocblockIsFlagged(): void
    {
        $method = $this->makeMethod(
            self::METHOD_RENDER,
            [$this->makeArrayParam(self::PARAM_ITEMS)],
            '/** @param list<ProductEnquiryItem> $items */',
        );

        self::assertCount(1, $this->rule->processNode($method, self::scopeStub()));
    }

    public function testErrorMessageContainsMethodAndParamName(): void
    {
        $method = $this->makeMethod(
            'process',
            [$this->makeArrayParam('records')],
            '/** @param list<string> $records */',
        );

        $errors = $this->rule->processNode($method, self::scopeStub());

        self::assertCount(1, $errors);
        self::assertStringContainsString('process()', $errors[0]->getMessage());
        self::assertStringContainsString('$records', $errors[0]->getMessage());
    }

    public function testMultipleParamsAreNotFlagged(): void
    {
        $method = $this->makeMethod(
            self::METHOD_RENDER,
            [$this->makeArrayParam(self::PARAM_ITEMS), $this->makeArrayParam('extra')],
            self::PARAM_LIST_STRING_ITEMS,
        );

        self::assertSame([], $this->rule->processNode($method, self::scopeStub()));
    }

    public function testZeroParamsAreNotFlagged(): void
    {
        $method = $this->makeMethod(self::METHOD_RENDER, [], '/** no params */');

        self::assertSame([], $this->rule->processNode($method, self::scopeStub()));
    }

    public function testNoDocblockIsNotFlagged(): void
    {
        $method = $this->makeMethod(self::METHOD_RENDER, [$this->makeArrayParam(self::PARAM_ITEMS)], null);

        self::assertSame([], $this->rule->processNode($method, self::scopeStub()));
    }

    public function testArrayDocblockIsNotFlagged(): void
    {
        $method = $this->makeMethod(
            self::METHOD_RENDER,
            [$this->makeArrayParam(self::PARAM_ITEMS)],
            '/** @param array<string> $items */',
        );

        self::assertSame([], $this->rule->processNode($method, self::scopeStub()));
    }

    public function testIterableDocblockIsNotFlagged(): void
    {
        $method = $this->makeMethod(
            self::METHOD_RENDER,
            [$this->makeArrayParam(self::PARAM_ITEMS)],
            '/** @param iterable<string> $items */',
        );

        self::assertSame([], $this->rule->processNode($method, self::scopeStub()));
    }

    public function testAlreadyVariadicIsNotFlagged(): void
    {
        $param  = new Param(new Variable(self::PARAM_ITEMS), null, new Identifier(self::TYPE_ARRAY), false, true);
        $method = $this->makeMethod(
            self::METHOD_RENDER,
            [$param],
            self::PARAM_LIST_STRING_ITEMS,
        );

        self::assertSame([], $this->rule->processNode($method, self::scopeStub()));
    }

    public function testNonArrayTypeIsNotFlagged(): void
    {
        $param  = new Param(new Variable(self::PARAM_ITEMS), null, new Identifier('string'));
        $method = $this->makeMethod(self::METHOD_RENDER, [$param], self::PARAM_LIST_STRING_ITEMS);

        self::assertSame([], $this->rule->processNode($method, self::scopeStub()));
    }

    public function testNullableArrayTypeIsNotFlagged(): void
    {
        $nullableType = new \PhpParser\Node\NullableType(new Identifier(self::TYPE_ARRAY));
        $param        = new Param(new Variable(self::PARAM_ITEMS), null, $nullableType);
        $method       = $this->makeMethod(self::METHOD_RENDER, [$param], self::PARAM_LIST_STRING_ITEMS);

        self::assertSame([], $this->rule->processNode($method, self::scopeStub()));
    }

    public function testUntypedParamIsNotFlagged(): void
    {
        $param  = new Param(new Variable(self::PARAM_ITEMS));
        $method = $this->makeMethod(self::METHOD_RENDER, [$param], self::PARAM_LIST_STRING_ITEMS);

        self::assertSame([], $this->rule->processNode($method, self::scopeStub()));
    }

    public function testNestedGenericListIsFlagged(): void
    {
        $method = $this->makeMethod(
            self::METHOD_RENDER,
            [$this->makeArrayParam(self::PARAM_ITEMS)],
            '/** @param list<Type<A, B>> $items */',
        );

        self::assertCount(1, $this->rule->processNode($method, self::scopeStub()));
    }

    /**
     * @param list<Param> $params
     */
    private function makeMethod(string $name, array $params, ?string $docComment): ClassMethod
    {
        $attributes = [];

        if (null !== $docComment) {
            $attributes['comments'] = [new Doc($docComment)];
        }

        return new ClassMethod($name, ['params' => $params], $attributes);
    }

    private function makeArrayParam(string $name): Param
    {
        return new Param(new Variable($name), null, new Identifier(self::TYPE_ARRAY));
    }
}
