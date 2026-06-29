<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/**
 * Flags calls to a method deprecated by the installed PHPUnit (e.g.
 * `$this->expectExceptionMessage(...)`). The deprecated set is discovered
 * dynamically — see {@see DeprecatedPhpunitMethodDetector} — so the rule stays
 * correct as PHPUnit evolves, with no hard-coded method list.
 *
 * A call is flagged only when BOTH hold:
 *   1. the called method name is in the deprecated set, AND
 *   2. the call target is (a subclass of) a configured PHPUnit class.
 * The second check prevents false positives where a non-PHPUnit class happens to
 * define a method whose name collides with a deprecated PHPUnit one.
 *
 * Covers instance calls (`$this->m()`), nullsafe calls (`$o?->m()`) and static
 * calls (`self::m()` / `Assert::m()`); dynamic method/class names are skipped
 * (not statically resolvable).
 *
 * The recognised PHPUnit base classes default to TestCase + Assert and are
 * configurable via the `phpunitClasses` constructor argument (the tests inject a
 * self-contained fake hierarchy so they do not depend on which methods the
 * installed PHPUnit deprecates).
 *
 * @implements Rule<CallLike>
 */
final readonly class ForbidDeprecatedPhpunitMethodRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.deprecatedPhpunitMethod';

    private DeprecatedPhpunitMethodDetector $detector;

    /**
     * @var list<class-string>
     */
    private array $phpunitClasses;

    /**
     * @param list<class-string> $phpunitClasses PHPUnit base classes whose deprecated methods are
     *                                           banned; calls are only flagged on these types or
     *                                           their subclasses. Defaults to TestCase + Assert.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        array $phpunitClasses = [],
    ) {
        $this->phpunitClasses = [] === $phpunitClasses
            ? [TestCase::class, Assert::class]
            : $phpunitClasses;
        $this->detector = new DeprecatedPhpunitMethodDetector($this->phpunitClasses);
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $methodName = $this->methodName($node);

        if (null === $methodName) {
            return [];
        }

        if (!$this->detector->isDeprecated($methodName)) {
            return [];
        }

        if (!$this->isCallOnPhpunitType($node, $scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                \sprintf(
                    'Method "%s()" is deprecated in the installed PHPUnit; replace it with a non-deprecated '
                    . 'equivalent. For expectExceptionMessage() prefer expectExceptionObject(), otherwise '
                    . 'expectExceptionMessageIs() / expectExceptionMessageIsOrContains() / expectExceptionMessageMatches().',
                    $methodName,
                ),
            )->identifier(self::IDENTIFIER)->build(),
        ];
    }

    private function methodName(Node $node): ?string
    {
        if (!$node instanceof MethodCall && !$node instanceof NullsafeMethodCall && !$node instanceof StaticCall) {
            return null;
        }

        return $node->name instanceof Identifier ? $node->name->toString() : null;
    }

    private function isCallOnPhpunitType(Node $node, Scope $scope): bool
    {
        foreach ($this->targetClassNames($node, $scope) as $className) {
            if ($this->isPhpunitType($className)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function targetClassNames(Node $node, Scope $scope): array
    {
        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            return $scope->getType($node->var)->getObjectClassNames();
        }

        if ($node instanceof StaticCall && $node->class instanceof Name) {
            return [$scope->resolveName($node->class)];
        }

        return [];
    }

    private function isPhpunitType(string $className): bool
    {
        if (!$this->reflectionProvider->hasClass($className)) {
            return false;
        }

        $reflection = $this->reflectionProvider->getClass($className);

        foreach ($this->phpunitClasses as $phpunitClass) {
            if ($className === $phpunitClass || $reflection->isSubclassOf($phpunitClass)) {
                return true;
            }
        }

        return false;
    }
}
