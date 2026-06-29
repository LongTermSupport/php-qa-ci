<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Attribute;

use Attribute;

/**
 * Marks a class as factory-sealed: it may be constructed (`new X(...)`) only by
 * the single authorised factory named here. Any other production construction is
 * a defect — PHP has no friend/package-private visibility, so a separate-class
 * sole-producer constraint cannot be expressed at the language level.
 *
 * Enforced by {@see \LTS\PHPQA\PHPStan\Rules\FactorySealedRule} (identifier
 * `phpqaci.factorySealed`), which flags `new <sealed>(...)` outside the authorised
 * factory (tests are exempt).
 *
 * This is a *reference* attribute. Because the rule resolves the authorised
 * factory from the attribute's first argument (it does not instantiate the
 * attribute), a project may instead define its own `FactorySealedBy`-style
 * attribute in its own (production) namespace and register that FQCN with the
 * rule — useful when the annotated classes are production code that must not
 * `use` a dev-only QA dependency. See the rule's `sealingAttributes` parameter.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class FactorySealedBy
{
    /**
     * @param class-string $factory the sole authorised producer of the sealed type
     */
    public function __construct(public string $factory)
    {
    }
}
