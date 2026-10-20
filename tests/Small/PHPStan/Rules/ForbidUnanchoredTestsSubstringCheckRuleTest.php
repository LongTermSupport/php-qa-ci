<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidUnanchoredTestsSubstringCheckRule;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\Type;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Drives the rule directly against synthetic FuncCall nodes, as its vendor/ sibling's test does.
 *
 * @internal
 */
#[CoversClass(ForbidUnanchoredTestsSubstringCheckRule::class)]
#[Small]
final class ForbidUnanchoredTestsSubstringCheckRuleTest extends TestCase
{
    use ScopeStubTrait;

    private const string STR_CONTAINS = 'str_contains';

    private const string FILE_NAME = 'fileName';

    private const string TESTS_FRAGMENT = '/tests/';

    private const string SELF = 'self';

    private ForbidUnanchoredTestsSubstringCheckRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ForbidUnanchoredTestsSubstringCheckRule();
    }

    #[Test]
    public function getNodeTypeIsFuncCall(): void
    {
        self::assertSame(FuncCall::class, $this->rule->getNodeType());
    }

    #[Test]
    public function strContainsWithBareTestsLiteralIsFlagged(): void
    {
        $call   = $this->funcCall(self::STR_CONTAINS, new Variable(self::FILE_NAME), new String_(self::TESTS_FRAGMENT));
        $errors = $this->rule->processNode($call, self::scopeStub());

        self::assertCount(1, $errors);
        self::assertSame(
            "Unanchored 'tests/' substring check: str_contains(..., '/tests/'). It classifies every file as test code "
            . 'whenever the project itself sits under a tests/ path. Use TestCodeDetector, or anchor on the project '
            . 'root. See docs/phpstan-rules/forbid-unanchored-tests-substring-check.md',
            $errors[0]->getMessage(),
        );
        self::assertSame(ForbidUnanchoredTestsSubstringCheckRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function everyTargetFunctionIsRead(): void
    {
        foreach (['strpos', 'stripos', 'str_starts_with', 'str_ends_with', '\STR_CONTAINS'] as $function) {
            $call = $this->funcCall($function, new Variable(self::FILE_NAME), new String_('tests/'));

            self::assertCount(1, $this->rule->processNode($call, self::scopeStub()), $function);
        }
    }

    /** A fragment held in a class constant is the same check: the constant's value is read. */
    #[Test]
    public function classConstantHoldingATestsFragmentIsFlagged(): void
    {
        $call = $this->funcCall(self::STR_CONTAINS, new Variable(self::FILE_NAME), new ClassConstFetch(new Name(self::SELF), 'TESTS'));

        $errors = $this->rule->processNode($call, $this->scopeTyping(new ConstantStringType(self::TESTS_FRAGMENT)));

        self::assertCount(1, $errors);
        self::assertStringContainsString("str_contains(..., '/tests/')", $errors[0]->getMessage());
    }

    #[Test]
    public function classConstantWithoutTheFragmentIsAllowed(): void
    {
        $call = $this->funcCall(self::STR_CONTAINS, new Variable(self::FILE_NAME), new ClassConstFetch(new Name(self::SELF), 'SRC'));

        self::assertSame([], $this->rule->processNode($call, $this->scopeTyping(new ConstantStringType('/src/'))));
    }

    #[Test]
    public function classConstantThatIsNotAStringIsAllowed(): void
    {
        $call = $this->funcCall('strpos', new Variable(self::FILE_NAME), new ClassConstFetch(new Name(self::SELF), 'OFFSET'));

        self::assertSame([], $this->rule->processNode($call, $this->scopeTyping(new IntegerType())));
    }

    #[Test]
    public function testsLiteralConcatenatedWithAProjectRootVariableIsAllowed(): void
    {
        $concat = new Concat(new Variable('projectRoot'), new String_(self::TESTS_FRAGMENT));
        $call   = $this->funcCall('str_starts_with', new Variable(self::FILE_NAME), $concat);

        self::assertSame([], $this->rule->processNode($call, self::scopeStub()));
    }

    #[Test]
    public function aVariableArgumentIsNotRead(): void
    {
        $call = $this->funcCall(self::STR_CONTAINS, new Variable(self::FILE_NAME), new Variable('fragment'));

        self::assertSame([], $this->rule->processNode($call, self::scopeStub()));
    }

    #[Test]
    public function literalWithoutTestsFragmentIsAllowed(): void
    {
        $call = $this->funcCall(self::STR_CONTAINS, new Variable(self::FILE_NAME), new String_('/src/'));

        self::assertSame([], $this->rule->processNode($call, self::scopeStub()));
    }

    /** `Tests` without the slash is a namespace word, not a path fragment. */
    #[Test]
    public function literalWithoutTheSlashIsAllowed(): void
    {
        $call = $this->funcCall(self::STR_CONTAINS, new Variable('namespace'), new String_('Tests'));

        self::assertSame([], $this->rule->processNode($call, self::scopeStub()));
    }

    #[Test]
    public function unrelatedFunctionCallIsIgnored(): void
    {
        $call = $this->funcCall('is_dir', new String_('/some/tests/path'));

        self::assertSame([], $this->rule->processNode($call, self::scopeStub()));
    }

    #[Test]
    public function dynamicFunctionNameIsIgnored(): void
    {
        $call = new FuncCall(new Variable('fn'), [new Arg(new Variable(self::FILE_NAME)), new Arg(new String_(self::TESTS_FRAGMENT))]);

        self::assertSame([], $this->rule->processNode($call, self::scopeStub()));
    }

    private function funcCall(string $functionName, Expr ...$argValues): FuncCall
    {
        $args = [];
        foreach ($argValues as $argValue) {
            $args[] = new Arg($argValue);
        }

        return new FuncCall(new Name($functionName), $args);
    }

    /** A scope that gives every expression the one type. */
    private function scopeTyping(Type $type): CollectedDataEmitter&DependencyTracker&NodeCallbackInvoker&Scope
    {
        $scope = self::scopeStub();
        $scope->method('getType')->willReturn($type);

        return $scope;
    }
}
