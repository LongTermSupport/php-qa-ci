# Phase 3 review recon: spaze/phpstan-disallowed-calls & shipmonk/dead-code-detector

Fetched via `https://repo.packagist.org/p2/...json` (Composer's v2 metadata API) and GitHub raw
files. Raw dumps cached under `/workspace/untracked/scratch/spaze/` and
`/workspace/untracked/scratch/shipmonk/`.

## Package 1: spaze/phpstan-disallowed-calls

### 1. Version / require / maintenance

- Latest stable (packagist p2, `spaze/phpstan-disallowed-calls`): **v4.14.0**, published
  2026-08-02T15:18:24Z (per `time`), 2026-08-02T15:32:12Z (`published-time`).
- `require`: `"php": "^7.4 || ^8.0"`, `"phpstan/phpstan": "^1.12.6 || ^2.0"`.
- No upper PHP bound (`^8.0` is open-ended), so **nominally PHP-8.5-compatible by constraint**.
  I did not run it under PHP 8.5 myself — whether the code actually parses/executes cleanly under
  8.5 is **unverified** (no CI matrix entry for 8.5 seen in the fetched files; I did not fetch
  `.github/workflows/*`).
- Release cadence: many releases through 2026 (v4.9.x series Jan-Mar 2026, v4.14.0 Aug 2026) —
  actively maintained.
- `type: phpstan-extension` (composer.json) → **works with phpstan/extension-installer** (auto
  registers via `extra.phpstan.includes: [extension.neon]`), confirmed by README: "If you use
  phpstan/extension-installer, you are all set and can skip to configuration."

### 2. Rule types (from `docs/custom-rules.md`)

Exact neon parameter keys, each a list of structured entries:

| Key | Detects |
|---|---|
| `disallowedMethodCalls` | `$object->method()` calls |
| `disallowedStaticCalls` | `Class::method()` |
| `disallowedFunctionCalls` | `function()` (also covers language constructs treated as functions: `die()`, `echo()`, `empty()`, `eval()`, `exit()`, `isset()`, `print()`, `unset()`; naive `new ClassName()` is expressed as `disallowedMethodCalls` on `Class::__construct`) |
| `disallowedConstants` | constants, incl. class constants (split into `class:`/`constant:`) |
| `disallowedNamespaces` / `disallowedClasses` (alias) | class/namespace usage |
| `disallowedSuperglobals` | `$GLOBALS`, `$_POST`, etc. |
| `disallowedAttributes` | attribute usage, e.g. `#[Entity(...)]` |
| `disallowedEnums` | enum cases (split into `enum:`/`case:`) |
| `disallowedControlStructures` | `if`/`else`/loops/`require`/`include`/`goto` etc. |
| `disallowedKeywords` | currently only `global` |
| `disallowedProperties` | instance/static/enum properties |

Also confirmed via `extension.neon`'s `parametersSchema`: the same 11 keys plus
`allowInRootDir` (deprecated alias of `filesRootDir`).

### 3. Shipped deny-list neon files

All at package root (fetched raw from `main` branch — exact paths, one level, no subdir):

- **`disallowed-execution-calls.neon`** — program-execution functions: `exec()`, `passthru()`,
  `proc_open()`, `shell_exec()` (also bans the backtick operator), `system()`, `pcntl_exec()`,
  `popen()`.
- **`disallowed-dangerous-calls.neon`** — `apache_setenv()`, `dl()`, `eval()`,
  `create_function()`, `extract()`, `posix_getpwuid()`, `posix_kill()`, `posix_mkfifo()`,
  `posix_mknod()`, `highlight_file()`, `show_source()`, `pfsockopen()`, `print_r()` (allowed with
  2nd param = return-string), `proc_nice()`, `putenv()`, `socket_create_listen()`,
  `socket_listen()`, `var_dump()`, `var_export()` (allowed with 2nd param), `phpinfo()`.
- **`disallowed-insecure-calls.neon`** — weak hashing (`md5()`, `sha1()`, `md5_file()`,
  `sha1_file()`, `hash()`/`hash_file()`/`hash_init()` when algo is md5/sha1), weak randomness
  (`rand()`, `mt_rand()`, `lcg_value()`, `uniqid()`), legacy unparametrized MySQL/mysqli query
  functions and `mysqli::query()`/`multi_query()`/`real_query()` method calls (SQLi risk).
  Note this is **not our concern's overlap** — see below.
- **`disallowed-loose-calls.neon`** — `in_array()` without strict 3rd param, `htmlspecialchars()`
  without `ENT_QUOTES` flag.
- **`disallowed-non-timing-safe-calls.neon`** — `hex2bin()`, `bin2hex()`, `base64_decode()`,
  `base64_encode()` (recommends sodium timing-safe equivalents for secret handling).

I grepped all five files for `unserialize` and `parse_str`: **neither appears in any shipped
file.**

### 4. Error identifiers

From `src/RuleErrors/ErrorIdentifiers.php` (class constants, string values are the actual
PHPStan `identifier()` strings): `disallowed.attribute`, `disallowed.backtick`,
`disallowed.break`, `disallowed.class`, `disallowed.classConstant`, `disallowed.constant`,
`disallowed.continue`, `disallowed.declare`, `disallowed.die`, `disallowed.doWhile`,
`disallowed.echo`, `disallowed.else`, `disallowed.elseIf`, `disallowed.empty`,
`disallowed.enum`, `disallowed.eval`, `disallowed.exit`, `disallowed.for`,
`disallowed.foreach`, `disallowed.function`, `disallowed.global`, `disallowed.goto`,
`disallowed.if`, `disallowed.include`, `disallowed.includeOnce`, `disallowed.isset`,
`disallowed.match`, `disallowed.method`, `disallowed.namespace`, `disallowed.new`,
`disallowed.print`, `disallowed.property`, `disallowed.require`, `disallowed.requireOnce`,
`disallowed.return`, `disallowed.switch`, `disallowed.trait`, `disallowed.unset`,
`disallowed.variable`, `disallowed.while`.

A config entry can also override with a custom `errorIdentifier` key.

### 5. extension-installer

Yes — confirmed by both `composer.json` (`"type": "phpstan-extension"`) and the README
instruction quoted above. Manual install alternative is `includes: [vendor/.../extension.neon]`
(which registers the rule classes/services but NOT the deny-lists — those are separate opt-in
includes per bundle file, see §3).

### 6. Comparison with our `ForbidDangerousFunctionsRule`

Our rule (`/workspace/src/PHPStan/Rules/ForbidDangerousFunctionsRule.php`, identifier
`phpqaci.dangerousFunctions`) bans exactly:

`exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`, `eval`, `unserialize`,
`extract`, `parse_str` (only when called with a single argument — the two-arg form is exempted).

Per-function coverage by spaze's shipped lists:

| Our banned function | Covered by spaze shipped list? |
|---|---|
| `exec` | Yes — `disallowed-execution-calls.neon` |
| `shell_exec` | Yes — `disallowed-execution-calls.neon` (also bans backtick operator, which ours does not) |
| `system` | Yes — `disallowed-execution-calls.neon` |
| `passthru` | Yes — `disallowed-execution-calls.neon` |
| `proc_open` | Yes — `disallowed-execution-calls.neon` |
| `popen` | Yes — `disallowed-execution-calls.neon` |
| `eval` | Yes — `disallowed-dangerous-calls.neon` |
| `extract` | Yes — `disallowed-dangerous-calls.neon` |
| `unserialize` | **Not covered by any shipped list.** |
| `parse_str` | **Not covered by any shipped list.** |

**What spaze catches that ours does not** (well beyond function calls): method/static calls,
constants, namespaces/classes, superglobals, attributes, enums, control structures, keywords,
properties, plus the backtick shell operator and a large extra function surface in the shipped
insecure/loose/non-timing-safe lists (weak hashing, weak RNG, unparametrized SQL functions,
`in_array` type-juggling, missing `ENT_QUOTES`, non-timing-safe encoding). None of that is
duplicative with our narrower rule.

**Can spaze express `unserialize` and `parse_str` ourselves, just not shipped?** Yes — both are
plain function calls, trivially expressible as custom `disallowedFunctionCalls` entries (spaze's
engine has no per-function restriction that would block this). But that requires **us to author
and maintain those two entries ourselves** in a project-level neon file; they do not come "for
free" from any of spaze's five bundled files.

**Could we retire `ForbidDangerousFunctionsRule` entirely without losing coverage?** Only if we
also ship our own `disallowedFunctionCalls` entries for `unserialize` and (single-argument)
`parse_str`, since neither is in any spaze bundle. Two behavioural gaps to note if we did:

- **`parse_str`'s conditional logic cannot be reproduced natively.** Our rule exempts
  `parse_str($s, $out)` (2-arg form) and only flags the 1-arg form. Spaze's parameter-condition
  directives (`allowExceptCaseInsensitiveParams`, `allowParamsAnywhere`, etc., seen in the
  insecure-calls file) operate on parameter **values**, not on argument **count/presence**. I did
  not find a documented "allow when N args are given" directive in `docs/allow-with-parameters.md`
  content fetched here — this specific arg-count-based exemption is **unverified as expressible**
  in spaze's config language and may require flagging `parse_str` unconditionally (a stricter,
  behaviourally different rule) or a custom PHP rule class, which spaze also supports registering
  ourselves.
- **Message/doc-link content**: our rule's message points at
  `docs/phpstan-rules/forbid-dangerous-functions.md` (our OWASP-A03-framed remediation guide with
  Symfony Process / JSON examples). Spaze entries carry their own `message`/`errorTip` strings we
  would need to re-author per function to preserve that guidance.
- **Location/reporting**: spaze's `disallowedFunctionCalls` reports at the call site via the same
  PHPStan rule mechanism (`FuncCall` node) — same granularity as ours, no behavioural difference
  there.
- **Escape hatches that could silently weaken enforcement**: spaze supports `allowIn` (path
  glob), `allowExceptIn`/`disallowIn`, `allowInInstanceOf`, attribute-based allow/exclude, and
  parameter-value-based allow directives — all **opt-in, per config-entry, and visible in the
  neon file** (not a global "ignore" switch). A consumer *can* add an `allowIn` for e.g.
  `tests/*` to `eval()`, which our rule has no equivalent for today (ours is unconditional
  except the parse_str 2-arg exemption) — so adopting spaze would introduce a *new*,
  more permissive surface unless we deliberately don't expose it to consumers. This is a
  meaningful design difference: our rule is currently "no escape hatch, put exceptions in
  `phpstan.neon` `ignoreErrors` with justification" (per our own doc); spaze bakes multiple
  escape hatches into the same config layer, which could be used to silently narrow enforcement
  in ways less visible than an `ignoreErrors` entry with the mandatory justification comment our
  `phpstanIgnoreJustification` lane enforces project-wide.

**Bottom line for the decision:** adopting spaze would **not** let us retire
`ForbidDangerousFunctionsRule` outright without also authoring two custom `disallowedFunctionCalls`
entries (`unserialize`, `parse_str`) ourselves, and the `parse_str` 2-arg exemption's
expressibility is unverified. It would, however, let us drop 8 of our 10 banned functions in
favour of two well-maintained upstream bundles (`disallowed-execution-calls.neon` +
`disallowed-dangerous-calls.neon`), and gain a large amount of additional coverage (weak
crypto/RNG, SQLi-prone calls, superglobals, attributes, control-structure bans) we don't
currently have at all.

---

## Package 2: shipmonk/dead-code-detector

### 1. Version / require / maintenance

- Latest stable (packagist p2): **1.4.0**, published 2026-08-28T10:48:53Z (`time`),
  2026-08-28T12:23:54Z (`published-time`).
- `require`: `"php": "^8.1"`, `"phpstan/phpstan": "^2.1.41"`.
- No upper PHP bound → nominally PHP-8.5-compatible by constraint. README's own "Supported PHP
  versions" section states explicitly: **"`1.x` — PHP 8.1+"** (and "`0.x` — PHP 7.4 - 8.5" for
  the older major). So the current 1.x line is stated by the maintainers to support 8.1 and up,
  which includes 8.5 — this is a direct maintainer claim, not just an open composer constraint.
- Release cadence: 1.2.1 (Jun 12), 1.3.0 (Jun 30), 1.3.1 (Jul 8), 1.3.2 (Jul 15), 1.3.3 (Aug 6),
  1.4.0 (Aug 28) — all 2026, frequent releases — actively maintained.
- `type: phpstan-extension` in composer.json → works with `phpstan/extension-installer`; README
  explicitly says "Use official extension-installer or just load the rules" (manual:
  `includes: [vendor/shipmonk/dead-code-detector/rules.neon]`).

### 2. What it detects / error identifiers

Detects unused (dead) class members. From `src/Rule/DeadCodeRule.php` constants (exact
`identifier()` strings PHPStan reports):

- `shipmonk.deadMethod`
- `shipmonk.deadConstant`
- `shipmonk.deadEnumCase`
- `shipmonk.deadProperty.neverRead`
- `shipmonk.deadProperty.neverWritten`

Also detects **dead cycles** and **transitively dead** methods (methods only reachable from
other dead methods) — reported as the same identifiers with the transitive members surfaced as
tips rather than separate top-level errors.

### 3. Configuration keys

Root neon key: `parameters.shipmonkDeadCode`. Sub-keys found in README:

- `detect.deadMethods` / `detect.deadConstants` / `detect.deadEnumCases` /
  `detect.deadProperties.neverRead` / `detect.deadProperties.neverWritten` — booleans, all true
  by default, individually toggleable.
- `usageExcluders.tests.enabled` (+ optional `usageExcluders.tests.devPaths`) — excludes usages
  found only in test paths from counting as "used" (autodetects `autoload-dev` paths from
  `composer.json` if `devPaths` omitted). README: **"We recommend enabling this excluder for all
  projects."**
- `usageExcluders.usageOverMixed.enabled` — disables the "calls over unknown/mixed types mark
  everything as used" leniency (stricter, more false-positive-prone mode).
- `usageProviders.<name>.enabled` — force enable/disable a framework-specific provider (e.g.
  `usageProviders.phpunit.enabled: true`). Frameworks auto-enable when detected in composer
  deps.
- `usageProviders.symfony.containerXmlPaths` — Symfony DIC container XML(s), needed for
  constructor/service-call detection unless `phpstan/phpstan-symfony`'s `containerXmlPath` is
  already configured.
- `usageProviders.composer.composerJsonPath` — override autodetected `composer.json` location
  for the Composer script-callback provider.
- `usageProviders.nette.containerNeonPaths` — Nette DI container neon paths (requires
  `nette/neon`).
- `debug.usagesOf` — list of `Class::member` refs to trace in `-vvv` debug output (doesn't
  invalidate result cache).
- `filterOutUnmatchedInlineIgnoresDuringPartialAnalysis.wrappedErrorFormatter` — cosmetic, for
  the partial-analysis inline-ignore false-positive workaround.

**Framework awareness (Symfony/Doctrine/PHPUnit) confirmed, exact hooks:**

- **Symfony**: DIC-driven constructor/call/factory usage (via `phpstan/phpstan-symfony`'s
  `containerXmlPath` or our own `containerXmlPaths`); attributes `#[AsEventListener]`,
  `#[AsMessageHandler]`, `#[AsController]`, `#[AsCommand]`, `#[Assert\Callback]`, `#[Interact]`,
  `#[Route]`, `#[Required]`, `#[AsSchedule]`, `#[AsCronTask]`, `#[AsPeriodicTask]`,
  `#[AutoconfigureTag('doctrine.event_listener')]`, `#[Autoconfigure(constructor:/calls:)]`,
  `#[AutowireCallable]`, `#[AutowireLocator]`/`#[AutowireIterator]`/`#[TaggedIterator]`/
  `#[TaggedLocator]` index/priority methods, workflow listener attributes,
  `EventSubscriberInterface::getSubscribedEvents`, `onKernel*` methods, `!php/const`/`!php/enum`
  YAML refs, Symfony UX component/live-component hooks.
- **Doctrine**: `#[AsEntityListener]`, `#[AsDoctrineListener]`, `Doctrine\ORM\Events::*`,
  `Doctrine\Common\EventSubscriber` methods, `repositoryMethod` in `#[UniqueEntity]`, lifecycle
  attributes (`#[PreFlush]`, `#[PostLoad]`, ...), enum column types.
- **PHPUnit**: data-provider methods, `testXxx` methods, `@test`/`@before`/`@afterClass`
  annotations and `#[Test]`/`#[Before]`/`#[AfterClass]` attributes.

### 4. False-positive surface — the library question

README's "Limitations" and "Other problematic cases" sections list, verbatim-summarized:
anonymous-class methods never reported dead (PHPStan limitation); abstract trait methods never
reported dead; most magic methods never reported dead (only `__construct`/`__clone` are
supported); constructor false positives possible for non-Symfony apps whose services are
"created magically" (with a documented single-ignore workaround, truncated by my fetch window —
not fully captured here, flagged **unverified in full detail**, though its existence and general
shape are confirmed).

**Reflection / container autowiring**: the "Generic usage providers → Reflection" section states
any property/enum/constant/method accessed via `ReflectionClass` (e.g. `getConstructor()`,
`getConstant()`, `getMethods()`, `getCases()`) is automatically marked used. Container-autowired
constructors are covered under the Symfony DIC provider (needs `containerXmlPaths` configured) —
for non-Symfony/non-DI-XML setups this is **not automatically covered** and would need a custom
`MemberUsageProvider` (documented, supported extension point) or the constructor-analysis
ignore mentioned above.

**Doctrine lifecycle callbacks**: explicitly covered (see §3 above) via attributes and
`Doctrine\Common\EventSubscriber`.

**Symfony event subscribers**: explicitly covered via `EventSubscriberInterface::getSubscribedEvents`
and the various `#[As...]` attributes.

**PHPUnit data providers**: explicitly covered ("data provider methods" listed first under
PHPUnit support).

**Public API of a library (the crux for php-qa-ci)**: the README has a dedicated **"Usage in
libraries"** section stating verbatim: *"Libraries typically contain public api, that is unused
— If you mark such methods with `@api` phpdoc, those will be considered entrypoints. You can
also mark whole class or interface with `@api` to mark all its methods as entrypoints."*

This is **the knob**: annotate `php-qa-ci`'s public surface (lane classes implementing
`ToolInterface`, `ToolContext`, config builder `with*()` methods, etc. — anything a consumer's
`qaConfig/tools/*.php` or `qaConfig/qa.php` calls but nothing inside `src/` itself calls) with
`@api` at the class/interface or method level. Without doing this, a library's public API called
only by consumers (never by its own `src/`) will be flagged wholesale as dead, exactly as the
concern anticipated. There is no separate "this is a library, not an app" global toggle — the
`@api` phpdoc marker is the only documented mechanism found for this. I did not find a
package-wide `treatAsLibrary` or similar flag in the fetched README/rules.neon.

Additionally relevant: `usageExcluders.tests.enabled` (recommended for all projects) prevents
methods that are *only* exercised by the library's own test suite (a common shape for a library
whose real callers are external consumers) from being counted as "used" just because tests call
them — meaning **without** the `@api` markers, test-only-covered public API would still show as
dead even though it's intentionally public.

### 5. Auto-remove/fix mode

Yes — `vendor/bin/phpstan analyse --error-format removeDeadCode` deletes reported dead methods/
constants/enum cases from source (shown with a diff example in the README). Caveats stated by
the README itself:

- If test-usage exclusion is enabled, removal does **not** cascade to delete the now-orphaned
  test code that called the removed member — it's left behind and reported separately
  ("Excluded usage at ... left intact"), so a human still needs to clean up tests.

**Is it safe?** The tool's own docs flag real false-positive classes (constructors created
"magically", calls/constants over unknown/mixed types, reflection-based access, anonymous
classes, most magic methods) where automatic removal would be actively wrong if those excluders/
providers aren't correctly configured first. Given php-qa-ci is a library (the exact case the
README calls out as needing manual `@api` annotation), running `--error-format removeDeadCode`
**before** that annotation work is done would be unsafe — it would delete public API. This is a
review/manual-approval mode, not something to wire into the pipeline unattended.

---

## Files fetched (for provenance)

- `/workspace/untracked/scratch/spaze/README.md`, `custom-rules.md`, `allow-in-paths.md`,
  `extension.neon`, `composer.json`, and all 5 `disallowed-*.neon` bundles.
- `/workspace/untracked/scratch/shipmonk/README.md`, `rules.neon`, `composer.json`.
- `/workspace/untracked/scratch/spaze.json`, `shipmonk.json` — raw packagist p2 metadata.
- GitHub API repo-content listings for both repos (root trees) via `gh api`.
- `src/RuleErrors/ErrorIdentifiers.php` (spaze) and `src/Rule/DeadCodeRule.php` (shipmonk) fetched
  raw for exact identifier constants.
