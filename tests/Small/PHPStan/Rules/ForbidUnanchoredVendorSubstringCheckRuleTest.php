<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidUnanchoredVendorSubstringCheckRule;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * Drives the rule directly against synthetic FuncCall nodes, mirroring
 * RequireRuleIdentifierConstantRuleTest's style.
 *
 * @internal
 *
 * @extends RuleTestCase<ForbidUnanchoredVendorSubstringCheckRule>
 */
#[CoversClass(ForbidUnanchoredVendorSubstringCheckRule::class)]
#[Medium]
final class ForbidUnanchoredVendorSubstringCheckRuleTest extends RuleTestCase
{
    use ScopeStubTrait;

    private const string STR_CONTAINS = 'str_contains';

    private const string FILE_NAME = 'fileName';

    private const string VENDOR_PREFIX = 'vendor/';

    #[Test]
    public function getNodeTypeIsFuncCall(): void
    {
        self::assertSame(FuncCall::class, $this->getRule()->getNodeType());
    }

    #[Test]
    public function strContainsWithBareVendorLiteralIsFlagged(): void
    {
        $call   = $this->funcCall(self::STR_CONTAINS, new Variable(self::FILE_NAME), new String_('/vendor/'));
        $errors = $this->getRule()->processNode($call, $this->scope());

        self::assertCount(1, $errors);
        self::assertStringContainsString('unanchored', $errors[0]->getMessage());
        self::assertSame(ForbidUnanchoredVendorSubstringCheckRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function strposWithBareVendorLiteralIsFlagged(): void
    {
        $call   = $this->funcCall('strpos', new Variable(self::FILE_NAME), new String_(self::VENDOR_PREFIX));
        $errors = $this->getRule()->processNode($call, $this->scope());

        self::assertCount(1, $errors);
    }

    #[Test]
    public function strStartsWithBareVendorLiteralIsFlagged(): void
    {
        $call   = $this->funcCall('str_starts_with', new Variable(self::FILE_NAME), new String_(self::VENDOR_PREFIX));
        $errors = $this->getRule()->processNode($call, $this->scope());

        self::assertCount(1, $errors);
    }

    #[Test]
    public function vendorLiteralConcatenatedWithAProjectRootVariableIsAllowed(): void
    {
        // $this->projectRoot . '/vendor/symfony/...' — anchored, not a bare literal arg.
        $concat = new Concat(new Variable('projectRoot'), new String_('/vendor/symfony/dotenv'));
        $call   = $this->funcCall('str_starts_with', new Variable(self::FILE_NAME), $concat);

        self::assertSame([], $this->getRule()->processNode($call, $this->scope()));
    }

    #[Test]
    public function literalWithoutVendorSubstringIsAllowed(): void
    {
        $call = $this->funcCall(self::STR_CONTAINS, new Variable(self::FILE_NAME), new String_('/src/'));

        self::assertSame([], $this->getRule()->processNode($call, $this->scope()));
    }

    #[Test]
    public function unrelatedFunctionCallIsIgnored(): void
    {
        $call = $this->funcCall('is_dir', new String_('/some/vendor/path'));

        self::assertSame([], $this->getRule()->processNode($call, $this->scope()));
    }

    #[Test]
    public function dynamicFunctionNameIsIgnored(): void
    {
        // $fn($fileName, '/vendor/') — the callee is not a literal Name.
        $call = new FuncCall(new Variable('fn'), [new Arg(new Variable(self::FILE_NAME)), new Arg(new String_('/vendor/'))]);

        self::assertSame([], $this->getRule()->processNode($call, $this->scope()));
    }

    #[Test]
    public function thisRulesOwnDetectionCallIsNotSelfFlagged(): void
    {
        // This rule's own processNode() legitimately calls
        // str_contains($arg->value->value, 'vendor/') to implement the check
        // itself — that inspects AST literal text, not a filesystem path, so
        // it must not be flagged as an instance of the pattern it defends against.
        $call  = $this->funcCall(self::STR_CONTAINS, new Variable('value'), new String_(self::VENDOR_PREFIX));
        $scope = self::scopeStub();
        $scope->method('getClassReflection')->willReturn(
            $this->createReflectionProvider()->getClass(ForbidUnanchoredVendorSubstringCheckRule::class),
        );

        self::assertSame([], $this->getRule()->processNode($call, $scope));
    }

    protected function getRule(): Rule
    {
        return new ForbidUnanchoredVendorSubstringCheckRule();
    }

    private function funcCall(string $functionName, \PhpParser\Node\Expr ...$argValues): FuncCall
    {
        $args = [];
        foreach ($argValues as $argValue) {
            $args[] = new Arg($argValue);
        }

        return new FuncCall(new Name($functionName), $args);
    }

    private function scope(): CollectedDataEmitter&NodeCallbackInvoker&Scope
    {
        return self::scopeStub();
    }
}
