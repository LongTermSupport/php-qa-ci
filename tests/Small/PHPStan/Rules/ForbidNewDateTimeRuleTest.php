<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidNewDateTimeRule;
use LTS\PHPQA\PHPStan\Rules\TestCodeDetector;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ForbidNewDateTimeRule::class)]
#[UsesClass(TestCodeDetector::class)]
#[Small]
final class ForbidNewDateTimeRuleTest extends TestCase
{
    use ScopeStubTrait;

    private const string DATE_TIME = 'DateTime';

    private const string APP_SERVICE = 'App\Service';

    private const string SERVICE_FOO_PHP = '/app/src/Service/Foo.php';

    private ForbidNewDateTimeRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ForbidNewDateTimeRule(new TestCodeDetector('/app'));
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
            new New_(new Name(self::DATE_TIME)),
            $this->scope(self::APP_SERVICE, self::SERVICE_FOO_PHP, self::DATE_TIME),
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
            $this->scope(self::APP_SERVICE, self::SERVICE_FOO_PHP, 'DateTimeImmutable'),
        );

        self::assertSame([], $errors);
    }

    #[Test]
    public function newDateTimeInATestsNamespaceIsSkipped(): void
    {
        $errors = $this->rule->processNode(
            new New_(new Name(self::DATE_TIME)),
            $this->scope('App\Tests\Service', self::SERVICE_FOO_PHP, self::DATE_TIME),
        );

        self::assertSame([], $errors);
    }

    #[Test]
    public function newDateTimeInATestsFilePathIsSkipped(): void
    {
        $errors = $this->rule->processNode(
            new New_(new Name(self::DATE_TIME)),
            $this->scope(self::APP_SERVICE, '/app/tests/Service/FooTest.php', self::DATE_TIME),
        );

        self::assertSame([], $errors);
    }

    /** A checkout under a directory named tests: its production code is still checked. */
    #[Test]
    public function newDateTimeInProductionCodeOfACheckoutUnderATestsDirectoryIsFlagged(): void
    {
        $rule = new ForbidNewDateTimeRule(new TestCodeDetector('/home/ci/tests/app'));

        $errors = $rule->processNode(
            new New_(new Name(self::DATE_TIME)),
            $this->scope(self::APP_SERVICE, '/home/ci/tests/app/src/Service/Foo.php', self::DATE_TIME),
        );

        self::assertCount(1, $errors);
    }

    /** `TestsHelper` contains `Tests` but no segment ends with it, and `Contests` ends in lower-case `tests`. */
    #[Test]
    public function newDateTimeInANamespaceThatOnlyContainsTheWordIsFlagged(): void
    {
        foreach (['App\TestsHelper\Service', 'App\Contests\Service'] as $namespace) {
            $errors = $this->rule->processNode(
                new New_(new Name(self::DATE_TIME)),
                $this->scope($namespace, self::SERVICE_FOO_PHP, self::DATE_TIME),
            );

            self::assertCount(1, $errors, $namespace);
        }
    }

    #[Test]
    public function newDateTimeInANamespaceSegmentEndingInTestsIsSkipped(): void
    {
        $errors = $this->rule->processNode(
            new New_(new Name(self::DATE_TIME)),
            $this->scope('App\FunctionalTests\Service', self::SERVICE_FOO_PHP, self::DATE_TIME),
        );

        self::assertSame([], $errors);
    }

    #[Test]
    public function dynamicInstantiationIsIgnored(): void
    {
        // new $class — the class expression is not a Name and cannot be resolved.
        $errors = $this->rule->processNode(
            new New_(new Variable('class')),
            $this->scope(self::APP_SERVICE, self::SERVICE_FOO_PHP, self::DATE_TIME),
        );

        self::assertSame([], $errors);
    }

    private function scope(?string $namespace, string $file, string $resolvesTo): CollectedDataEmitter&DependencyTracker&NodeCallbackInvoker&Scope
    {
        $scope = self::scopeStub();
        $scope->method('getNamespace')->willReturn($namespace);
        $scope->method('getFile')->willReturn($file);
        $scope->method('resolveName')->willReturn($resolvesTo);

        return $scope;
    }
}
