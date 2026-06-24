<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

/**
 * Pure, framework-free decision logic for {@see RequireApiOrInternalTagRule}.
 *
 * Extracted from the rule so the "must this public class-like carry `@api` /
 * `@internal`, and is it correct?" decision is unit-testable without PHPStan's
 * Scope (the rule that drives this runs under the bundled PHPStan PHAR).
 *
 * Policy (package-type-aware):
 *   - The project's composer `type` is **`library`** (an installable dependency,
 *     and Composer's default when `type` is omitted): its public surface is a
 *     consumer contract, so every public class-like must be classified as exactly
 *     one of `@api` (supported) or `@internal` (may change) — neither ⇒ Missing,
 *     both ⇒ Both (contradictory).
 *   - Any other type (`project` application, `metapackage`, …): no consumer-facing
 *     API surface, so the classification is not required — always {@see ApiOrInternalTagVerdict::Ok}.
 *
 * The `type` string is normalised (trimmed + lower-cased) because it is
 * author-typed free text in composer.json.
 */
final class ApiOrInternalTagDetector
{
    private const string LIBRARY_TYPE = 'library';

    public function classify(string $projectType, bool $hasApi, bool $hasInternal): ApiOrInternalTagVerdict
    {
        if (self::LIBRARY_TYPE !== \strtolower(\trim($projectType))) {
            return ApiOrInternalTagVerdict::Ok;
        }

        if ($hasApi && $hasInternal) {
            return ApiOrInternalTagVerdict::Both;
        }

        if (!$hasApi && !$hasInternal) {
            return ApiOrInternalTagVerdict::Missing;
        }

        return ApiOrInternalTagVerdict::Ok;
    }
}
