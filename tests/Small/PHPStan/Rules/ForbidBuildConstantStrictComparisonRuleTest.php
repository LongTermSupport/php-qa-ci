<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidBuildConstantStrictComparisonRule;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\Cast\Bool_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ForbidBuildConstantStrictComparisonRule::class)]
#[Small]
final class ForbidBuildConstantStrictComparisonRuleTest extends TestCase
{
    use ScopeStubTrait;

    private const string ZTS = 'PHP_ZTS';

    private const string DEBUG = 'PHP_DEBUG';

    private ForbidBuildConstantStrictComparisonRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ForbidBuildConstantStrictComparisonRule();
    }

    #[Test]
    public function itVisitsEveryExpression(): void
    {
        self::assertSame(Expr::class, $this->rule->getNodeType());
    }

    #[Test]
    #[DataProvider('strictComparisons')]
    public function aStrictComparisonOfABuildConstantIsReported(Expr $node, string $constant): void
    {
        $errors = $this->rule->processNode($node, $this->scope());

        self::assertCount(1, $errors);
        self::assertSame(ForbidBuildConstantStrictComparisonRule::IDENTIFIER, $errors[0]->getIdentifier());
        self::assertSame(
            $constant . ' is compared strictly, but it is a bool since PHP 8.4 and an int before, so the comparison is always false on one of them. Write (bool) \\' . $constant . '.',
            $errors[0]->getMessage(),
        );
    }

    /** @return iterable<string, array{Expr, string}> */
    public static function strictComparisons(): iterable
    {
        yield '1 === \PHP_ZTS' => [new Identical(new Int_(1), self::constant(self::ZTS)), self::ZTS];
        yield '\PHP_ZTS === 1' => [new Identical(self::constant(self::ZTS), new Int_(1)), self::ZTS];
        yield '0 !== \PHP_DEBUG' => [new NotIdentical(new Int_(0), self::constant(self::DEBUG)), self::DEBUG];
        yield 'true === PHP_ZTS, unqualified' => [new Identical(new ConstFetch(new Name('true')), new ConstFetch(new Name(self::ZTS))), self::ZTS];
        yield 'match (\PHP_DEBUG)' => [new Match_(self::constant(self::DEBUG), [new MatchArm([new Int_(1)], new String_('debug'))]), self::DEBUG];
    }

    #[Test]
    #[DataProvider('portableUses')]
    public function aPortableUseIsNotReported(Expr $node): void
    {
        self::assertSame([], $this->rule->processNode($node, $this->scope()));
    }

    /** @return iterable<string, array{Expr}> */
    public static function portableUses(): iterable
    {
        yield 'the cast itself' => [new Bool_(self::constant(self::ZTS))];
        yield 'a cast compared' => [new Identical(new ConstFetch(new Name('true')), new Bool_(self::constant(self::ZTS)))];
        yield 'another constant compared' => [new Identical(new Int_(8), self::constant('PHP_MAJOR_VERSION'))];
        yield 'a loose comparison, which another rule reports' => [new Equal(new Int_(1), self::constant(self::ZTS))];
        yield 'a strict comparison of variables' => [new Identical(new Variable('a'), new Variable('b'))];
        yield 'a match on another value' => [new Match_(new Variable('a'), [new MatchArm([self::constant(self::ZTS)], new String_('zts'))])];
        yield 'a function call' => [new FuncCall(new Name('phpversion'), [new Arg(new String_('x'))])];
        yield 'a namespaced constant of the same short name' => [new Identical(new Int_(1), new ConstFetch(new FullyQualified('Vendor\PHP_ZTS')))];
    }

    private static function constant(string $name): ConstFetch
    {
        return new ConstFetch(new FullyQualified($name));
    }

    private function scope(): CollectedDataEmitter&DependencyTracker&NodeCallbackInvoker&Scope
    {
        return self::scopeStub();
    }
}
