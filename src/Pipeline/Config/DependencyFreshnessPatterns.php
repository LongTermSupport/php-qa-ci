<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config;

use LogicException;

/**
 * The package-name patterns the dependencyFreshness lane checks: exact
 * `vendor/package` names, or globs such as `acme/*`. php-qa-ci's own name is
 * always the first entry, whatever a project configures, so the only way to
 * stop the self-check is a pin in composer.json that leaves `composer update`
 * nothing newer to install.
 *
 * @internal
 */
final readonly class DependencyFreshnessPatterns
{
    /** php-qa-ci's own package name, the entry no configuration can drop. */
    public const string SELF_PACKAGE = 'lts/php-qa-ci';

    /** A Composer package name, lower case, with `*`, `?` and `[...]` allowed in either half. */
    private const string SHAPE = '/^[a-z0-9_.*?\[\]-]+\/[a-z0-9_.*?\[\]-]+$/';

    /** @var list<string> php-qa-ci first, then each configured pattern once */
    public array $patterns;

    public function __construct(string ...$patterns)
    {
        foreach ($patterns as $pattern) {
            if (1 !== \Safe\preg_match(self::SHAPE, $pattern)) {
                throw new LogicException(\sprintf("dependency freshness pattern '%s' does not name a package: use a lower-case vendor/package name, or a glob such as acme/*.", $pattern));
            }
        }

        $this->patterns = array_values(array_unique([self::SELF_PACKAGE, ...array_values($patterns)]));
    }

    /** Whether $package, a locked package name, is one this check covers. A `*` never crosses the `/`. */
    public function matches(string $package): bool
    {
        return array_any($this->patterns, static fn (string $pattern): bool => fnmatch($pattern, $package, FNM_PATHNAME));
    }
}
