<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\RequireSensitiveParameterUsageRule;
use LTS\PHPQA\PHPStan\Rules\SensitiveParameterAttributeCollector;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * Unit-tests the CollectedDataNode rule by invoking processNode() directly with
 * a hand-built CollectedDataNode. This mirrors the repo's existing rule-test
 * convention and, crucially, side-steps a PHPStan limitation: the analyser
 * finalizer never emits a CollectedDataNode when the total collected data is
 * empty, so the "zero usage" case cannot be exercised through RuleTestCase with
 * only this collector registered.
 */
#[CoversClass(RequireSensitiveParameterUsageRule::class)]
#[Small]
final class RequireSensitiveParameterUsageRuleTest extends TestCase
{
    private const string COLLECTOR = SensitiveParameterAttributeCollector::class;

    private const string EXPECTED_MESSAGE = 'No #[\SensitiveParameter] attribute was found anywhere in the '
        . 'analysed codebase. Most projects handle a password, token or secret somewhere and should mark that '
        . 'parameter with #[\SensitiveParameter] so its value is redacted from stack traces. If this project '
        . 'genuinely never handles sensitive parameters, opt out by setting the requireAtLeastOneUsage parameter '
        . 'of RequireSensitiveParameterUsageRule to false in your phpstan.neon.';

    #[Test]
    public function itGetNodeTypeIsCollectedDataNode(): void
    {
        $rule = new RequireSensitiveParameterUsageRule();

        self::assertSame(CollectedDataNode::class, $rule->getNodeType());
    }

    #[Test]
    public function itDoesNotErrorWhenTheAttributeIsUsedAtLeastOnce(): void
    {
        $rule = new RequireSensitiveParameterUsageRule(true);
        $node = new CollectedDataNode(
            ['/src/Auth.php' => [self::COLLECTOR => ['/src/Auth.php']]],
            false,
        );

        $errors = $rule->processNode($node, $this->makeScope());

        self::assertSame([], $errors);
    }

    #[Test]
    public function itErrorsWhenTheAttributeIsNeverUsedAndTheRequirementIsEnabled(): void
    {
        $rule = new RequireSensitiveParameterUsageRule(true);
        $node = new CollectedDataNode([], false);

        $errors = $rule->processNode($node, $this->makeScope());

        self::assertCount(1, $errors);
        self::assertSame(self::EXPECTED_MESSAGE, $errors[0]->getMessage());
        self::assertSame(RequireSensitiveParameterUsageRule::IDENTIFIER, $errors[0]->getIdentifier());
    }

    #[Test]
    public function itIsANoOpWhenTheRequirementIsDisabledViaTheEscapeHatch(): void
    {
        $rule = new RequireSensitiveParameterUsageRule(false);
        $node = new CollectedDataNode([], false);

        $errors = $rule->processNode($node, $this->makeScope());

        self::assertSame([], $errors);
    }

    /**
     * @return Scope&NodeCallbackInvoker
     */
    private function makeScope(): Scope
    {
        return $this->createMockForIntersectionOfInterfaces([NodeCallbackInvoker::class, Scope::class]);
    }
}
