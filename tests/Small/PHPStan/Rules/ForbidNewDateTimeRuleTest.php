<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidNewDateTimeRule;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
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
#[CoversClass(ForbidNewDateTimeRule::class)]
#[Small]
#[AllowMockObjectsWithoutExpectations]
final class ForbidNewDateTimeRuleTest extends TestCase
{
    private ForbidNewDateTimeRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ForbidNewDateTimeRule();
    }

    #[Test]
    public function getNodeTypeIsNew(): void
    {
        self::assertSame(New_::class, $this->rule->getNodeType());
    }

    #[Test]
    public function newDateTimeInProductionCodeIsFlagged(): void
    {
        $errors = $this->rule->processNode(
            new New_(new Name('DateTime')),
            $this->scope('App\Service', '/app/src/Service/Foo.php', 'DateTime'),
        );

        self::assertCount(1, $errors);
        self::assertStringContainsString('Use DateTimeImmutable instead of DateTime', $errors[0]->getMessage());
        self::assertSame(ForbidNewDateTimeRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function newDateTimeImmutableIsNotFlagged(): void
    {
        $errors = $this->rule->processNode(
            new New_(new Name('DateTimeImmutable')),
            $this->scope('App\Service', '/app/src/Service/Foo.php', 'DateTimeImmutable'),
        );

        self::assertSame([], $errors);
    }

    #[Test]
    public function newDateTimeInATestsNamespaceIsSkipped(): void
    {
        $errors = $this->rule->processNode(
            new New_(new Name('DateTime')),
            $this->scope('App\Tests\Service', '/app/src/Service/Foo.php', 'DateTime'),
        );

        self::assertSame([], $errors);
    }

    #[Test]
    public function newDateTimeInATestsFilePathIsSkipped(): void
    {
        $errors = $this->rule->processNode(
            new New_(new Name('DateTime')),
            $this->scope('App\Service', '/app/tests/Service/FooTest.php', 'DateTime'),
        );

        self::assertSame([], $errors);
    }

    #[Test]
    public function dynamicInstantiationIsIgnored(): void
    {
        // new $class — the class expression is not a Name and cannot be resolved.
        $errors = $this->rule->processNode(
            new New_(new Variable('class')),
            $this->scope('App\Service', '/app/src/Service/Foo.php', 'DateTime'),
        );

        self::assertSame([], $errors);
    }

    private function scope(?string $namespace, string $file, string $resolvesTo): CollectedDataEmitter&NodeCallbackInvoker&Scope
    {
        $scope = $this->createMockForIntersectionOfInterfaces([CollectedDataEmitter::class, NodeCallbackInvoker::class, Scope::class]);
        $scope->method('getNamespace')->willReturn($namespace);
        $scope->method('getFile')->willReturn($file);
        $scope->method('resolveName')->willReturn($resolvesTo);

        return $scope;
    }
}
