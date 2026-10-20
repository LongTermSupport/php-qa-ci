<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\PathWordSubstringCheckCollector;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the collector gathers is driven through ForbidPathWordSubstringCheckRuleTest's fixtures;
 * this pins what it ignores before it reads a type.
 *
 * @internal
 */
#[CoversClass(PathWordSubstringCheckCollector::class)]
#[Small]
final class PathWordSubstringCheckCollectorTest extends TestCase
{
    use ScopeStubTrait;

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
        self::assertNull($collector->processNode(new FuncCall(new String_('str_contains'), []), self::scopeStub()));
    }

    #[Test]
    public function aCallMissingAnArgumentIsNotCollected(): void
    {
        self::assertNull(
            new PathWordSubstringCheckCollector()->processNode(new FuncCall(new Name('str_contains'), []), self::scopeStub()),
        );
    }
}
