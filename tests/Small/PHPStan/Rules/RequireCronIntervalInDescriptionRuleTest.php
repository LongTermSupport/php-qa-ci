<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\RequireCronIntervalInDescriptionRule;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
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
#[CoversClass(RequireCronIntervalInDescriptionRule::class)]
#[Small]
#[AllowMockObjectsWithoutExpectations]
final class RequireCronIntervalInDescriptionRuleTest extends TestCase
{
    private RequireCronIntervalInDescriptionRule $rule;

    protected function setUp(): void
    {
        $this->rule = new RequireCronIntervalInDescriptionRule();
    }

    #[Test]
    public function getNodeTypeIsClass(): void
    {
        self::assertSame(Class_::class, $this->rule->getNodeType());
    }

    #[Test]
    public function cronCommandWithIntervalInDescriptionIsNotFlagged(): void
    {
        $class = $this->commandClass('AsCommand', ['app:cron:cleanup', 'Clean up expired tokens. [every 1h]']);

        self::assertSame([], $this->rule->processNode($class, $this->scope()));
    }

    #[Test]
    public function cronCommandWithoutIntervalIsFlagged(): void
    {
        $class  = $this->commandClass('AsCommand', ['app:cron:cleanup', 'Clean up expired tokens.']);
        $errors = $this->rule->processNode($class, $this->scope());

        self::assertCount(1, $errors);
        self::assertStringContainsString('must end with a schedule interval', $errors[0]->getMessage());
        self::assertSame(RequireCronIntervalInDescriptionRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function cronCommandWithNoDescriptionArgumentIsFlagged(): void
    {
        $class  = $this->commandClass('AsCommand', ['app:cron:cleanup']);
        $errors = $this->rule->processNode($class, $this->scope());

        self::assertCount(1, $errors);
        self::assertStringContainsString('is missing a description', $errors[0]->getMessage());
    }

    #[Test]
    public function nonCronCommandIsNotFlagged(): void
    {
        $class = $this->commandClass('AsCommand', ['app:report:generate', 'Generate a report.']);

        self::assertSame([], $this->rule->processNode($class, $this->scope()));
    }

    #[Test]
    public function classWithoutAsCommandAttributeIsNotFlagged(): void
    {
        $class = new Class_('PlainClass', ['attrGroups' => []]);

        self::assertSame([], $this->rule->processNode($class, $this->scope()));
    }

    #[Test]
    public function anonymousClassIsIgnored(): void
    {
        $class = $this->commandClass('AsCommand', ['app:cron:cleanup'], name: null);

        self::assertSame([], $this->rule->processNode($class, $this->scope()));
    }

    #[Test]
    public function fullyQualifiedAsCommandAttributeIsRecognised(): void
    {
        $class = $this->commandClass(
            'Symfony\Component\Console\Attribute\AsCommand',
            ['app:cron:cleanup', 'Clean up. [every 15m]'],
        );

        self::assertSame([], $this->rule->processNode($class, $this->scope()));
    }

    /**
     * @param list<string> $stringArgs
     */
    private function commandClass(string $attributeName, array $stringArgs, ?string $name = 'CleanupCommand'): Class_
    {
        $args = [];
        foreach ($stringArgs as $stringArg) {
            $args[] = new Arg(new String_($stringArg));
        }

        $attrGroup = new AttributeGroup([new Attribute(new Name($attributeName), $args)]);

        return new Class_($name, ['attrGroups' => [$attrGroup]]);
    }

    private function scope(): CollectedDataEmitter&NodeCallbackInvoker&Scope
    {
        return $this->createMockForIntersectionOfInterfaces([CollectedDataEmitter::class, NodeCallbackInvoker::class, Scope::class]);
    }
}
