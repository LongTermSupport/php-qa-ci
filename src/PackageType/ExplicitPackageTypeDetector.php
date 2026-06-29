<?php

declare(strict_types=1);

namespace LTS\PHPQA\PackageType;

/**
 * Pure, framework-free decision for the "declare your package type" check.
 *
 * A `composer.json` MUST declare `type` EXPLICITLY — Composer silently defaults
 * an omitted `type` to `library`, which hides whether the package is an
 * installable dependency or an application. Declaring it makes that decision
 * conscious (and gates php-qa-ci's library-only API-surface enforcement).
 *
 * Returns `null` when `type` is explicitly declared (any non-blank string —
 * `library`, `project`, or another valid Composer type), otherwise an actionable
 * error message. The decoded composer.json is passed in so this stays pure and
 * unit-testable; the pipeline reads the real file via {@see \LTS\PHPQA\Helper}.
 */
final class ExplicitPackageTypeDetector
{
    /**
     * @param array<int|string, mixed> $composerJson the decoded composer.json
     *
     * @return string|null null when OK; otherwise the error to fail the build with
     */
    public function check(array $composerJson): ?string
    {
        $type = $composerJson['type'] ?? null;

        if (\is_string($type) && '' !== trim($type)) {
            return null;
        }

        return 'composer.json must declare "type" explicitly: "library" (an installable dependency) '
            . 'or "project" (an application). Composer silently defaults an omitted "type" to "library", '
            . 'which hides the app-vs-library decision — declare it so the choice is conscious. '
            . 'A library additionally has its public surface checked: every public class must carry '
            . '@api or @internal.';
    }
}
