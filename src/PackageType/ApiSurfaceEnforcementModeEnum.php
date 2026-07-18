<?php

declare(strict_types=1);

namespace LTS\PHPQA\PackageType;

use InvalidArgumentException;

/**
 * Tri-state control for the API-surface classification rules
 * ({@see \LTS\PHPQA\PHPStan\Rules\RequireApiOrInternalTagRule} and
 * {@see \LTS\PHPQA\PHPStan\Rules\ApiMustNotExposeInternalRule}), resolved by
 * {@see ProjectComposerTypeReader::enforcesApiSurface()}.
 *
 * The historic behaviour was hard-wired: enforce iff the composer `type` is
 * `library`. That conflates "is a consumer contract" with the composer `type`,
 * which is wrong for a package that IS a consumable library yet must carry a
 * different `type` for an unrelated reason — the motivating case is a
 * `composer-plugin` that self-deploys but still ships a public API
 * (`ballicom/ballicom-sql`). A composer-plugin is a form of library, not an
 * application, so its public surface deserves the same discipline.
 *
 * This enum makes the decision configurable via `phpqaciApiOrInternal.enforce`:
 *
 *   - {@see self::Auto}   — `auto` (DEFAULT): enforce iff the composer `type` is
 *                           `library`. Byte-for-byte the historic behaviour, so
 *                           every existing consumer is unaffected.
 *   - {@see self::Always} — `always` (force ON): enforce regardless of composer
 *                           `type`. The opt-in a library-with-a-non-library-type
 *                           sets to keep its `@api`/`@internal` discipline.
 *   - {@see self::Never}  — `never` (force OFF): never enforce, regardless of
 *                           composer `type`.
 */
enum ApiSurfaceEnforcementModeEnum: string
{
    /**
     * Parse a config token (case-insensitive, trimmed) into a mode, failing fast
     * with a listing of the valid tokens rather than a bare \ValueError — the value
     * is author-typed in a consumer's `phpstan.neon`.
     */
    public static function fromConfig(string $value): self
    {
        $mode = self::tryFrom(strtolower(trim($value)));

        if (null === $mode) {
            throw new InvalidArgumentException(\sprintf(
                'Invalid phpqaciApiOrInternal.enforce value "%s"; expected one of: %s.',
                $value,
                implode(', ', array_map(static fn (self $case): string => $case->value, self::cases())),
            ));
        }

        return $mode;
    }
    case Auto   = 'auto';
    case Always = 'always';
    case Never  = 'never';
}
