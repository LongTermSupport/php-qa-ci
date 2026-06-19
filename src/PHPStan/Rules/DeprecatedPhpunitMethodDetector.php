<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use Deprecated;
use ReflectionClass;
use ReflectionMethod;

/**
 * Discovers, via reflection, the set of method names deprecated by the installed
 * PHPUnit (or any configured class list). A method counts as deprecated if it
 * carries a `@deprecated` docblock tag or the native PHP 8.4 `#[\Deprecated]`
 * attribute.
 *
 * The set is computed ONCE per detector instance and memoised — reflection runs
 * a single time, then every {@see self::isDeprecated()} query is an array lookup.
 * A PHPStan rule constructs the detector once per analysis run, so the whole file
 * sweep shares one reflection pass (the fastest viable approach without a
 * persisted cache).
 *
 * Deriving the set dynamically keeps the companion {@see ForbidDeprecatedPhpunitMethodRule}
 * correct as PHPUnit evolves — no hard-coded method list to maintain.
 */
final class DeprecatedPhpunitMethodDetector
{
    /**
     * @var array<string, true>|null
     */
    private ?array $cache = null;

    /**
     * @param list<string> $classes the PHPUnit classes to scan (e.g. TestCase, Assert). Names that
     *                              do not resolve to a loadable class are skipped, so plain strings
     *                              are accepted. Inherited methods are included, so scanning
     *                              TestCase alone already covers its Assert ancestry.
     */
    public function __construct(private readonly array $classes)
    {
    }

    public function isDeprecated(string $method): bool
    {
        return isset($this->deprecatedMethods()[$method]);
    }

    /**
     * @return array<string, true> a set of deprecated method names (name => true)
     */
    public function deprecatedMethods(): array
    {
        if (null !== $this->cache) {
            return $this->cache;
        }

        $deprecated = [];

        foreach ($this->classes as $class) {
            if (!class_exists($class)) {
                continue;
            }

            foreach (new ReflectionClass($class)->getMethods() as $method) {
                if ($this->isDeprecatedMethod($method)) {
                    $deprecated[$method->getName()] = true;
                }
            }
        }

        return $this->cache = $deprecated;
    }

    private function isDeprecatedMethod(ReflectionMethod $method): bool
    {
        $docComment = $method->getDocComment();

        if (false !== $docComment && str_contains($docComment, '@deprecated')) {
            return true;
        }

        return [] !== $method->getAttributes(Deprecated::class);
    }
}
