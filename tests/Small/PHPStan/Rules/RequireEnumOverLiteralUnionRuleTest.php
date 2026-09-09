<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\RequireEnumOverLiteralUnionRule;
use PhpParser\Comment\Doc;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\UnionType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * The fixture cases below are the rule's proof (Defence Before Fix, clause 3.3 part C):
 * the originating instance was a `@param 'TokenGet'|'TokenRefresh' $operation` docblock
 * over a `string` parameter, reproduced here as the first flagged case.
 *
 * @internal
 */
#[CoversClass(RequireEnumOverLiteralUnionRule::class)]
#[Small]
final class RequireEnumOverLiteralUnionRuleTest extends TestCase
{
    use ScopeStubTrait;

    private const string METHOD_BUILD = 'build';

    private const string TYPE_STRING = 'string';

    private const string PARAM_MODE = 'mode';

    private const string PARAM_A_B_MODE = '/** @param \'a\'|\'b\' $mode */';

    private const string TYPE_INT = 'int';

    private RequireEnumOverLiteralUnionRule $rule;

    protected function setUp(): void
    {
        $this->rule = new RequireEnumOverLiteralUnionRule();
    }

    public function testGetNodeType(): void
    {
        self::assertSame(\PhpParser\Node\FunctionLike::class, $this->rule->getNodeType());
    }

    #[DataProvider('flaggedParamDocblocks')]
    public function testLiteralUnionParamDocblockOverAScalarIsFlagged(string $docblock): void
    {
        $method = $this->makeMethod(self::METHOD_BUILD, [$this->makeParam('operation', new Identifier(self::TYPE_STRING))], $docblock);

        $errors = $this->rule->processNode($method, self::scopeStub());

        self::assertCount(1, $errors);
        self::assertStringContainsString('$operation', $errors[0]->getMessage());
        self::assertStringContainsString('build()', $errors[0]->getMessage());
        self::assertStringContainsString('backed enum', $errors[0]->getMessage());
        self::assertSame(RequireEnumOverLiteralUnionRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function flaggedParamDocblocks(): iterable
    {
        yield 'two string literals (the originating instance)' => ["/** @param 'TokenGet'|'TokenRefresh' \$operation */"];

        yield 'three string literals' => ["/** @param 'a'|'b'|'c' \$operation */"];

        yield 'double-quoted literals' => ['/** @param "asc"|"desc" $operation */'];

        yield 'string literals plus null' => ["/** @param 'a'|'b'|null \$operation */"];

        yield 'parenthesised union' => ["/** @param ('a'|'b') \$operation */"];

        yield 'nullable shorthand on a parenthesised union' => ["/** @param ?('a'|'b') \$operation */"];

        yield 'integer literals' => ['/** @param 0|1|2 $operation */'];

        yield 'negative integer literal' => ['/** @param -1|1 $operation */'];

        yield 'mixed string and int literals' => ["/** @param 'auto'|0|1 \$operation */"];

        yield 'preceded by another param line' => ["/**\n * @param int \$other\n * @param 'a'|'b' \$operation\n */"];
    }

    #[DataProvider('scalarNativeTypes')]
    public function testEveryScalarNativeShapeIsFlagged(Identifier|Name|NullableType|UnionType|null $nativeType): void
    {
        $method = $this->makeMethod(self::METHOD_BUILD, [$this->makeParam(self::PARAM_MODE, $nativeType)], self::PARAM_A_B_MODE);

        self::assertCount(1, $this->rule->processNode($method, self::scopeStub()));
    }

    /**
     * @return iterable<string, array{0: Identifier|Name|NullableType|UnionType|null}>
     */
    public static function scalarNativeTypes(): iterable
    {
        yield self::TYPE_STRING => [new Identifier(self::TYPE_STRING)];

        yield self::TYPE_INT => [new Identifier(self::TYPE_INT)];

        yield '?string' => [new NullableType(new Identifier(self::TYPE_STRING))];

        yield 'string|int' => [new UnionType([new Identifier(self::TYPE_STRING), new Identifier(self::TYPE_INT)])];

        yield 'string|int|null' => [new UnionType([new Identifier(self::TYPE_STRING), new Identifier(self::TYPE_INT), new Identifier('null')])];

        yield 'untyped (the docblock is the only type)' => [null];
    }

    public function testLiteralUnionReturnDocblockIsFlagged(): void
    {
        $method = $this->makeMethod('direction', [], "/** @return 'asc'|'desc' */", new Identifier(self::TYPE_STRING));

        $errors = $this->rule->processNode($method, self::scopeStub());

        self::assertCount(1, $errors);
        self::assertStringContainsString('@return', $errors[0]->getMessage());
        self::assertStringContainsString('direction()', $errors[0]->getMessage());
    }

    public function testEachOffendingParamIsReportedOnce(): void
    {
        $method = $this->makeMethod(
            self::METHOD_BUILD,
            [$this->makeParam('kind', new Identifier(self::TYPE_STRING)), $this->makeParam('level', new Identifier(self::TYPE_INT))],
            "/**\n * @param 'a'|'b' \$kind\n * @param 1|2|3 \$level\n */",
        );

        self::assertCount(2, $this->rule->processNode($method, self::scopeStub()));
    }

    public function testFreeFunctionsAndClosuresAreCovered(): void
    {
        $doc      = self::PARAM_A_B_MODE;
        $function = new Function_('fn', ['params' => [$this->makeParam(self::PARAM_MODE, new Identifier(self::TYPE_STRING))]], ['comments' => [new Doc($doc)]]);
        $closure  = new Closure(['params' => [$this->makeParam(self::PARAM_MODE, new Identifier(self::TYPE_STRING))]], ['comments' => [new Doc($doc)]]);

        self::assertCount(1, $this->rule->processNode($function, self::scopeStub()));
        self::assertCount(1, $this->rule->processNode($closure, self::scopeStub()));
    }

    #[DataProvider('unflaggedParamDocblocks')]
    public function testDocblocksWithoutAClosedLiteralSetAreNotFlagged(string $docblock): void
    {
        $method = $this->makeMethod(self::METHOD_BUILD, [$this->makeParam('operation', new Identifier(self::TYPE_STRING))], $docblock);

        self::assertSame([], $this->rule->processNode($method, self::scopeStub()));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unflaggedParamDocblocks(): iterable
    {
        yield 'no docblock type narrowing' => ['/** @param string $operation */'];

        yield 'single literal (a constant, not a set)' => ["/** @param 'fixed' \$operation */"];

        yield 'single literal or null' => ["/** @param 'fixed'|null \$operation */"];

        yield 'scalar union without literals' => ['/** @param string|int $operation */'];

        yield 'class-string union' => ['/** @param class-string<Foo>|class-string<Bar> $operation */'];

        yield 'literal union inside a generic (the element type, not this scalar)' => ["/** @param list<'a'|'b'> \$operation */"];

        yield 'literals in a different param' => ["/** @param 'a'|'b' \$other */"];

        yield 'non-empty-string and friends' => ['/** @param non-empty-string|numeric-string $operation */'];

        yield 'literal union in prose, not a tag' => ["/** Accepts 'a'|'b' as documented elsewhere. */"];
    }

    public function testNonScalarNativeTypesAreNotFlagged(): void
    {
        // A literal union over a non-scalar native type is PHPStan's business (it will already
        // report the docblock as incompatible); this rule is about scalars standing in for enums.
        $method = $this->makeMethod(self::METHOD_BUILD, [$this->makeParam(self::PARAM_MODE, new Name('Foo'))], self::PARAM_A_B_MODE);

        self::assertSame([], $this->rule->processNode($method, self::scopeStub()));
    }

    public function testNoDocblockIsNotFlagged(): void
    {
        $method = $this->makeMethod(self::METHOD_BUILD, [$this->makeParam(self::PARAM_MODE, new Identifier(self::TYPE_STRING))], null);

        self::assertSame([], $this->rule->processNode($method, self::scopeStub()));
    }

    /**
     * @param list<Param> $params
     */
    private function makeMethod(
        string $name,
        array $params,
        ?string $docComment,
        Identifier|Name|NullableType|UnionType|null $returnType = null,
    ): ClassMethod {
        $attributes = [];

        if (null !== $docComment) {
            $attributes['comments'] = [new Doc($docComment)];
        }

        return new ClassMethod($name, ['params' => $params, 'returnType' => $returnType], $attributes);
    }

    private function makeParam(string $name, Identifier|Name|NullableType|UnionType|null $type): Param
    {
        return new Param(new Variable($name), null, $type);
    }
}
