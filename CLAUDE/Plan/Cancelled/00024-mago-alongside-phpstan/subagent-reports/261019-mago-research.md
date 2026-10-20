STOPPING BECAUSE: the research is finished. The harness refused to let me write `/workspace/untracked/scratch/mago-research/REPORT.md` because sub-agents may not write report files, so the full report is below. The daemon also saves this reply under `/workspace/untracked/agent-reports/auto/`. No tracked file was changed: `git status` was clean before and after every run.

## Headline

01. **Speed:** your assumption holds. On this repo's 629 files Mago lints in 0.07–0.10s, analyses in 0.3–1.0s and checks formatting in 0.09s. PHPStan takes 14.6s cold and 1.3s warm; PHP-CS-Fixer takes 22.9s cold and 4.6s warm. One file: Mago lint ~15ms, Mago analyse ~0.3–0.8s, PHPStan ~1.2–1.9s warm. Mago keeps no cache.
02. **Capability:** your assumption is only partly right. On a probe of PHPStan annotations (aliases, shapes, generics, conditional returns, `@phpstan-assert`, `int-mask-of`, `key-of`, `literal-string`) Mago caught all 8 planted errors PHPStan catches, with no false positives on the 7 correct uses. On real code that PHPStan passes cleanly it reports more, not less: 485 analyser findings with defaults (148 errors) and 1,598 lint findings.
03. **Linter:** config alone brings it into agreement with the current toolchain, leaving 8 findings. The main fix is `assertion-style = self`, because Rector rewrites assertions to `self::` and Mago's default demands `static::` (372 warnings). The rest is switching off metric and name-heuristic rules.
04. **Analyser:** 211 disagreements remain (148 errors) even with an aligned config. Most can be fixed in code in a way both tools accept. One is a true A-vs-B: PHPStan requires an `is_string($_SERVER['PWD'])` guard, and Mago calls that guard redundant.
05. **Formatter:** it cannot coexist with PHP-CS-Fixer. With defaults it rewrites 554 of 629 files. Tuned as close to CS-Fixer as its options allow, it still rewrites 353, because it reflows whole statements rather than fixing individual rules.
06. **Suppression gap:** Mago ignores `@phpstan-ignore` and has its own inline `@mago-ignore` / `@mago-expect`. Neither `phpqaci.inlinePhpstanIgnore` nor the daemon's suppression guard catches those today.
07. **Extensions:** custom rules are possible since 1.47 (August 2026), but only as PHP worker processes through the SDK in the Composer package. The formatter and guard cannot be extended. The Composer package downloads the binary on first run without checking a checksum.
08. **Distribution:** a 31MB static musl binary, with a sha256 digest and a GitHub attestation on each release. It could be shipped committed under `vendor-bin/` the way ShellCheck is. It cannot read classes inside a PHAR, so it saw `PHPStan\*` only after I extracted `phpstan.phar`.
09. **Hooks daemon:** `lint_on_edit` has exactly two command slots per language. php-qa-ci consumers already fill both (`php -l` and `qa -t phpstan -p {file}`), so adding Mago needs a wrapper, a project handler or an upstream daemon change. In this repo phpstan is not found at all, so only `php -l` runs on edit.
10. **Maturity:** licensed MIT or Apache-2.0, one maintainer in practice, a release every 2–4 days, and a breaking 2.0 rework (#1931) open.

Tags: [measured] = run here; [docs] = Mago 1.57.0 docs in its source tarball; [web] = GitHub/web via a sub-agent; [unverified] = not confirmed. Scratch artefacts are under `/workspace/untracked/scratch/mago-research/`: `bin/`, `dl/` (source and docs), `cfg/`, `out/` (JSON reports, diffs, `rules-table.txt`), `probe/`, `phpstan-run/`, `phpstan-src/`. Sub-agent reports are in `/workspace/untracked/agent-reports/auto/261019-161525-general-purpose-*.md` (prior art) and `261019-161109-Explore-*.md` (hooks daemon). One sub-agent also left scratch files in `/workspace/untracked/scratch/mago/`.

## 1. Release, maturity, distribution

- **Version:** 1.57.0, released 2026-10-14. 0.0.1 was 2024-12-08 and 1.0.0 was 2025-12-20. There are 217 tags, and 30 releases between 2026-06-24 and 2026-10-14. [measured]
- **Licence:** the tarball and `composer.json` say MIT OR Apache-2.0. [measured]
- **Bus factor:** azjezz has about 2,988 commits; the next contributor has 104. Sponsored by CHECK24 and GitHub Sponsors. About 36 analyser bugs were filed in 30 days and nearly all are closed. [web]
- **Binaries:** a static musl binary for x86_64 (11.8MB tarball, 31.3MB binary), plus wasm, Docker, Homebrew and `self-update`. [measured]
  - Release assets carry a sha256 digest; the musl tarball's digest matched `sha256sum` and has one GitHub attestation. There are no separate checksum or signature files.
- **Composer package:** it is not a PHAR. Its bin downloads the binary from GitHub on first run (`composer/src/internal.php:352-579`) and contains no hash check. [measured]
- **PHP 8.5:** with `php-version = 8.5`, all 629 repo files parse with zero semantic errors. A probe using clone-with, `|>`, `(void)`, `#[\NoDiscard]` and `array_first` is clean in both Mago and PHPStan. [measured]
- **Cache:** none. The persistent cache request (#2512) was closed as not planned. [web]

## 2. Components

Subcommands: init, config, extension, list-files, cst, lint, fix, analyze, guard, format, inspect-baseline, self-update.

- **Formatter:** a Prettier-style reprinter, `print-width` 120, PER-CS by default. About 90 options; presets `psr-12`, `laravel`, `drupal`. Inline opt-out with `@mago-format-ignore-*`.
- **Linter:** 190 rules, 113 on by default, 21 tied to an integration (symfony, laravel, phpunit, pest, psl, wordpress, doctrine). Most rules have fixes (safe / potentially-unsafe / unsafe). [measured]
- **Analyser:** 331 issue codes and about 25 opt-in strictness flags, e.g. `check-throws`, `enforce-class-finality`, `require-api-or-internal`, `strict-array-index-existence`, `check-missing-type-hints`. [measured, docs]
- **Guard:** architecture rules; overlaps PHPArkitect. Not evaluated in depth.
- **Fix:** repeats guard, analyser, linter and formatter fixes until nothing changes.

Shared options \[docs\]:

- **Per-rule config:** `{enabled, level, exclude, rule options}`.
- **Analyser ignores:** `ignore = [code | {code, in} | {pattern, code?, in?}]`.
- **Levels:** error, warning, help, note. `minimum-fail-level` defaults to `error`, so warnings do not fail a run.
- **Baselines:** separate files for lint and analyse, loose or strict, with `--verify-baseline` and `--fail-on-out-of-sync-baseline`.
- **Inline pragmas:** `@mago-expect` and `@mago-ignore`.
- **Output formats:** json, sarif, checkstyle, github and gitlab. `CLAUDECODE` selects the `medium` format automatically.
- **Exit codes:** 0 clean, 1 findings. A separate crash exit code is [unverified].
- **Config files:** `extends` layering and version pinning.
- **Vendor typing:** `patches` stubs refine vendor types.

## 3. Rule overlap and conflicts

The full rule table is `out/rules-table.txt`; effective rule options are in `out/linter-effective.json`.

### 3a. php-qa-ci's 36 `phpqaci.*` rules against Mago's native rules

| `phpqaci` rule                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              | Mago equivalent                                                                     | Notes                                                                       |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| emptyLanguageConstruct                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | `no-empty` (lint, error)                                                            | same intent                                                                 |
| looseComparison                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             | `identity-comparison` (warning)                                                     | same intent                                                                 |
| nestedTernary                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               | `no-nested-ternary`                                                                 | same                                                                        |
| emptyCatchBlock                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             | `no-empty-catch-clause`                                                             | same                                                                        |
| missingStrictTypes                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | `strict-types`                                                                      | same                                                                        |
| debugOutputFunction                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         | `no-debug-symbols`                                                                  | note level only                                                             |
| dangerousFunctions / insecureFunction                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       | `no-eval`, `no-shell-execute-string`, `no-ffi`, configurable `disallowed-functions` | a function list in config could reproduce them                              |
| newDateTime                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | `disallowed-type-instantiation`                                                     | needs config; exact match [unverified]                                      |
| requireSensitiveParameterAttribute                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | `sensitive-parameter`                                                               | different name heuristic; flagged 10 test parameters PHPStan's rule accepts |
| requireApiOrInternalTag                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     | analyser `require-api-or-internal`                                                  | Mago covers abstract classes, interfaces and traits only                    |
| deprecatedPhpunitMethod                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     | analyser deprecation codes                                                          | generic deprecation only                                                    |
| The other 25 rules (silentCatch, nullCoalescing…, readonlyService, enumOverLiteralUnion, repeatedStringLiteral, consistentMemberDocs, variadicOverArrayParameter, ambiguousArrayDoc, processStream, unvalidatedRegexPattern, factorySealed, processStopOnThrow, mockFinalClass, httpPrefixedEnvVars, devNamespaceInProductionSource, apiMustNotExposeInternal, ruleIdentifierMustBeConstant, unanchoredVendorSubstringCheck, inlinePhpstanIgnore, binDirTool, composerPluginNamespacedFunction, forbiddenAttribute, deprecatedSerializable) | none                                                                                | would need a Mago extension                                                 |

About 8 of the 36 have a native counterpart, all of them syntactic. type-coverage has only the boolean `check-missing-type-hints`, not a percentage floor.

### 3b. A-vs-B evidence

"PHPStan-clean" means the current tree, which passes level max with strict-rules, all phpqaci rules and zero `ignoreErrors`. Probes are in `probe/cases/probes.php`.

| #   | Construct                                                                        | Our toolchain                                                                                                                                  | Mago                                                                                                                                                                                                                                  | Evidence                                                            | Can config resolve it?                                                                                      |
| --- | -------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| C1  | `isset($_SERVER['PWD']) && \is_string(...)` guard                                | PHPStan requires it (without it: `return.type`, returns mixed)                                                                                 | `redundant-type-comparison` + `redundant-logical-operation`                                                                                                                                                                           | probe P1; `src/Helper.php:44`                                       | Yes (help level, or ignore). **A true contradiction.**                                                      |
| C2  | Assertion call style                                                             | Rector `PreferPHPUnitSelfCallRector` → `self::`                                                                                                | `assertion-style` default `static`: 372 warnings                                                                                                                                                                                      | lint run                                                            | Yes: `style = "self"`                                                                                       |
| C3  | Whole-file formatting                                                            | CS-Fixer: aligned `=`/`=>`, `fn (`, `(int)$x`, no line wrapping                                                                                | Rewrites 554 files (+13,766/−6,384 lines). Tuned (`print-width` 400, `fn (`, no cast space, align, all `preserve-breaking-*`) it still rewrites 353 files: reflows `.` chains, drops grouping parentheses, inlines empty constructors | `out/fmt-default.diff`, `out/fmt-tuned.diff`                        | **No**                                                                                                      |
| C4  | `$x = require $f;` then narrowing                                                | PHPStan fine                                                                                                                                   | `mixed-assignment` (274)                                                                                                                                                                                                              | probe P5                                                            | Yes: ignore the code                                                                                        |
| C5  | `\Safe\preg_match(..., $matches)` with `$matches` not set beforehand             | fine                                                                                                                                           | `mixed-argument` error (41)                                                                                                                                                                                                           | probe P2b                                                           | Code fix (`$matches = null;`) satisfies both; looks like a Mago false positive on Safe's stubs [unverified] |
| C6  | Nullable property narrowed, then read after `$this->` calls in a loop            | PHPStan keeps the narrowing                                                                                                                    | 3 errors (`nullable-return-statement` and others)                                                                                                                                                                                     | probe P4; `RunLockSignalTest.php:156`                               | Code fix (local variable) satisfies both                                                                    |
| C7  | `str_repeat(' ', $int)`                                                          | fine                                                                                                                                           | wants `non-negative-int`                                                                                                                                                                                                              | probe P7; `FullQaBlockerYamlEditor.php:70`                          | Code fix (`int<0,max>`) satisfies both                                                                      |
| C8  | Boolean flag updated later in the same loop                                      | fine                                                                                                                                           | `paradoxical-condition` error                                                                                                                                                                                                         | `RequireProcessStopOnThrowRule.php:263`                             | Mago false positive                                                                                         |
| C9  | `'uniterate' === $request->selectedToken`; `const HTTP_API_KEY = 'HTTP_API_KEY'` | —                                                                                                                                              | `no-insecure-comparison` / `no-literal-password` errors                                                                                                                                                                               | `QaApplication.php:214`; `ForbidHttpPrefixedEnvVarsRuleTest.php:36` | Mago false positives; disable the rules                                                                     |
| C10 | Metric rules                                                                     | none in php-qa-ci                                                                                                                              | `too-many-methods` 122, `cyclomatic-complexity` 80, `kan-defect` 46, `excessive-parameter-list` 35, all **error** level                                                                                                               | lint run                                                            | Yes: disable or tune                                                                                        |
| C11 | `isset()` / literal positional arguments                                         | idiomatic here                                                                                                                                 | `no-isset` 77 / `literal-named-argument` 630                                                                                                                                                                                          | lint run                                                            | Yes                                                                                                         |
| C12 | `@` error-control operator                                                       | allowed                                                                                                                                        | error (2)                                                                                                                                                                                                                             | `LinksChecker.php:295`                                              | Policy choice                                                                                               |
| C13 | Adding an `is_string` guard on a Safe match group                                | PHPStan `booleanAnd.rightAlwaysTrue` error                                                                                                     | redundant (help)                                                                                                                                                                                                                      | probe P2c                                                           | Shows that "add a guard to please the other tool" can trip PHPStan                                          |
| C14 | Inline pragmas                                                                   | `ForbidInlinePhpstanIgnoreRule.php:31` matches only `@phpstan-ignore`; the daemon's `qa_suppression/php_strategy.py:12-28` has no mago pattern | `@mago-*` pragmas                                                                                                                                                                                                                     | —                                                                   | **A gap, not a conflict**                                                                                   |
| C15 | Rules off by default that would conflict if switched on                          | —                                                                                                                                              | `missing-docs` (vs `no_superfluous_phpdoc_tags`), `yoda-conditions` in `forbid` mode, analyser `check-missing-override`                                                                                                               | rule table                                                          | Leave them off                                                                                              |

With the aligned config (`cfg/mago-aligned.toml`):

- **Linter: 8 findings left** — 5 `single-class-per-file`, 2 `@` operator, 1 `no-duplicate-match-arm`.
- **Analyser: 211 left (148 errors).** The largest groups are `mixed-argument` 73 (41 of them C5), `less-specific-*` about 34, and possibly-undefined-variable 14.
- I did not classify all 211, so a hidden contradiction among them is [unverified].

### 3c. Against PHP-CS-Fixer and Rector

- Mago's linter agrees with them in many places: `prefer-static-closure`, `inline-variable-return`, `no-redundant-final`, `no-protected-in-final`, `str-contains`, `explicit-nullable-param`, `braced-string-interpolation`, `array-style`.
- The linter makes no layout demands. Only the formatter fights CS-Fixer (C3).
- The only Rector conflict found is assertion style (C2), and config fixes it. I did not go through the Rector sets rule by rule [unverified].

## 4. Extensibility [docs]

- **Model:** since 1.47.0 (PR #2192), extensions are pools of external worker processes speaking a versioned binary protocol. Any language could implement it, but the only first-party SDK is PHP (`Mago\Sdk`, inside the Composer package, using Revolt and Fibers). Workers are not sandboxed.
- **What extensions can add:**
  - linter rules matched on syntax-tree node kinds;
  - analyser plugins: type, return and assertion providers, entry points, targeted hooks that can report issues, and after-file and after-analysis hooks.
- **Formatter and guard:** no extension API.
- **Stability:** only `@api` symbols under `Mago\Sdk` are supported. The SDK and binary are released as a matched pair, and the surface is still growing (#2517, #2527 added in October 2026).
- **For DBF rules in Mago:**
  - Each one would be PHP running in a worker, adding PHP start-up and IPC to every run; the cost is [unverified].
  - It needs the Composer package that downloads the binary.
  - `bin/rules`, `rule-doc` and the `phpqaci.*` identifiers are PHPStan-only.
  - `sandermuller/phpstan-to-mago` exists [web, unverified].

## 5. Measurements

Scope: `src/` and `tests/`, 629 files. `tests/assets` is included for symbol lookup only and `tests/Small/ComposerPlugin` is excluded, mirroring the PHPStan config. Without `phpstan.phar` extracted, the analyser reported 2,016 issues, 477 of them unknown classes, because Mago cannot read PHARs.

| Task                  | Mago                                                     | PHPStan 2.3.1 (repo config, 4 workers) | PHP-CS-Fixer 3.95     |
| --------------------- | -------------------------------------------------------- | -------------------------------------- | --------------------- |
| Project lint          | 0.065–0.10s                                              | —                                      | —                     |
| Project analyse       | 0.28–0.33s (vendor only); 0.40–1.0s with PHPStan sources | 14.6s cold, 1.33s warm                 | —                     |
| Project format check  | 0.09s                                                    | —                                      | 22.9s cold, 4.6s warm |
| One-file lint         | 0.012–0.028s                                             | —                                      | —                     |
| One-file analyse      | 0.28–0.85s (about the same as the whole project)         | 1.2–1.9s warm                          | —                     |
| One-file format check | 0.014s                                                   | —                                      | 4.0s                  |

- PHPStan was timed as `phpstan.phar` directly with the lane's wrapper neon and a scratch `tmpDir`, not through `bin/qa`; I avoided `bin/qa` so as not to take the run lock.
- A warm PHPStan run after a real edit was not measured.
- PHP 8.5 probe: clean in both tools.
- Known gaps in Mago's annotation support \[web\]: #2260 (`non-decimal-int-string`), #1139 (recursive types), #2395 (`@this-out`), #2148 (`type-string`), #2280 (`@psalm-assert-if-*`), #2441 (`preg_match_all` named groups).
  - Mago has no equivalent of phpstan-phpunit narrowing (#2383: "no first-party extensions any time soon") or of PHPStan's regex-shape inference.

## 6. Hooks daemon `lint_on_edit`

Paths are under `.claude/hooks-daemon/src/claude_code_hooks_daemon/`.

- **When it runs:** after Write, Edit and Bash-authored files, on the edited file only.
- **PHP commands** (`strategies/lint/php_strategy.py:10-21`): `php -l {file}`, then `phpstan analyse {file}` if a phpstan binary is found. The second is skipped when the first fails.
- **Options:** `command_overrides.PHP.{default,extended}`, `timeouts.PHP`, `exclude_paths`, `lint_bash_writes`.
  - The command is split on whitespace with no shell, so `&&` and pipes do not work.
  - Binaries are looked up in the project's bin dirs, then the daemon's venv, then PATH.
  - A project handler under `.claude/project-handlers/` is the alternative (guide: `CLAUDE/PROJECT_HANDLERS.md`).
- **Time limits:** 15s per command (`constants/timeout.py:128`), 20s for the handler chain, 30s client cut-off. A timeout or a missing tool lets the edit through with a note.
- **On failure:** a DENY with "PHP lint FAILED for …", the output and the command; the agent fixes with Edit.
- **This repo:** no options are set, and bin-dir is `bin/`, so phpstan is not found and only `php -l` runs.
- **Consumers:** `scripts/lib/daemon-lint-override-check.inc.bash` already tells them to set `extended: "env PHPQACI_AGENT_MODE=1 qa -t phpstan -p {file}"`.
- **Routes for Mago:**
  1. Replace `php -l` with `mago lint {file}`, which also reports parse errors.
  2. A wrapper bin that runs Mago then qa-phpstan.
  3. A project handler.
  4. An upstream daemon change.
- With Mago's default fail level, warnings would not block an edit.

## 7. Prior art [web]

- **Adoption:** 78 repositories have a `mago.toml`. sentry-php runs `mago analyze` alongside PHPStan and CS-Fixer; infection, tempest, firefly-iii and opentelemetry also run Mago with PHPStan. The common split is Mago for format and lint, PHPStan for types. flow-php, shlink and PSL use Mago without PHPStan.
- **supun.io** (Mago 1.0.2): over 1,000 Mago findings where PHPStan at max had none, about 500 of them from `check-throws`.
- **dev.to** (Mago 1.54, WordPress): PHPStan 31.2s cold, 13.3s with Turbo, 1.8s warm per commit, against 1.1s for Mago.
- **Benchmarks:** Mago's are its own. Issue #1111 asked for fair benchmarks, and PHPStan's author asked for like-for-like configuration. The formatter is compared only against pretty-php.
- **Formatter bug:** #2355 is open — output with `align-assignment-like` changes between runs.

## 8. Tradeoffs

**A. Linter on edit only**

- For: ~15ms per file; native rules for about 8 phpqaci concerns; 8 leftover findings with the aligned config.
- Against: needs a third command slot (wrapper, handler or upstream change); catches only the syntactic slice; adds a second rule set that must be kept in agreement.
- Agreement risk: low.

**A2. Analyser on edit**

- For: 0.3–0.9s per edit.
- Against: 211 disagreements, including C1, would block edits PHPStan accepts unless the codes are curated or baselined; it has to be shown PHAR-delivered APIs.

**B. Mago lane before PHPStan**

- For: whole project in about 0.1–1s; could fail fast on a chosen set of serious codes.
- Against:
  - Every Mago code that is on becomes a second gate that must agree with PHPStan.
  - Analyser behaviour changes almost every release, and the daily update policy would pull that in.
  - A new lane extends the public API (CLAUDE/tool-boundaries.md).
  - A 31MB binary committed on every update, with a release every 2–4 days.

**C. Mago formatter instead of CS-Fixer**

- For: 0.09s against 4.6–22.9s.
- Against:
  - Rewrites 353–554 files; consumers inherit the change.
  - Drops CS-Fixer's risky and semantic fixers: yoda, `native_function_invocation`, `strict_param`, `ordered_class_elements`, `final_class`.
  - The #2355 formatter bug.
  - Running both formatters is not possible.

**D. DBF rules in Mago**

- For: edit-speed linter rules; about 8 rules map to native Mago rules through config.
- Against:
  - Rules run as PHP workers, at an unmeasured cost.
  - The extension API is about two months old.
  - The binary arrives by unverified download.
  - 28 rules need PHPStan's scope and reflection.
  - The DBF tooling is PHPStan-only, and one defence would live in two engines.

What the facts imply:

1. The two formatters cannot share the same files.
2. The linter can be brought into agreement by config alone.
3. The analyser cannot, without curating its codes or a baseline.
4. Mago's inline pragmas are currently an unguarded way to suppress findings.

## 9. Open questions for the Owner

1. Lint-on-edit only (A), or also a pipeline lane (B)? B triggers the new-tool / public-API decision.
2. For lint-on-edit, which route: an upstream daemon change, a php-qa-ci wrapper bin, or a project handler?
3. Are the metric rules (`too-many-methods`, `cyclomatic-complexity` and so on) wanted at all, and at what thresholds? That is a new policy, not an agreement question.
4. Is the analyser in scope? If so, which codes, and how are its disagreements resolved: code changes both tools accept, config ignores, or a baseline? Ignores and baselines are your decision under DBF.
5. Should `@mago-*` pragmas be forbidden, by extending `phpqaci.inlinePhpstanIgnore` or adding a new rule, plus the daemon's suppression patterns?
6. Distribution: a committed static binary under `vendor-bin/` with sha256 and attestation checked at update time? Is a 31MB blob per update acceptable for repository growth, or should updates be throttled?
7. Is replacing CS-Fixer (C) on the table at all?
8. Is a bus factor of one, plus the coming breaking 2.0 rework, an acceptable risk for a tool consumers inherit?
9. Should PHPStan stay the single owner of the overlapping conventions, with Mago's `sensitive-parameter` and `require-api-or-internal` switched off?
