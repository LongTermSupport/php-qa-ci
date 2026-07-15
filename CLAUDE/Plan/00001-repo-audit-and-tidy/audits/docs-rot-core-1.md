# Docs Rot Audit — CLAUDE.md & README.md vs. Actual Code

**Date**: 2026-07-15
**Scope**: `/workspace/CLAUDE.md` and `/workspace/README.md`, verified against `bin/qa`, `includes/**`, `configDefaults/**`, `scripts/**`, `src/**`, `composer.json`, `phive.xml`, `templates/**`, `qaConfig/**`, and (spot-check corroboration only) `docs/**`.
**Method**: Every substantive factual claim in the two root docs was traced to the specific source file(s) that implement or contradict it. Where a file's *current* state alone was ambiguous (e.g. an empty script), `git log`/`git show` was used to establish when and how it diverged from the doc's description. Code is ground truth throughout; the two docs were never used to validate each other.
**Files read/inspected**: ~48 distinct files (bash includes, PHP source, neon/JSON/XML configs, composer.json, phive.xml, plus 6 git revisions of one file's history).

---

## 1. Table of Contents by Severity

### CRITICAL (6)
- DC-001 — PSR-4 Validation is a **silent no-op**; the wiring script is a 0-byte file
- DC-002 — PHPUnit Annotations Check is a **silent no-op**; the wiring script is fully commented out
- DC-003 — "Configuration Cascade" describes a `configDefaults.inc.bash` file and per-platform `configDefaults/{platform}/` dirs that **do not exist**
- DC-004 — `phpUnitCoverage` documented default (`0`) is the **opposite** of the actual default (`1`)
- DC-005 — CLAUDE.md claims **PHP 7.4+** support; `composer.json` requires `^8.3`, and README itself says "PHP 8.3+"
- DC-006 — PHIVE install step cites a **non-existent script** (`scripts/phive-install.bash`) and inverts the actual conditional logic

### MAJOR (7)
- DC-007 — README undercounts opt-in PHPStan rules (claims 6+4=10; actual is 8+4=12)
- DC-008 — README's "Always-on rules" list omits 8 of the ~14 rules actually wired in `rules-default.neon`
- DC-009 — README says "three Composer plugins"; **four** are registered (`ManagedSourceDeployPlugin` omitted)
- DC-010 — CLAUDE.md's phase numbering is broken (Phase 4 restarts at 11 instead of continuing from 12→13/14); `branchNamePolicy` runs in the Static Analysis phase but appears nowhere in either doc's phase list
- DC-011 — `packageType`, an always-on Phase-2 tool with its own doc file, is absent from both root docs' tool lists
- DC-012 — The Locking System (`lock.inc.bash`, `timing.inc.bash`) is completely undocumented
- DC-013 — The `qaReadOnly` / `QA_READONLY` / aggregate-mode subsystem is completely undocumented in both root docs

### MINOR (3)
- DC-014 — README's consolidated "Docs" list omits 2 of the 6 files actually in `docs/tools/`
- DC-015 — An orphaned, unregistered hook file exists (`php-qa-ci__check-vendor-uncommitted.py`) that CLAUDE.md's "Included Hooks" list doesn't mention
- DC-016 — PHPLoc "cannot fail the pipeline" claim is not clearly guaranteed by the code (SPECULATIVE)

### INFO (1)
- DC-017 — Install instructions are internally consistent but not verified against a live registry (SPECULATIVE)

---

## 2. Findings

### DC-001 — CRITICAL — PSR-4 Validation is a silent no-op

**Doc location**: `CLAUDE.md:84` (phase list, "PSR-4 Validation (`psr4Validate`)") and `CLAUDE.md:431-436` ("### PSR-4 Validate" tool section); also `README.md:65` ("3\. PSR-4 Validation").

**Quoted claim**: "**PSR-4 Validation** (`psr4Validate`) - Validates namespace/directory structure" / "How it works: Reads composer.json autoload definitions, checks each PHP file's namespace matches its directory location."

**Actual behaviour**: `includes/generic/psr4Validate.inc.bash` is a **0-byte file** (`ls -la` confirms `0` size). `runTool` (`includes/functions.inc.bash:20-37`) `source`s this file when `allLintingTools.inc.bash:7` calls `runToolGuarded psr4Validate` — sourcing an empty file is a silent success, so the step always "passes" having done nothing. No project override exists (`qaConfig/tools/` contains only `setConfig.inc.bash`) and no platform override exists (`includes/symfony/` has no `psr4Validate.inc.bash`).

**Root cause (git history)**: The file had real content (a retry loop calling `bin/psr4-validate`) through commit `e8fcb9b` (2021-11-11). Commit `e0240dc` (2023-12-18, commit message is about an unrelated Rector caching fix) accidentally deleted the entire body, leaving a 0-byte file. It has stayed empty through the current `php8.4` HEAD (`a7348f4`, 2025-11-06, which only changed the file's execute bit). The standalone binary (`bin/psr4-validate`) and `src/Psr4Validator.php` still work correctly if invoked directly — they are simply never invoked by the pipeline.

**Suggested correction**: Restore the wiring logic (from `e8fcb9b`'s version, adapted to current conventions) or explicitly document that PSR-4 validation is currently disabled, matching how `docs/pipeline.md:72` already flags PHPUnit Annotations as "(currently disabled)".

---

### DC-002 — CRITICAL — PHPUnit Annotations Check is a silent no-op

**Doc location**: `CLAUDE.md:88` and `CLAUDE.md:481-486` ("### PHPUnit Annotations Check").

**Quoted claim**: "**PHPUnit Annotations Check** (`phpunitAnnotations`) - Validates test annotations" / "How it works: Parses test files to ensure proper @test, @group annotations."

**Actual behaviour**: `includes/generic/phpunitAnnotations.inc.bash` is **entirely commented out** — every line begins with `#`. Sourcing it executes nothing. `docs/pipeline.md:72` is honest about this ("PHPUnit Annotations Check -- Test annotation validation (currently disabled)"), but CLAUDE.md presents it as a fully active, functioning check with no such caveat. `README.md`'s own phase list (lines 64-70) does *not* mention this tool at all — an inconsistency between the two root docs on the same tool.

**Suggested correction**: Add a "(currently disabled)" note to CLAUDE.md's phase list and tool section, matching `docs/pipeline.md`, or re-enable the check and remove the comment markers.

---

### DC-003 — CRITICAL — "Configuration Cascade" references a nonexistent file and nonexistent directories

**Doc location**: `CLAUDE.md:124-129` ("### Configuration Cascade").

**Quoted claim**: "1. **Built-in defaults** in `configDefaults.inc.bash`" / "2. **Platform-specific defaults** in `configDefaults/{platform}/`".

**Actual behaviour**: `find /workspace -name configDefaults.inc.bash` returns **no results** — this file does not exist anywhere in the repository. `configDefaults/` contains exactly one subdirectory, `generic/` (confirmed via `find configDefaults -maxdepth 1`); there is no `configDefaults/symfony/` or any other platform directory. The actual resolution mechanism is `configPath()` in `includes/functions.inc.bash:66-78`, a 3-level lookup: `{project}/qaConfig/{relativePath}` → `configDefaults/{platform}/{relativePath}` (currently always falls through, since no platform dir exists) → `configDefaults/generic/{relativePath}`. There is no separate "built-in defaults" layer distinct from the `configDefaults/generic/*` files themselves — the doc's 6-step cascade conflates config-file resolution (`configPath`) with tool-script resolution (`runTool`) and invents an extra layer that isn't real.

**Suggested correction**: Rewrite as the actual 3-level `configPath()` lookup order, and drop the `configDefaults.inc.bash` reference entirely (or, if platform-specific config dirs are a planned feature, mark it as not-yet-implemented).

---

### DC-004 — CRITICAL — `phpUnitCoverage` documented default is the opposite of the real default

**Doc location**: `CLAUDE.md:146` (Key Configuration Variables code block).

**Quoted claim**:
```bash
phpUnitCoverage=${phpUnitCoverage:-0}
```

**Actual behaviour**: `includes/generic/setConfig.inc.bash:54`:
```bash
phpUnitCoverage=${phpUnitCoverage:-1}
```
The real default is **`1` (coverage ON)**, not `0`. This is not cosmetic — it determines whether every default run pays the Xdebug coverage cost and whether Infection has coverage available to consume without a separate generation pass (see `includes/generic/infection.inc.bash`'s coverage-reuse logic, which assumes the phpunit step already produced coverage in the full-pipeline case).

**Suggested correction**: Change the documented default to `1` and note the auto-downgrade to `0` when Xdebug is unavailable (`setConfig.inc.bash:56-60`).

---

### DC-005 — CRITICAL — PHP version requirement contradicts composer.json and README itself

**Doc location**: `CLAUDE.md:316-319` ("## Environment Requirements").

**Quoted claim**: "- PHP 7.4 or higher (PHP 8.4 supported on php8.4 branch)".

**Actual behaviour**: `composer.json:7` declares `"php": "^8.3"` — Composer will refuse to install this package on PHP 7.4 through 8.2. `README.md:3` (the very next doc in this same audit) correctly states: "A comprehensive quality assurance and continuous integration pipeline for **PHP 8.3+ projects**". CLAUDE.md's own claim contradicts both the machine-enforced constraint and the sibling doc.

**Suggested correction**: Change to "PHP 8.3 or higher (PHP 8.4 supported and default on the `php8.4` branch)".

---

### DC-006 — CRITICAL — PHIVE Install step cites a nonexistent script and inverts the conditional

**Doc location**: `CLAUDE.md:67` (Preflight Phase, step 8); same wording repeated in `docs/pipeline.md:58`.

**Quoted claim**: "8. **PHIVE Install** - If `phive.xml` exists, runs `scripts/phive-install.bash` to install PHAR dependencies".

**Actual behaviour**: No file named `scripts/phive-install.bash` exists anywhere in the repo (`find` confirms). The real script is `scripts/tool-install.bash`, invoked **unconditionally** at `bin/qa:197` (`$qaDir/../scripts/tool-install.bash`, no `if [[ -f phive.xml ]]` guard around the call). Inside `tool-install.bash:46-49`, the logic is actually the reverse of what's documented: if `phive.xml` is **missing**, the script prints an error and `exit 1`s — it does not skip silently. The doc's "if phive.xml exists, runs..." framing implies an optional/conditional step; in reality the step always runs and phive.xml is a hard requirement.

**Suggested correction**: "Runs `scripts/tool-install.bash`, which requires `phive.xml` to exist (fails otherwise) and installs PHAR dependencies to `vendor-phar/` plus the isolated Rector composer project under `tools/rector/`."

---

### DC-007 — MAJOR — README undercounts opt-in PHPStan rules (says 10, actual 12)

**Doc location**: `README.md:242-245` ("### Optional rules (opt-in)").

**Quoted claim**: "Ten additional rules ship as opt-in, split across two files: - `rules-optional.neon` — 6 generic rules... - `rules-optional-symfony.neon` — all generic rules + 4 Symfony/Doctrine-specific rules".

**Actual behaviour**: `rules-optional.neon` lists 6 rules under its `rules:` key (`ForbidNullCoalescingEmptyStringRule`, `ForbidNullCoalescingFalseRule`, `ForbidSilentCatchRule`, `ForbidInlinePhpstanIgnoreRule`, `RequireReadonlyServiceRule`, `RequireVariadicForSingleListParamRule`) **plus 2 more rules registered via `services:`** (`FactorySealedRule`, `ForbidDeprecatedPhpunitMethodRule`, both tagged `phpstan.rules.rule` — i.e. both are genuinely active). That's **8**, not 6 — and the file's own header comment (`rules-optional.neon:36-57`) documents both of the missing two in detail. `rules-optional-symfony.neon` correctly adds 4 more (`ForbidHeaderInjectionRule`, `ForbidRawSqlRule`, `RequireCronIntervalInDescriptionRule`, `RequireExplicitDIAttributeRule`), so the true total is **8 + 4 = 12**, not 10.

**Suggested correction**: "6 named `rules:` + 2 service-registered rules (8 total) suitable for any PHP project" / "...plus 4 Symfony/Doctrine-specific rules (12 total)".

---

### DC-008 — MAJOR — README's "Always-on rules" list is missing at least 8 active rules

**Doc location**: `README.md:197-209` ("### Always-on rules (auto-loaded)").

**Quoted claim**: Lists exactly 6 rules: `ForbidMockingFinalClassRule`, `ForbidAllowMockWithoutExpectationsRule`, `ForbidDangerousFunctionsRule`, `ForbidEmptyCatchBlockRule`, `RequireDeclareStrictTypesRule`, `RequireSensitiveParameterAttributeRule`.

**Actual behaviour**: `rules-default.neon` wires up all 6 of those (verified), but also wires up, unconditionally and without opt-in:
- `ForbidNewDateTimeRule`
- `ForbidEmptyLanguageConstructRule`
- `ForbidLooseComparisonRule`
- `ForbidDeprecatedSerializableRule`
- `ForbidNestedTernaryRule`
- `RequireRuleIdentifierConstantRule`
- `RequireApiOrInternalTagRule` (a substantial, package-type-aware rule with its own dedicated doc at `docs/tools/requireApiOrInternal.md`, described there as "**default-on**... ships in `rules-default.neon`... no opt-in")
- `ApiMustNotExposeInternalRule`

That's 8 additional always-on rules — roughly doubling the actual always-on rule count beyond what README documents. `docs/tools/requireApiOrInternal.md` is not linked from README's consolidated "Docs" list either (see DC-014), compounding the omission.

**Suggested correction**: Either list all ~14 always-on rules, or replace the itemised list with "see `rules-default.neon` for the full, authoritative list" plus a link to `docs/tools/requireApiOrInternal.md`.

---

### DC-009 — MAJOR — README says "three Composer plugins"; four are registered

**Doc location**: `README.md:411-415` ("### Composer Plugins").

**Quoted claim**: "PHP-QA-CI registers three Composer plugins: - **PhiveUpdatePlugin**... - **SkillsDeployPlugin**... - **PhpStanGuardPlugin**...".

**Actual behaviour**: `composer.json`'s `extra.class` array registers **four** plugin classes:
```json
"class": [
  "LTS\\PHPQA\\ComposerPlugin\\PhiveUpdatePlugin",
  "LTS\\PHPQA\\ComposerPlugin\\SkillsDeployPlugin",
  "LTS\\PHPQA\\ComposerPlugin\\PhpStanGuardPlugin",
  "LTS\\PHPQA\\ComposerPlugin\\ManagedSourceDeployPlugin"
]
```
`src/ComposerPlugin/` contains 4 matching `.php` files. `ManagedSourceDeployPlugin` is not a stub — it's the plugin backing the entire "Managed Source" feature CLAUDE.md documents separately (`CLAUDE.md`'s "## Managed Source" section, `CLAUDE/managed-source.md`), and it is explicitly gated by the same `PHP_QA_CI_DISABLE_CONFIG_PUSH` env var README documents two sections earlier (`README.md:390-407`) for `SkillsDeployPlugin` — i.e. README already half-documents this plugin's behaviour without naming it.

**Suggested correction**: "registers four Composer plugins", add `ManagedSourceDeployPlugin` to the list with a one-line description and a link to `CLAUDE/managed-source.md`.

---

### DC-010 — MAJOR — Broken phase numbering; `branchNamePolicy` missing from the phase list entirely

**Doc location**: `CLAUDE.md:92-101` (Phase 3 and Phase 4 headings).

**Quoted claim**:
```
### Phase 3: Static Analysis Tools
10. PHPStan (`phpstan`) - Static analysis tool
11. PHPArkitect (`phpArkitect`) ...
12. SensitiveParameter Usage (`sensitiveParameterUsage`) ...

### Phase 4: Testing Tools
11. PHPUnit (`phpunit`) - Unit testing framework
12. Infection (`infection`) - Mutation testing ...
```

**Actual behaviour (numbering)**: Phase 4 restarts numbering at 11/12, duplicating Phase 3's PHPArkitect/SensitiveParameter numbers, instead of continuing at 13/14.

**Actual behaviour (missing tool)**: `includes/generic/allStaticAnalysisTools.inc.bash:1-6` runs `runToolGuarded branchNamePolicy` **first**, before PHPStan (lines 8-18), PHPArkitect (20-25), and SensitiveParameter Usage (27-32). `branchNamePolicy` (`includes/generic/branchNamePolicy.inc.bash`, a 10KB always-on branch-naming-convention check) does not appear in CLAUDE.md's phase list at all — it is only documented via the separate `branch-policy` skill and `CLAUDE/branch-policy.md`, neither of which is cross-referenced from CLAUDE.md's pipeline description.

**Suggested correction**: Renumber Phase 4 to continue from Phase 3 (13, 14), and add `branchNamePolicy` as the actual first item of Phase 3 (or explain why it's intentionally excluded from the numbered list, with a cross-reference to `CLAUDE/branch-policy.md`).

---

### DC-011 — MAJOR — `packageType` tool undocumented in both root docs

**Doc location**: N/A — absence in `CLAUDE.md`'s Phase 2 list (`CLAUDE.md:82-90`) and Tools Reference, and in `README.md`'s Phase 2 list (`README.md:64-70`).

**Actual behaviour**: `includes/generic/packageType.inc.bash` is an always-on, no-opt-out check ("Default-on, no opt-out: declaring one composer.json line is trivial") that runs in the linting phase, right after `composerChecks` (`includes/generic/allLintingTools.inc.bash:17-24`, `runTool packageType`). It has its own PHP entrypoint (`bin/package-type-check`, listed in `composer.json`'s `bin` array) and its own doc file, `docs/tools/packageType.md`. It is referenced nowhere in either CLAUDE.md or README.md.

**Suggested correction**: Add a "Package Type Declaration" entry to both phase lists (between Composer Checks and Strict Types, matching actual run order) and a Tools Reference subsection in CLAUDE.md, linking `docs/tools/packageType.md`.

---

### DC-012 — MAJOR — Locking System undocumented

**Doc location**: N/A — absence from `CLAUDE.md`'s "### Preflight Phase (Configuration & Setup)" (`CLAUDE.md:29-70`), which stops at step 9 (Pre-Hook).

**Actual behaviour**: `bin/qa:208-219` sources `includes/generic/timing.inc.bash` (8KB) and `includes/generic/lock.inc.bash` (17.8KB) and calls `initLockSystem`, `acquireLock` (which can `exit 1` and abort the whole run if another `qa` process holds the lock), and `setupLockCleanup` — all **after** the Pre-Hook step and **before** any tool phase runs. This is a substantial, behaviourally significant subsystem (it can cause a run to fail outright with no tool having executed) that is entirely absent from CLAUDE.md's step-by-step Preflight Phase description.

**Suggested correction**: Add a step 10, "Locking — acquires a run lock (`lock.inc.bash`) to prevent concurrent `qa` invocations; a held lock aborts the run", to the Preflight Phase list.

---

### DC-013 — MAJOR — Read-only / aggregate-mode subsystem undocumented

**Doc location**: N/A — absence from both `CLAUDE.md` and `README.md` entirely (confirmed via grep: zero hits for `qaReadOnly`, `QA_READONLY`, or `aggregate` in either file).

**Actual behaviour**: `bin/qa:97-122` implements a whole orthogonal-to-CI "read-only / check mode" (`qaReadOnly`, auto-enabled on GitHub Actions via `detectReadOnly()` in `includes/functions.inc.bash:273-289`) and an "aggregate (non-fail-fast) mode" (`qaAggregate`, `runToolGuarded`, `qaReportAggregate` in the same file, lines 330-397). This governs whether Rector and PHP CS Fixer are allowed to write to disk versus `--dry-run` and fail on any pending change (documented at length in the *header comments* of `includes/generic/rector.inc.bash` and `includes/generic/phpCsFixer.inc.bash` — i.e. the feature is well-documented at the code level, just never surfaced in either root doc). A user reading only CLAUDE.md/README.md would have no idea `QA_READONLY=1` or `QA_READONLY=0` exist, nor that CI auto-enables read-only + aggregate mode.

**Suggested correction**: Add a "Read-Only / CI Verification Mode" section to CLAUDE.md's Configuration System, covering `QA_READONLY`, `QA_FAIL_FAST`, and how they interact with `CI`/`GITHUB_ACTIONS`.

---

### DC-014 — MINOR — README's Docs list omits 2 of 6 files in `docs/tools/`

**Doc location**: `README.md:429-433` ("Tool-specific documentation").

**Quoted claim**: Lists only `docs/tools/phpstan.md`, `docs/tools/phpunit.md`, `docs/tools/infection.md`.

**Actual behaviour**: `docs/tools/` contains 6 files: `infection.md`, `packageType.md`, `phpstan.md`, `phpunit.md`, `requireApiOrInternal.md`, `sensitiveParameterUsage.md`. `sensitiveParameterUsage.md` *is* linked, but separately and earlier in the file (`README.md:309`, inline in the "SensitiveParameter usage check" section) rather than from this consolidated list. `packageType.md` and `requireApiOrInternal.md` are not linked from anywhere in README.md.

**Suggested correction**: Add all remaining `docs/tools/*.md` files to the consolidated list for discoverability.

---

### DC-015 — MINOR — Orphaned, unregistered hook file not mentioned in "Included Hooks"

**Doc location**: `CLAUDE.md`'s "Included Hooks" list (Claude Code Hooks section) and `README.md:381-386` ("### Included Hooks") — both list exactly 6 hooks.

**Actual behaviour**: `.claude/hooks/` contains all 6 documented hooks plus a 7th file, `php-qa-ci__check-vendor-uncommitted.py` (516 lines), that is not mentioned in either list. Grep of `.claude/settings.json` and `.claude/settings.local.json` for `check-vendor-uncommitted` returns no matches — the hook is not wired into any event, so it also appears to be dead/orphaned code, independent of the doc gap. (There is a related `git-hooks/pre-commit-check-vendor-uncommitted` at the repo root, but a relationship between the two was not confirmed.)

**Suggested correction**: Either document the 7th hook (if intended to be live) or remove the orphaned file (if superseded by the root-level `git-hooks/pre-commit-check-vendor-uncommitted`).

---

### DC-016 — MINOR / SPECULATIVE — "PHPLoc cannot fail the pipeline" not clearly guaranteed by the code

**Doc location**: `CLAUDE.md`'s Post-Success Phase description ("This is informational only and cannot fail the pipeline") and `README.md:80` ("**Post-Success:** PHPLoc (stats only, cannot fail)").

**Actual behaviour**: `bin/qa:314` calls `runTool phploc` directly (not `runToolGuarded`, and not wrapped in any failure-swallowing construct). `includes/generic/phploc.inc.bash` runs `phpNoXdebug -f "$binDir"/phploc ${pathsToCheck[@]}` unconditionally inside an `if [[ -f "$binDir"/phploc ]]; then ... fi` block — the file-existence check is the `if`'s condition, but the `phpNoXdebug` call itself is the body, not the condition, so under `bin/qa`'s `set -e` a non-zero exit from the phploc PHAR would abort the script before reaching `hookPost.bash` and the final completion banner. I have **not** executed a failing phploc run to confirm this in practice, so I'm flagging it as SPECULATIVE rather than a confirmed contradiction — but the code does not visibly guarantee the "cannot fail" claim the way, e.g., `runToolGuarded`'s explicit subshell-and-continue logic does for aggregate mode.

**Suggested correction**: Either wrap the phploc call so a crash truly cannot abort the run (e.g. `if ! phpNoXdebug ...; then echo "phploc failed (non-fatal)"; fi`), or soften the doc claim to "not part of the pass/fail gate" without asserting it literally cannot abort the script.

---

### DC-017 — INFO / SPECULATIVE — Install instructions unverified against a live registry

**Doc location**: `README.md:9-11` ("## Install").

**Quoted claim**: `composer require --dev lts/php-qa-ci:dev-php8.4@dev`.

**Actual behaviour**: Internally consistent — `composer.json:2` declares `"name": "lts/php-qa-ci"` and the current branch is `php8.4`, so the version constraint syntax is plausible. This environment has no access to Packagist/VCS to confirm the package is actually published and installable under this name/constraint, so this is unverified rather than confirmed-wrong.

---

## 3. Verified Accurate (do NOT touch in the fix phase)

The following sections were checked in detail against the implementing code and found correct:

- **Rector** (`CLAUDE.md` Tools Reference + README) — tool path, config path (`configDefaults/generic/rector-safe.php`), the qaReadOnly dry-run/retry split, and the safe-function preflight check all match `includes/generic/rector.inc.bash` and `configDefaults/generic/rector-safe.php` exactly.
- **PHP CS Fixer** — tool/config/finder paths, exit-code semantics (0/8), and the qaReadOnly split all match `includes/generic/phpCsFixer.inc.bash`, `configDefaults/generic/php_cs.php`, `configDefaults/generic/php_cs_finder.php`.
- **PHP 8.4-specific PHP CS Fixer rules** (`nullable_type_declaration_for_default_null_value`, `nullable_type_declaration` syntax) — quoted verbatim in CLAUDE.md, matches `php_cs.php` exactly.
- **"Removed PHP_CodeSniffer completely"** claim — confirmed; only a historical comment remains (`php_cs.php:60`), no active phpcs config/dependency anywhere.
- **Composer Checks** — tool path, `ergebnis/composer-normalize` requirement, and behaviour (diagnose → normalize → dump-autoload) all match `includes/generic/composerChecks.inc.bash`.
- **PHP Strict Types** — interactive per-file prompt behaviour matches `includes/generic/phpStrictTypes.inc.bash` exactly.
- **PHP Lint** — matches `includes/generic/phpLint.inc.bash` (parallel-lint wrapper).
- **Composer Require Checker** — tool/config paths and the whitelist guidance ("never add dev-dependency symbols") match `includes/generic/composerRequireChecker.inc.bash` and `configDefaults/generic/composerRequireChecker.json` closely.
- **Markdown Links Checker** — binary (`bin/mdlinks`), and documented scope ("README.md and all files in docs/") confirmed against `src/Markdown/LinksChecker.php` (checks `$projectRootDirectory/docs` and `$projectRootDirectory/README.md`).
- **PHPStan level: max** — confirmed in `configDefaults/generic/phpstan.neon`.
- **PHPArkitect** (README's large dedicated section) — tier files/env vars, the PHIVE key/short-id math (`D9C905CED1932CA2` is genuinely the trailing 16 chars of `47CD54B6398FE21B3709D0A4D9C905CED1932CA2`, verified programmatically), the exclude-path mechanism, and `templates/qaConfig-phparkitect.php` all check out exactly against `includes/generic/phpArkitect.inc.bash` and `configDefaults/generic/phparkitect.php`.
- **PHPUnit** — bootstrap auto-creation, paratest detection, and coverage-mode switching all match `includes/generic/phpunit.inc.bash` / `configDefaults/generic/phpunit.xml`.
- **Infection** — tool/config paths, Xdebug/coverage requirement, and `useInfection` default (`1`, matching `setConfig.inc.bash:68`) all correct.
- **SensitiveParameter usage check** (README's dedicated section) — matches `includes/generic/sensitiveParameterUsage.inc.bash` and `rules-default.neon`'s `RequireSensitiveParameterAttributeRule` wiring exactly, including the escape hatch and php-qa-ci's own opt-out in `qaConfig/qaConfig.inc.bash` as the "canonical worked example."
- **Managed Source** (CLAUDE.md) — matches `CLAUDE/managed-source.md` and `src/ManagedSource/ManagedSourceGenerator.php`, including the `FactorySealedBy` artefact claim (confirmed at `ManagedSourceGenerator.php:79,146,184`).
- **CI/CD Workflows** (README) — `ci.yml`, `qa.yml`, `update-deps.yml` all exist in `.github/workflows/`; `templates/github-actions/php-qa-ci.yml` and `qa-autofix.yml` both exist.
- **Tool Runner System** description (project override → platform → generic) — matches `runTool()` in `includes/functions.inc.bash:20-37` exactly.
- **Memory Configuration** (`phpqaMemoryLimit` default `4G`) — matches `setConfig.inc.bash:4` and its use in `phpNoXdebug()`.
- **Most other Key Configuration Variables** (`phpqaQuickTests`, `phpUnitQuickTests`, `phpUnitIterativeMode`, `useInfection`, `CI`, `skipUncommittedChangesCheck`) all match their real defaults — only `phpUnitCoverage` (DC-004) was wrong.
- **"Where does a rule belong" migration note** (README) — the claim that the Interface/Enum/Trait suffix convention "used to be the PHPStan `RequireTypeSuffixRule` and is now owned solely by the default arkitect tier" is confirmed verbatim in `rules-default.neon`'s own comment (lines 11-15).
- **PHIVE tool list** (README's "Tool Delivery" section: PHPStan, PHP CS Fixer, Infection, Composer Require Checker, PHPArkitect) — matches `phive.xml` exactly (5 PHARs, same names).

---

## 4. Coverage Statement (not verified / out of time budget)

- `docs/*.md` beyond `docs/pipeline.md` (spot-checked for corroboration only) — `docs/coding-standards.md`, `docs/configuration.md`, `docs/ci.md`, `docs/github-actions.md`, `docs/platform-detection.md`, `docs/phpqa-tools.md` were confirmed to **exist** (all links in CLAUDE.md/README.md resolve) but their internal content was not exhaustively fact-checked against code.
- `docs/tools/*.md` content depth — existence confirmed for all 6 files; only `requireApiOrInternal.md`'s opening section was read in detail.
- `includes/symfony/*` (platform-specific tool overrides: `allLintingTools.inc.bash`, `setConfig.inc.bash`, `twigLint.inc.bash`, `yamlLint.inc.bash`) — not cross-checked in depth against `docs/platform-detection.md`'s claims.
- `scripts/install-github-actions.bash`, `scripts/setup-branch-protection.bash`, `scripts/deploy-skills.bash` — README describes their behaviour in bullet lists; the scripts' full source was not read to verify each bullet individually (only `scripts/tool-install.bash` was read in full, for DC-006).
- Actual installability of `composer require --dev lts/php-qa-ci:dev-php8.4@dev` against a live Packagist/VCS registry (DC-017) — no network access to verify from this environment.
- `.claude/agents/`, `.claude/commands/`, `.claude/skills/` contents — not audited against CLAUDE.md's Claude Code Integration claims beyond the hooks list itself.
- `git-hooks/pre-commit-check-vendor-uncommitted` vs. the orphaned `.claude/hooks/php-qa-ci__check-vendor-uncommitted.py` (DC-015) — a relationship between the two was suspected but not confirmed by reading both files' full contents.
- `tests/**` — only used incidentally (existence of `Psr4ValidatorTest.php` noted) to corroborate that `src/Psr4Validator.php` itself is not dead code, independent of the pipeline-wiring bug in DC-001.
