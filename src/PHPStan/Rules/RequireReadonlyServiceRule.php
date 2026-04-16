<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Enforces that service classes are declared as "final readonly class".
 *
 * Mutable services carry hidden state between requests, making bugs
 * non-deterministic. A readonly service guarantees immutability.
 *
 * If a service genuinely needs mutable state per-call, extract that state
 * into a context DTO.
 *
 * EXCEPTIONS (auto-skipped):
 * - Entity classes (Doctrine), Controllers, Commands, Exceptions, Enums
 * - Classes implementing LoggerAwareInterface or ResetInterface
 * - Classes extending framework base classes (Constraint, TestCase, etc.)
 * - Test classes, SDK/generated code
 * - Common framework patterns: Repository, Validator, Authenticator, etc.
 *
 * @implements Rule<Class_>
 */
final class RequireReadonlyServiceRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.readonlyService';

    /** @var list<string> */
    private const array EXCLUDED_NAMESPACE_SEGMENTS = [
        '\Controller\\',
        '\Command\\',
        '\Entity\\',
        '\Exception\\',
        '\Sdk\\',
        '\API\\',
        '\Oa\\',
        '\Form\\',
        '\Tests\\',
    ];

    /** @var list<string> */
    private const array EXCLUDED_SUFFIXES = [
        'Controller',
        'Command',
        'Exception',
        'Enum',
        'Test',
        'TestCase',
        'Authenticator',
        'Validator',
        'TypeExtension',
        'Repository',
        'Fixture',
        'Constraint',
        'Handler',
        'Kernel',
        'Helper',
        'Listener',
        'Factory',
        'Session',
        'Guesser',
        'Matrix',
        'Creator',
        'Client',
        'Subscriber',
        'Provider',
        'Compiler',
        'Extension',
        'Normalizer',
        'Denormalizer',
        'Transformer',
        'Voter',
        'Loader',
    ];

    /** @var list<string> */
    private const array EXCLUDED_INTERFACES = [
        'Psr\Log\LoggerAwareInterface',
        'Symfony\Contracts\Service\ResetInterface',
    ];

    public function getNodeType(): string
    {
        return Class_::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = $scope->getNamespace();
        if (null === $namespace) {
            return [];
        }

        if (!$node->isFinal() || $node->isReadonly() || $node->isAbstract()) {
            return [];
        }

        $className = null !== $node->name ? $node->name->name : null;
        if (null === $className) {
            return [];
        }

        $fqcn = $namespace . '\\' . $className;
        foreach (self::EXCLUDED_NAMESPACE_SEGMENTS as $segment) {
            if (str_contains($fqcn, $segment)) {
                return [];
            }
        }

        foreach (self::EXCLUDED_SUFFIXES as $suffix) {
            if (str_ends_with($className, $suffix)) {
                return [];
            }
        }

        if ($this->isDoctrineEntity($node)) {
            return [];
        }

        if (null !== $node->extends) {
            return [];
        }

        $classReflection = $scope->getClassReflection();
        if ($classReflection instanceof \PHPStan\Reflection\ClassReflection) {
            $interfaceNames = array_keys($classReflection->getInterfaces());
            foreach ($interfaceNames as $interfaceName) {
                if (\in_array($interfaceName, self::EXCLUDED_INTERFACES, true)) {
                    return [];
                }
            }
        }

        return [
            RuleErrorBuilder::message(
                \sprintf(
                    'Service class %s should be "final readonly class". '
                    . 'Mutable services cause hidden state bugs. '
                    . 'If per-call state is needed, extract it into a context DTO.',
                    $className,
                ),
            )->identifier(self::IDENTIFIER)->build(),
        ];
    }

    private function isDoctrineEntity(Class_ $node): bool
    {
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $attrName = $attr->name->toString();
                if ('ORM\Entity' === $attrName || str_ends_with($attrName, '\Entity')) {
                    return true;
                }
            }
        }

        return false;
    }

}
