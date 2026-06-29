<?php

declare(strict_types=1);

use Arkitect\Expression\ForClasses\NotDependsOnTheseNamespaces;
use Arkitect\Expression\ForClasses\NotResideInTheseNamespaces;
use Arkitect\Rules\Rule;

/**
 * Reusable CONSUMER API-boundary rule factory.
 *
 * Returns PHPArkitect rules a *consumer* of a `type: library` package applies to
 * its OWN `src/` to enforce that it reaches the library only through the
 * library's public `@api` namespace — never its `@internal` ones. This is the
 * hard, shipped counterpart to the per-class `@api`/`@internal` classification
 * (see docs/tools/requireApiOrInternal.md): the library declares its surface,
 * the consumer's CI forbids crossing it.
 *
 * Loaded the same way as the shipped rule tiers — via an env var the pipeline
 * exports — from a consumer's `qaConfig/phparkitect.php`:
 *
 *     $consumerMustOnlyDependOn = require getenv('PHPQACI_ARKITECT_CONSUMER_API_BOUNDARY');
 *     $config->add(
 *         ClassSet::fromDir(__DIR__ . '/../src'),
 *         ...$consumerMustOnlyDependOn(
 *             'Ballicom\AccountsIq\Facade',          // the library's public @api namespace
 *             'Ballicom\AccountsIq\Gateway',         // its @internal namespaces …
 *             'Ballicom\AccountsIq\Api',
 *         ),
 *     );
 *
 * The rule applies to every class that is NOT itself part of the library's
 * namespaces (i.e. the consumer's own code) and forbids it from depending on any
 * listed internal namespace. The public namespace stays allowed.
 *
 * @return callable(string, string...): list<Rule>
 */
return static function (string $publicNamespace, string ...$internalNamespaces): array {
    if ([] === $internalNamespaces) {
        throw new InvalidArgumentException(
            'consumerMustOnlyDependOn() requires at least one internal namespace to forbid '
            . '(the library namespaces consumers must not reach into).',
        );
    }

    return [
        Rule::allClasses()
            ->that(new NotResideInTheseNamespaces($publicNamespace, ...$internalNamespaces))
            ->should(new NotDependsOnTheseNamespaces($internalNamespaces))
            ->because(\sprintf(
                'consumers must reach this library only through its public @api namespace %s; '
                . '%s are @internal and may change without notice',
                $publicNamespace,
                implode(', ', $internalNamespaces),
            )),
    ];
};
