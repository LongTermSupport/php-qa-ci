<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

/**
 * Pure, framework-free decision logic for {@see RequireApiOrInternalTagRule}.
 *
 * Extracted from the rule so the "given whether a public class-like carries `@api`
 * / `@internal`, is it correctly classified?" decision is unit-testable without
 * PHPStan's Scope (the rule that drives this runs under the bundled PHPStan PHAR).
 *
 * This is PURE tag logic — it assumes enforcement is already ON for the project.
 * WHETHER to enforce (the package-type gate plus the `auto`/`always`/`never`
 * override) is decided upstream by
 * {@see \LTS\PHPQA\PackageType\ProjectComposerTypeReader::enforcesApiSurface()};
 * the rule calls this only after that gate passes.
 *
 * Policy: a public class-like must carry exactly one of `@api` (supported contract)
 * or `@internal` (may change) — neither ⇒ {@see ApiOrInternalTagVerdictEnum::Missing},
 * both ⇒ {@see ApiOrInternalTagVerdictEnum::Both} (contradictory), exactly one ⇒
 * {@see ApiOrInternalTagVerdictEnum::Ok}.
 */
final class ApiOrInternalTagDetector
{
    public function classify(bool $hasApi, bool $hasInternal): ApiOrInternalTagVerdictEnum
    {
        if ($hasApi && $hasInternal) {
            return ApiOrInternalTagVerdictEnum::Both;
        }

        if (!$hasApi && !$hasInternal) {
            return ApiOrInternalTagVerdictEnum::Missing;
        }

        return ApiOrInternalTagVerdictEnum::Ok;
    }
}
