# Package Type Declaration Check

An **always-on** php-qa-ci pipeline tool that fails the build unless the
project's `composer.json` declares its `type` **explicitly**.

Composer silently defaults an omitted `type` to `library`. That default quietly
makes a real architectural decision — *is this an installable dependency or an
application?* — without anyone choosing it. This check rejects the silent default
so the decision is **conscious**: the file must say `library`, `project`, or any
other explicit Composer type.

## Why it matters

The declared type drives php-qa-ci's API-surface discipline:

- **`type: library`** — an installable dependency. Its public surface is a
  consumer contract, so the
  [`RequireApiOrInternalTagRule`](requireApiOrInternal.md) PHPStan rule then
  requires every public class-like to be classified `@api` / `@internal`.
- **`type: project`** — an application. No consumers, so the classification rule
  no-ops.
- **other explicit types** (`metapackage`, `composer-plugin`, …) — accepted as a
  conscious declaration; treated as non-library for classification.

If the type were left to Composer's default, a library could ship an
unclassified, accidental public surface. Forcing the declaration is the forcing
function that makes the rest of the discipline reliable.

## How it runs

- **Part of the full pipeline**: runs automatically in the **linting phase** of a
  plain `bin/qa`, right after the Composer checks.
- **Standalone**: `vendor/bin/qa -t packageType` (aliases: `-t pt`, `-t packagetype`).
- **Binary**: `bin/package-type-check` (delegates to
  `LTS\PHPQA\PackageType\ExplicitPackageTypeCheck::main()`).

The tool reads `{projectRoot}/composer.json` and:

- **passes (exit 0)** when `type` is a non-blank string; or
- **fails (exit 1)** when `type` is absent, blank, or not a string — printing the
  guidance to declare it.

## No opt-out

Unlike most baseline checks this one has **no escape hatch**: declaring one
`composer.json` line is trivial, and the app-vs-library decision must always be
made. Every consumer's `composer.json` must carry an explicit `type`.

## How to fix a failure

Add an explicit `type` to `composer.json`:

```json
{
    "name": "acme/widget",
    "type": "library"
}
```

Choose:

- **`library`** if this package is installed as a dependency by other projects
  (then classify its public surface — see
  [requireApiOrInternal.md](requireApiOrInternal.md)).
- **`project`** if this is a deployable application with no consumers.

## Design / implementation notes

- Pure decision core:
  [`ExplicitPackageTypeDetector`](./../../src/PackageType/ExplicitPackageTypeDetector.php)
  — `check(decoded composer.json): ?string` (null = OK, else the guidance
  message); exhaustively unit-tested.
- Thin I/O runner:
  [`ExplicitPackageTypeCheck`](./../../src/PackageType/ExplicitPackageTypeCheck.php)
  — maps the verdict to stdout + exit code.
