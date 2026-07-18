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

## Dev-only code is skipped automatically

Code under an **`autoload-dev`** PSR-4 namespace (tests, dev/maintainer tooling,
QA config) is never installed in a production/runtime install, so it forms no
consumer contract and has nothing to classify. The rule reads your
`composer.json` `autoload-dev.psr-4` prefixes and skips any class-like under one
— **no configuration, and you never hand-tag your own test suite.**

## Exempting runtime generated / managed code

A generated or managed tree that lives under the **runtime** `autoload` namespace
(so the dev skip above does not cover it) yet cannot carry hand-authored tags is
exempted by fully-qualified namespace **prefix** in your `qaConfig/phpstan.neon`:

```neon
parameters:
    phpqaciApiOrInternal:
        ignoredNamespacePrefixes:
            - App\Generated
```

The default is an empty list. Prefer to **classify** generated code where you can
— e.g. have your generator stamp `@internal` on every emitted class-like — so the
rule keeps enforcing over it rather than skipping a whole tree. (php-qa-ci's own
[managed source](../../CLAUDE/managed-source.md) does exactly this: the
`FactorySealedBy` artefact it writes into your `<RootNs>\PhpQaCi\` namespace is
generated already carrying `@internal`, so no exemption is needed.)

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

## Overriding enforcement by package `type` (`auto` / `always` / `never`)

By default enforcement is **`auto`**: both API-surface rules
(RequireApiOrInternalTagRule and the coherence rule below) enforce **iff** the
composer `type` is `library`. That is right for the common case, but a package can
be a genuine consumable **library** yet be forced to carry a **non-`library`**
composer `type` for an unrelated reason — the motivating case is a package that
must be `type: composer-plugin` so Composer will activate its self-deployment,
while it still ships a public API its consumers depend on. A `composer-plugin` is a
form of library, not an application, so its public surface deserves the same
discipline.

Set `phpqaciApiOrInternal.enforce` to override the `type` gate:

```neon
parameters:
    phpqaciApiOrInternal:
        # auto (default) — enforce iff composer `type` is `library`
        # always         — enforce regardless of `type` (a library that carries a
        #                   non-library type, e.g. a self-deploying composer-plugin)
        # never          — never enforce, regardless of `type`
        enforce: always
```

The setting governs **both** API-surface rules (presence *and* coherence). `auto`
is byte-for-byte the historic behaviour, so existing projects are unaffected.

## Coherence: an `@api` class must not expose `@internal` (default-on)

Classifying every class-like is necessary but not sufficient — the classification
can still be **incoherent**. If an `@api` class exposes an `@internal` one through
its public signature (a public method's parameter or return type, or a public
property), a consumer using the supported `@api` type is forced to touch the
`@internal` one and PHPStan flags them with `class.internal` / `method.internal`.
The `@api` promise is hollow.

A second **default-on** rule, `phpqaci.apiMustNotExposeInternal`, catches that
leak at the library's own gate. For a `type: library` project it scans every
`@api` class-like's public surface and errors if a referenced class-like **in the
same package** (same root namespace segment — the boundary PHPStan keys
`@internal` on) is `@internal`. Third-party `@internal` types (a different root
namespace) are not flagged — that is the other package's concern.

A **member** that is itself tagged `@internal` (a factory-only `__construct`, or
an internal mapping bridge) is skipped — it is not part of the consumer surface,
so an internal type reached only through it is not a leak. This is how an `@api`
class legitimately keeps internal wiring (e.g. a facade whose constructor takes
`@internal` gateways but is only ever called by the factory).

Fix a real violation by either **promoting** the referenced type to `@api`
(commit to it as public contract), **keeping it off the public surface** — e.g.
map it to an `@api` DTO at the boundary so the internal type never crosses it — or
marking the exposing **member** `@internal` if it is genuinely not for consumers. Together the
two rules give *presence* (everything classified) and *coherence* (the public
surface is closed over `@api`).

## Enforcing the boundary in consumers

Classifying the surface declares the contract; it does not stop a *consumer* from
reaching into `@internal` code anyway. php-qa-ci ships a reusable PHPArkitect
**consumer API-boundary factory** for that hard half — a consumer applies it to
its own `src/` to forbid depending on a library's internal namespaces (only the
public `@api` namespace is allowed).

It is loaded the same way as the shipped rule tiers — via an env var the pipeline
exports (`PHPQACI_ARKITECT_CONSUMER_API_BOUNDARY`) — from the consumer's
`qaConfig/phparkitect.php`:

```php
$consumerMustOnlyDependOn = require getenv('PHPQACI_ARKITECT_CONSUMER_API_BOUNDARY');

$config->add(
    ClassSet::fromDir(__DIR__ . '/../src'),
    ...$consumerMustOnlyDependOn(
        'Ballicom\AccountsIq\Facade',   // the library's public @api namespace
        'Ballicom\AccountsIq\Gateway',  // its @internal namespaces, off-limits …
        'Ballicom\AccountsIq\Api',
    ),
);
```

Any consumer class that depends on a listed internal namespace then fails
`bin/qa -t arch`, naming the offending class. The factory lives at
[`configDefaults/generic/phparkitect-consumer-api-boundary.php`](./../../configDefaults/generic/phparkitect-consumer-api-boundary.php).

## Design / implementation notes

- Pure decision core: [`ApiOrInternalTagDetector`](./../../src/PHPStan/Rules/ApiOrInternalTagDetector.php)
  (+ `ApiOrInternalTagVerdictEnum`) — unit-tested exhaustively, no PHPStan Scope needed.
- Rule: [`RequireApiOrInternalTagRule`](./../../src/PHPStan/Rules/RequireApiOrInternalTagRule.php)
  (hooks `InClassNode`; reads the class docblock for a standalone `@api`/`@internal`
  tag).
- Package kind: [`ProjectComposerTypeReader`](./../../src/PackageType/ProjectComposerTypeReader.php)
  — injectable seam over the project `composer.json` (mockable in tests).
- Dev-namespace skip: [`DevAutoloadNamespaceReader`](./../../src/PackageType/DevAutoloadNamespaceReader.php)
  — reads `autoload-dev.psr-4` prefixes (same injectable-override pattern).
- Coherence rule: [`ApiMustNotExposeInternalRule`](./../../src/PHPStan/Rules/ApiMustNotExposeInternalRule.php)
  (hooks `InClassNode`; reflects each `@api` class's public surface and flags
  same-package `@internal` references).
