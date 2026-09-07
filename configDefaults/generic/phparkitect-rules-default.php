<?php

declare(strict_types=1);

/**
 * php-qa-ci PHPArkitect — GENERIC DEFAULT ruleset (the "rules-default" tier).
 *
 * Generic, safe-everywhere naming conventions. This tier is applied
 * BY DEFAULT to every consuming project (the shipped configDefaults entry
 * config `phparkitect.php` adds it to the detected source dir), exactly the way
 * rules-default.neon is the PHPStan baseline. Keep it conservative — anything
 * that is not safe across every project belongs in the optional tiers
 * (phparkitect-rules-optional.php, phparkitect-rules-optional-symfony.php).
 *
 * This file returns a list of ArchRule objects; it is NOT a runnable config (no
 * ClassSet). Projects compose it from qaConfig/phparkitect.php:
 *
 *   $default = require getenv('PHPQACI_ARKITECT_RULES_DEFAULT');
 *   $config->add($classSet, ...$default, ...$projectRules);   // EXTEND
 *
 * To CUSTOMISE the baseline itself, copy this file to
 * qaConfig/phparkitect-rules-default.php and edit it — configPath resolves the
 * project copy first, and both the entry config and the
 * PHPQACI_ARKITECT_RULES_DEFAULT env var follow it.
 *
 * @return list<\Arkitect\Rules\ArchRule>
 */

use Arkitect\Expression\ForClasses\HaveNameMatching;
use Arkitect\Expression\ForClasses\IsEnum;
use Arkitect\Expression\ForClasses\IsFinal;
use Arkitect\Expression\ForClasses\IsInterface;
use Arkitect\Expression\ForClasses\IsNotEnum;
use Arkitect\Expression\ForClasses\IsNotInterface;
use Arkitect\Expression\ForClasses\IsNotTrait;
use Arkitect\Expression\ForClasses\IsReadonly;
use Arkitect\Expression\ForClasses\IsTrait;
use Arkitect\Expression\ForClasses\ResideInOneOfTheseNamespaces;
use Arkitect\Rules\Rule;

// These rules match on AST node KIND (interface/enum/trait keyword), on the
// class NAME, and on the NAMESPACE — never on ancestry, so PHPArkitect is never
// forced to resolve a parent type. That keeps them robust even when a project
// references a base type that is not in the autoloader. Rules that DO resolve
// ancestry (IsA/Extend/Implement, e.g. the *Exception convention) live in the
// optional tier, because they require a complete autoloader and are therefore
// not safe-everywhere.

// The namespace patterns matching a `Dto` SEGMENT of a namespace. Arkitect's
// ResideInOneOfTheseNamespaces appends its own trailing '*', so '*\Dto\'
// becomes the FQCN glob '*\Dto\*' — a segment named exactly `Dto` somewhere in
// the middle. The bare 'Dto\' form additionally covers a root-level `Dto`
// namespace (a project with no vendor prefix). The trailing separator is what
// makes this exact: without it, '*\Dto' would also match a `Dtos` segment.
$dtoNamespaces = ['*\\Dto\\', 'Dto\\'];

// Every Dto rule below repeats the same three andThat() guards, skipping
// interfaces, enums and traits. Those kinds carry their OWN suffix under the
// rules above (a `FooDtoInterface` is correctly named), so applying the *Dto
// suffix / final / readonly expectations to them would contradict this very
// tier. The guards must be stated explicitly: Arkitect honours an expression's
// appliesTo() only in `that()`, never in `should()`, so IsFinal / IsReadonly
// carrying their own kind carve-out does NOT protect a `should()` clause.

return [
    // Interfaces / enums / traits carry their kind in the suffix.
    // This tier is the SINGLE SOURCE OF TRUTH for type-suffix naming: the former
    // PHPStan RequireTypeSuffixRule was migrated here (structural naming belongs
    // in PHPArkitect, not PHPStan), so do not re-introduce a PHPStan equivalent.
    Rule::allClasses()
        ->that(new IsInterface())
        ->should(new HaveNameMatching('*Interface'))
        ->because('an Interface suffix makes the symbol kind obvious at every use site'),
    Rule::allClasses()
        ->that(new IsEnum())
        ->should(new HaveNameMatching('*Enum'))
        ->because('an Enum suffix makes the symbol kind obvious at every use site'),
    Rule::allClasses()
        ->that(new IsTrait())
        ->should(new HaveNameMatching('*Trait'))
        ->because('a Trait suffix makes the symbol kind obvious at every use site'),

    // DTOs are suffixed *Dto, live in a Dto namespace, and are immutable value
    // carriers. The three rules below are one convention read from three sides:
    // the namespace implies the suffix, the suffix implies the namespace, and
    // the suffix implies `final readonly`. Together they stop a DTO being
    // mistaken for a service and stop a service hiding in the DTO namespace.
    Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces(...$dtoNamespaces))
        ->andThat(new IsNotInterface())
        ->andThat(new IsNotEnum())
        ->andThat(new IsNotTrait())
        ->should(new HaveNameMatching('*Dto'))
        ->because('a Dto namespace holds data transfer objects, so everything in it carries the Dto suffix'),
    Rule::allClasses()
        ->that(new HaveNameMatching('*Dto'))
        ->andThat(new IsNotInterface())
        ->andThat(new IsNotEnum())
        ->andThat(new IsNotTrait())
        ->should(new ResideInOneOfTheseNamespaces(...$dtoNamespaces))
        ->because('keeping every Dto in a Dto namespace makes the data-transfer layer discoverable in one place'),
    Rule::allClasses()
        ->that(new HaveNameMatching('*Dto'))
        ->andThat(new IsNotInterface())
        ->andThat(new IsNotEnum())
        ->andThat(new IsNotTrait())
        ->should(new IsFinal())
        ->because('a Dto is a value carrier, not an extension point — subclassing one invites behaviour where there should be none'),
    Rule::allClasses()
        ->that(new HaveNameMatching('*Dto'))
        ->andThat(new IsNotInterface())
        ->andThat(new IsNotEnum())
        ->andThat(new IsNotTrait())
        ->should(new IsReadonly())
        ->because('a readonly Dto cannot be mutated after construction, so what a caller receives is what the producer sent'),
];
