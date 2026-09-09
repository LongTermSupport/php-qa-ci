# RequireVariadicOverArrayParameterRule — build report

## What was created

1. `src/PHPStan/Rules/RequireVariadicOverArrayParameterRule.php` — `final readonly class`,
   `@implements Rule<FunctionLike>`, identifier constant
   `phpqaci.variadicOverArrayParameter`.
2. `tests/Small/PHPStan/Rules/RequireVariadicOverArrayParameterRuleTest.php` — 3 test methods
   (`RuleTestCase`), covering positive detection, every exclusion, and the
   interface/parent-override boundary.
3. Fixtures under `tests/assets/PHPStan/VariadicOverArrayParameter/`:
   - `Reportable.php` — 4 methods + 1 free function, all expected to fire.
   - `NotReportable.php` — one method per exclusion (promoted+construct, by-ref,
     variadic, map, iterable map, shape, missing `@param`, no docblock, `#[DataProvider]`,
     `#[DataProviderExternal]`).
   - `SupertypeExclusion.php` — an interface + implementer and an abstract parent + child,
     proving the root declaration is flagged and the implementation/override is not.
4. `composer.json` — added the `LTS\PHPQA\Tests\Assets\PHPStan\VariadicOverArrayParameter\`
   autoload-dev mapping (same pattern as the other PHPStan fixture namespaces).
5. `docs/phpstan-rules/require-variadic-over-array-parameter.md` — states plainly that at
   level `max` PHPStan already checks a documented `list<T>` at any call site it can see;
   the rule's value is engine-level enforcement at boundaries PHPStan does not analyse (a
   consumer's override, a dynamically resolved callable) plus removing a docblock that can
   drift from the native type.
6. `docs/phpstan-rules/README.md` — added the index row (opt-in table).
7. `rules-optional.neon` — registered in the `rules:` block, plus a comment block in the
   header matching the existing per-rule commentary style.

### Documentation count drift (pre-existing, fixed as part of step 4 of "Adding a rule")

Before this task, `README.md` and `docs/tools/phpstan.md` already undercounted the opt-in
rules: they said "8 generic / 6 named + 2 service, 12 total with Symfony", but
`rules-optional.neon`'s `rules:` block already had 8 named entries (not 6) before I added a
9th — `ForbidRepeatedStringLiteralRule` and `RequireConsistentMemberDocsRule` were added by
an earlier change without updating the count. I corrected both files to the actual total:
**11 generic (9 named + 2 service), 15 with the Symfony bundle** — this reflects the true
current state including my new rule, not drift I introduced. Flagging in case this was meant
to be tracked/caught elsewhere.

## Design notes

- Node type is `FunctionLike`, narrowed in `processNode` to `ClassMethod`/`Function_` only
  (closures/arrow functions return early) so the rule covers both methods and free functions
  as asked.
- List-shape detection is regex-based over the docblock text (same technique as the existing
  `RequireVariadicForSingleListParamRule` and `RequireEnumOverLiteralUnionRule`), not the
  phpdoc-parser library, to match house style. A `list<T>` / `non-empty-list<T>` match is
  always single-argument; an `array<...>` match is rejected if it contains a top-level comma
  (map) via a small bracket-depth scanner — this also naturally excludes `array{shape}` (no
  `<` follows `array`) and `iterable<K,V>` (keyword literal doesn't match).
- Interface/parent-override detection uses only non-deprecated `ClassReflection` API:
  `getParents()` + `getInterfaces()` (walked, not just immediate) and `hasNativeMethod()`.
  Verified against `build/rector-phar/vendor/phpstan/phpstan/phpstan-src/src/Reflection/ClassReflection.php`
  in this checkout — `hasProperty`/`getProperty`/`isSubclassOf` were not used anywhere.
- `__construct` is excluded outright (regardless of promotion), matching the reasoning that
  PHPStan's neon DI passes one positional value per `arguments:` entry.
- Promoted-parameter exclusion is real but only reachable via `__construct` in valid PHP (a
  promoted parameter can't exist anywhere else), so it can't be tested independently of the
  `__construct` exclusion — the fixture's constructor case exercises both simultaneously.
- `#[DataProvider]` / `#[DataProviderExternal]` are checked against both the FQCN and the
  bare attribute name (`Node\Name::getLast()`), defensively, matching how
  `ForbidAllowMockWithoutExpectationsRule` handles the same ambiguity about whether the AST
  name is pre-resolved.
- No `@phpstan-ignore` or suppression added anywhere. All-or-none member docs respected (no
  class constant in the new rule carries a docblock, matching
  `ForbidRepeatedStringLiteralRule`/`RequireEnumOverLiteralUnionRule`'s style). The literal
  `'array'` keyword is declared once as `ARRAY_KEYWORD` and referenced everywhere else, so
  `phpqaci.repeatedStringLiteral` does not fire on this file (only 1 real occurrence: the
  const's own value; two other sites reference the const, not a literal).

## Verification — NOT run; this agent has no shell/exec tool

This session's tool set was Read/Edit/Write/Glob/Grep/SendMessage only — no Bash. I could
not run the three requested verification commands. I did a full manual trace of the rule's
logic against every fixture method (documented in-thread) and cross-checked the AST/reflection
APIs used directly against the vendored PHPStan/nikic/php-parser source in this checkout, but
this is not a substitute for actually running the suite. **Please run, and treat as
outstanding until someone does:**

```bash
cd /workspace
composer dump-autoload -q
XDEBUG_MODE=off php bin/phpunit -c qaConfig/phpunit.xml --no-coverage --no-progress --filter VariadicOverArrayParameter
bin/phpstan-rule phpqaci.variadicOverArrayParameter tests/assets/PHPStan/VariadicOverArrayParameter/Reportable.php
```

I have not run the full pipeline and have not touched the pre-existing 12 real instances in
`src/`/`tests/` this rule would flag, per instructions.

## Note: an existing, narrower sibling rule

`RequireVariadicForSingleListParamRule` (`phpqaci.arrayListShouldBeVariadic`) already exists
and covers the same idea but only for a method with **exactly one** parameter, does not
special-case `__construct`/`DataProvider`/interface-overrides explicitly (it excludes any
parameter carrying attributes at all, and promoted params, but has no class-reflection
override check), and does not handle `non-empty-list`/bare `array<T>`. The new rule is a
strict superset in scope (last-of-N parameters, both methods and functions) with the fuller
exclusion set the task specified. Both are now registered in `rules-optional.neon`; I did not
touch or remove the older rule since the task asked for a new, separately-identified rule.
