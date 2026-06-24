# RequireApiOrInternalTagRule — package-type-aware API-surface classification

A **default-on** PHPStan rule (`phpqaci.requireApiOrInternalTag`) that makes a
library declare its public contract explicitly: when the consuming project's
Composer `type` is **`library`**, every public class-like must be classified as
exactly one of `@api` or `@internal`.

It ships in [`rules-default.neon`](./../../rules-default.neon), so it runs for any
project that includes php-qa-ci's default rule set — no opt-in.

## What it enforces

For a `type: library` package (an installable dependency — **and Composer's silent
default when `type` is omitted**), each public class-like (`class`, `interface`,
`enum`, `trait`) must carry exactly one classifying tag in its docblock:

| Docblock                        | Verdict     | Result                          |
| ------------------------------- | ----------- | ------------------------------- |
| `@api` only                     | classified  | ✅ pass                         |
| `@internal` only                | classified  | ✅ pass                         |
| neither `@api` nor `@internal`  | **Missing** | ❌ "classify it"                |
| **both** `@api` and `@internal` | **Both**    | ❌ "contradiction — choose one" |

For any other package type (`project` application, `metapackage`,
`composer-plugin`, …) there is no consumer-facing surface, so the rule **no-ops**.

Anonymous classes are skipped (no name to classify, no public contract).

> **Why per-class classification?** `@internal` is the ecosystem-standard,
> tool-enforced marker (PHPStan/Psalm/PhpStorm all treat a root-namespace
> `@internal` symbol as off-limits to consumers), but **no tool infers the
> inverse** — "this isn't `@api`, therefore it's internal". So the only
> enforceable model is to require an explicit, per-class choice. The rule forces
> the choice to be *made*; making it *correctly* is the author's job (below).

## The `@api` vs `@internal` judgement (read this before tagging)

This rule is a **quality ratchet**: it does not decide for you, it makes you
decide deliberately. The choice is genuinely two-sided:

- **`@internal`** — not part of the supported contract; the library may change or
  remove it in any release. Marking something `@internal` that consumers
  legitimately need **over-restricts** them (or pushes them to depend on internals
  anyway).
- **`@api`** — a supported public contract. Changing its signature/behaviour later
  is a **breaking change** for every consumer. Marking something `@api`
  prematurely **commits the library** to maintaining it.

**Safe default: tag `@internal`.** Promote a class to `@api` only when consumers
genuinely need it *and* the library is willing to support it long-term. A small,
deliberate `@api` surface (often a single facade) is the goal; everything behind
it stays `@internal` and free to evolve.

## Exempting generated / managed code

Generated and managed trees cannot carry hand-authored tags. Exempt them by
fully-qualified namespace **prefix** in your `qaConfig/phpstan.neon`:

```neon
parameters:
    phpqaciApiOrInternal:
        ignoredNamespacePrefixes:
            - App\Generated
            - App\PhpQaCi
```

The default is an empty list (nothing exempt).

## How to fix a violation

- **Missing** — add one tag to the class docblock. Default to `@internal`:

  ```php
  /**
   * @internal
   */
  final class OrderMapper { /* … */ }
  ```

  Promote to `@api` only for the deliberate public surface.

- **Both** — remove the wrong one. A class is either supported (`@api`) or not
  (`@internal`), never both.

## Relationship to the explicit-`type` requirement

This rule treats an **undeclared** `type` as `library` (the safe side — keep the
surface discipline on). Separately, the always-on
[Package Type Declaration Check](packageType.md) **hard-fails** a `composer.json`
that does not declare `type` explicitly, so the app-vs-library decision is
conscious rather than inherited from Composer's silent default. A package that is
really an application sets `type: project` (and this rule then no-ops); a real
library sets `type: library` and classifies its surface.

## Design / implementation notes

- Pure decision core: [`ApiOrInternalTagDetector`](./../../src/PHPStan/Rules/ApiOrInternalTagDetector.php)
  (+ `ApiOrInternalTagVerdict`) — unit-tested exhaustively, no PHPStan Scope needed.
- Rule: [`RequireApiOrInternalTagRule`](./../../src/PHPStan/Rules/RequireApiOrInternalTagRule.php)
  (hooks `InClassNode`; reads the class docblock for a standalone `@api`/`@internal`
  tag).
- Package kind: [`ProjectComposerTypeReader`](./../../src/PackageType/ProjectComposerTypeReader.php)
  — injectable seam over the project `composer.json` (mockable in tests).
