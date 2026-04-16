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
            'render',
            [$this->makeArrayParam('items')],
            '/** @param list<ProductEnquiryItem> $items */',
        );

        self::assertCount(1, $this->rule->processNode($method, $this->createStub(\PHPStan\Analyser\Scope::class)));
    }

    public function testErrorMessageContainsMethodAndParamName(): void
    {
        $method = $this->makeMethod(
            'process',
            [$this->makeArrayParam('records')],
            '/** @param list<string> $records */',
        );

        $errors = $this->rule->processNode($method, $this->createStub(\PHPStan\Analyser\Scope::class));

        self::assertCount(1, $errors);
        self::assertStringContainsString('process()', (string) $errors[0]->getMessage());
        self::assertStringContainsString('$records', (string) $errors[0]->getMessage());
    }

    public function testMultipleParamsAreNotFlagged(): void
    {
        $method = $this->makeMethod(
            'render',
            [$this->makeArrayParam('items'), $this->makeArrayParam('extra')],
            '/** @param list<string> $items */',
        );

        self::assertSame([], $this->rule->processNode($method, $this->createStub(\PHPStan\Analyser\Scope::class)));
    }

    public function testZeroParamsAreNotFlagged(): void
    {
        $method = $this->makeMethod('render', [], '/** no params */');

        self::assertSame([], $this->rule->processNode($method, $this->createStub(\PHPStan\Analyser\Scope::class)));
    }

    public function testNoDocblockIsNotFlagged(): void
    {
        $method = $this->makeMethod('render', [$this->makeArrayParam('items')], null);

        self::assertSame([], $this->rule->processNode($method, $this->createStub(\PHPStan\Analyser\Scope::class)));
    }

    public function testArrayDocblockIsNotFlagged(): void
    {
        $method = $this->makeMethod(
            'render',
            [$this->makeArrayParam('items')],
            '/** @param array<string> $items */',
        );

        self::assertSame([], $this->rule->processNode($method, $this->createStub(\PHPStan\Analyser\Scope::class)));
    }

    public function testIterableDocblockIsNotFlagged(): void
    {
        $method = $this->makeMethod(
            'render',
            [$this->makeArrayParam('items')],
            '/** @param iterable<string> $items */',
        );

        self::assertSame([], $this->rule->processNode($method, $this->createStub(\PHPStan\Analyser\Scope::class)));
    }

    public function testAlreadyVariadicIsNotFlagged(): void
    {
        $param  = new Param(new Variable('items'), null, new Identifier('array'), false, true);
        $method = $this->makeMethod(
            'render',
            [$param],
            '/** @param list<string> $items */',
        );

        self::assertSame([], $this->rule->processNode($method, $this->createStub(\PHPStan\Analyser\Scope::class)));
    }

    public function testNonArrayTypeIsNotFlagged(): void
    {
        $param  = new Param(new Variable('items'), null, new Identifier('string'));
        $method = $this->makeMethod('render', [$param], '/** @param list<string> $items */');

        self::assertSame([], $this->rule->processNode($method, $this->createStub(\PHPStan\Analyser\Scope::class)));
    }

    public function testNullableArrayTypeIsNotFlagged(): void
    {
        $nullableType = new \PhpParser\Node\NullableType(new Identifier('array'));
        $param        = new Param(new Variable('items'), null, $nullableType);
        $method       = $this->makeMethod('render', [$param], '/** @param list<string> $items */');

        self::assertSame([], $this->rule->processNode($method, $this->createStub(\PHPStan\Analyser\Scope::class)));
    }

    public function testUntypedParamIsNotFlagged(): void
    {
        $param  = new Param(new Variable('items'));
        $method = $this->makeMethod('render', [$param], '/** @param list<string> $items */');

        self::assertSame([], $this->rule->processNode($method, $this->createStub(\PHPStan\Analyser\Scope::class)));
    }

    public function testNestedGenericListIsFlagged(): void
    {
        $method = $this->makeMethod(
            'render',
            [$this->makeArrayParam('items')],
            '/** @param list<Type<A, B>> $items */',
        );

        self::assertCount(1, $this->rule->processNode($method, $this->createStub(\PHPStan\Analyser\Scope::class)));
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
        return new Param(new Variable($name), null, new Identifier('array'));
    }
}
