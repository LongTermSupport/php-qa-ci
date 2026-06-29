<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Attribute\FactorySealedBy;
use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Enforces factory-sealed construction: a class marked with a sealing attribute
 * (the package's {@see FactorySealedBy}, or any project-defined attribute whose
 * first argument is the authorised factory class-string) may be constructed
 * (`new X(...)`) only by that single authorised factory. PHP has no
 * friend/package-private visibility, so this separate-class sole-producer
 * constraint cannot be expressed at the language level — it is an AST rule on the
 * `new` operator.
 *
 * A `new <sealed>(...)` is flagged unless the enclosing class is the authorised
 * factory or the file is under `tests/` (tests construct freely). Dynamic
 * `new $var` is skipped (the class is not statically known). Only the `new`
 * keyword is covered — reflection-based and de-serialisation construction are a
 * documented residual gap.
 *
 * Responsibilities are split for testability:
 *   - {@see SealingAttributeReader} resolves the authorised factory from the
 *     target's sealing attribute (pure reflection);
 *   - {@see FactorySealedDetector} makes the violation decision (pure logic);
 *   - this rule only resolves the AST facts and wires the two together.
 *
 * Register via `rules-optional.neon` (configure the recognised sealing
 * attributes with the `sealingAttributes` argument).
 *
 * @implements Rule<New_>
 */
final readonly class FactorySealedRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.factorySealed';

    private SealingAttributeReader $reader;

    private FactorySealedDetector $detector;

    /**
     * @var list<class-string>
     */
    private array $sealingAttributes;

    /**
     * @param list<class-string> $sealingAttributes attribute FQCNs that mark a class as factory-sealed.
     *                                              Defaults to the package's reference attribute. A
     *                                              project that annotates production code should pass
     *                                              its OWN attribute FQCN here so its production classes
     *                                              need not `use` this dev-only QA package.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        array $sealingAttributes = [],
    ) {
        $this->reader            = new SealingAttributeReader();
        $this->detector          = new FactorySealedDetector();
        $this->sealingAttributes = [] === $sealingAttributes
            ? [FactorySealedBy::class]
            : $sealingAttributes;
    }

    public function getNodeType(): string
    {
        return New_::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // Dynamic construction (new $var / new (expr)) — the class is not
        // statically known, so it cannot be checked.
        if (!$node->class instanceof Node\Name) {
            return [];
        }

        $fqcn = $scope->resolveName($node->class);

        if (!$this->reflectionProvider->hasClass($fqcn)) {
            return [];
        }

        $sealedFactory  = $this->reader->factoryFor($fqcn, $this->sealingAttributes);
        $enclosingClass = $scope->getClassReflection()?->getName();

        if (!$this->detector->isViolation($sealedFactory, $enclosingClass, $scope->getFile())) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                \sprintf(
                    'Class "%s" is factory-sealed — construct it via "%s", never with a direct '
                    . '"new %s(...)" outside that factory (tests are exempt).',
                    $fqcn,
                    (string)$sealedFactory,
                    $fqcn,
                ),
            )->identifier(self::IDENTIFIER)->build(),
        ];
    }
}
