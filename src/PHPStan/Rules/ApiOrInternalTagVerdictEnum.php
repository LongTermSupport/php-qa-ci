<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

/**
 * The outcome of {@see ApiOrInternalTagDetector::classify()} for one public
 * class-like in a `type: library` project.
 *
 *   - {@see self::Ok}      — not a library, OR classified with exactly one of
 *                            `@api` / `@internal` (the desired state).
 *   - {@see self::Missing} — a library's public class-like carries neither tag:
 *                            it must declare whether it is part of the supported
 *                            API (`@api`) or internal (`@internal`).
 *   - {@see self::Both}    — it carries BOTH tags: contradictory; a symbol is
 *                            either public API or internal, never both.
 */
enum ApiOrInternalTagVerdict
{
    case Ok;
    case Missing;
    case Both;
}
