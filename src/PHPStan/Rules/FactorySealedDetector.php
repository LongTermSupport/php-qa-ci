<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

/**
 * Pure, framework-free decision logic for {@see FactorySealedRule}.
 *
 * Extracted from the rule so the "is this construction a violation?" decision
 * can be unit-tested without PHPStan's Scope or RuleTestCase (PHPStan ships as a
 * PHAR here, so neither is autoloadable as a Composer class).
 *
 * Decision (given the resolved facts):
 *   - target is not sealed (no sealing attribute) → no violation;
 *   - file lives under tests/                      → no violation;
 *   - enclosing class IS the authorised factory    → no violation;
 *   - otherwise                                     → violation.
 */
final readonly class FactorySealedDetector
{
    private const string TESTS_PATH_FRAGMENT = '/tests/';

    /**
     * @param string|null $sealedFactory  the authorised factory FQCN read from the
     *                                    target's sealing attribute, or null when
     *                                    the target is not sealed
     * @param string|null $enclosingClass the FQCN of the class containing the `new`,
     *                                    or null at file scope
     * @param string      $filePath       the file containing the construction
     */
    public function isViolation(?string $sealedFactory, ?string $enclosingClass, string $filePath): bool
    {
        if (null === $sealedFactory) {
            return false;
        }

        if (str_contains($filePath, self::TESTS_PATH_FRAGMENT)) {
            return false;
        }

        return $enclosingClass !== $sealedFactory;
    }
}
