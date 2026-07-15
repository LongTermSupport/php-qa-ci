<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidEmptyLanguageConstructRule;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ForbidEmptyLanguageConstructRule::class)]
#[Small]
#[AllowMockObjectsWithoutExpectations]
final class ForbidEmptyLanguageConstructRuleTest extends TestCase
{
    private ForbidEmptyLanguageConstructRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ForbidEmptyLanguageConstructRule();
    }

    #[Test]
    public function getNodeTypeIsEmpty(): void
    {
        self::assertSame(Empty_::class, $this->rule->getNodeType());
    }

    #[Test]
    public function everyEmptyConstructIsFlagged(): void
    {
        $errors = $this->rule->processNode(new Empty_(new Variable('array')), $this->scope());

        self::assertCount(1, $errors);
        self::assertStringContainsString('empty() is banned', $errors[0]->getMessage());
        self::assertSame(ForbidEmptyLanguageConstructRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    private function scope(): CollectedDataEmitter&NodeCallbackInvoker&Scope
    {
        return $this->createMockForIntersectionOfInterfaces([CollectedDataEmitter::class, NodeCallbackInvoker::class, Scope::class]);
    }
}
