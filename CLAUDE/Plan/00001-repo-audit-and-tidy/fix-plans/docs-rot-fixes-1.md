# Docs-Rot Remediation Plan — php-qa-ci (fix-plans/docs-rot-fixes-1.md)

**Status**: DRAFT — planning only, no doc edits made yet.
**Inputs**: `synthesis/findings-master-1.md` (M-IDs), `audits/docs-rot-core-1.md` (DC-IDs), `audits/docs-rot-secondary-1.md` (DS-IDs).
**Scope**: every DOCS-axis finding in the master register (M-005..M-008, M-029..M-043,
M-054..M-062, M-080..M-084) plus theme clusters (b) fabrications and (c) missing subsystems.

---

## 1. Strategy

### 1.1 The root problem

The docs estate has **structural duplication, not just individual drift**. The same fact is
hand-authored in 2-4 places that evolve independently:

| Fact | Duplicated in | Findings |
|---|---|---|
| Platform detection (Symfony/generic) | `docs/platform-detection.md`, `docs/pipeline.md`, `CLAUDE.md`, `README.md` | M-005 — all 4 share one fabricated "Laravel" claim, a single origin that propagated, not 4 independent errors |
| Phase/tool inventory + ordering | `CLAUDE.md`, `README.md`, `docs/pipeline.md`, `docs/phpqa-tools.md` | M-031, M-032, M-033, M-034 |
| PHPStan rule counts/lists | `README.md`, `docs/tools/phpstan.md` | M-029 |
| Composer plugin count | `README.md` (sole location, but drifted from `composer.json`) | M-030 |
| Config cascade mechanism | `CLAUDE.md`, `docs/configuration.md` | M-006 |

Every one of these is a **hand-synced SSoT violation**: nobody owns the fact, so nobody notices
when one copy drifts. This mirrors M-011 in the bash layer (tool registry hand-synced 3-4 ways) —
the docs estate has the same disease. Fixing the words without fixing the *ownership model* means
the next PR reintroduces the same drift in 6 months.

### 1.2 Target docs architecture

Assign exactly one SSoT per fact, everything else links to it instead of restating it:

- **`CLAUDE.md`** = agent-facing operating manual. It is the only doc guaranteed to be loaded
  into every Claude Code session working on this repo (confirmed operationally — this very
  planning session had `CLAUDE.md` injected as system context). That makes its accuracy the
  highest-leverage fix in the whole audit: an error here propagates to every future agent
  session, not just a human reader who happens to open the file. **Designate CLAUDE.md as the
  canonical, full, ordered phase-and-tool list** (fixing numbering + adding missing tools — see
  WP-D1) and the canonical **Key Configuration Variables** table. It should NOT re-derive rule
  counts, plugin counts, or config-cascade worked examples — those link out.
- **`README.md`** = human quickstart + narrative reference for a first-time consumer. SSoT for:
  install instructions, the PHPArkitect deep-dive section, the "where does a rule belong"
  migration note. It must **stop independently enumerating** the phase list (link to CLAUDE.md),
  the PHPStan rule inventory (link to `docs/tools/phpstan.md`), and platform detection (link to
  `docs/platform-detection.md`) — each of those has caused a duplicate-drift finding.
- **`docs/*.md`** = deep-dive mechanics, one topic per file, each the sole owner of its subject's
  detail: `docs/pipeline.md` (how phases/retry/aggregate-mode work — but its ordered list must
  literally match CLAUDE.md's, not be independently authored), `docs/configuration.md` (cascade
  mechanics + worked example + full file inventory), `docs/platform-detection.md` (platform
  detection, sole owner), `docs/coding-standards.md`, `docs/github-actions.md`,
  `docs/phpqa-tools.md` (mechanics only — no redundant tool inventory).
- **`docs/tools/*.md`** = sole SSoT for exact rule lists, thresholds, and counts per analysis
  tool. `README.md`/`docs/phpstan.md` cross-references point here instead of re-listing.
- **`CLAUDE/*.md`** = process/subsystem reference docs for engineers and agents working *on*
  php-qa-ci itself (branch-policy, managed-source, DefenceBeforeFix). These are living reference
  docs, not point-in-time plans, and should not sit under `CLAUDE/Plan/`.
- **`CLAUDE/Plan/`** = point-in-time planning artifacts only. Adopt a two-bucket archival
  convention this very plan (`00001-repo-audit-and-tidy`) already models with its `NNNNN-name`
  numbering: `CLAUDE/Plan/Completed/` for plans implemented as described (accurate historical
  record) and `CLAUDE/Plan/Superseded/` for plans whose implementation diverged so far that the
  prose is actively misleading if read as current (so a reader browsing `Completed/` never hits
  rot). See WP-D13.

### 1.3 DS verdict actions (delete / merge / archive)

| Doc | DS verdict | Action | WP |
|---|---|---|---|
| `docs/platform-detection.md` | ROTTEN | **Rewrite** (fabrication only; subsystem is real and needs a doc) — becomes the sole SSoT per §1.2 | WP-D3 |
| `CLAUDE/Plan/skills-deployment-system-2025-11.md` | ROTTEN | **Archive-with-disclaimer** into `CLAUDE/Plan/Superseded/` — 705-line real script vs 70-line stale example, not worth a full rewrite in this pass | WP-D13 |
| `docs/_config.yml` | DELETE-CANDIDATE | **Delete** — confirmed dead (no Pages workflow, `gh api .../pages` → 404, single commit, no Jekyll scaffolding) | WP-D13 |
| `CLAUDE/ANALYSIS/timing-schema-analysis.md` | OBSOLETE-ARCHIVE (borderline DELETE) | **Delete** — zero repo references, reviews a stale draft whose line citations don't resolve against the current doc, 4/5 concerns never acted on | WP-D13 |
| `CLAUDE/Plan/worktree-git-env-fix.md` | OBSOLETE-ARCHIVE | **Move** to `CLAUDE/Plan/Completed/` — implemented exactly as described, clean historical record | WP-D13 |
| `CLAUDE/Plan/locking-system.md` | DRIFTED (2 stale claims) | **Relocate + fix** — this is the *only* documentation of a live, run-aborting subsystem; living under `Plan/` mislabels it as a proposal. Move to `CLAUDE/locking-system.md`, fix DS-006/DS-007 | WP-D11 (gated) |

---

## 2. Work packages

Each WP lists: scope (files), M-IDs closed, exact old→new corrections with evidence, effort
(S/M/L), and dependencies.

### WP-D8 — Fix the actively-misleading CI-repro command (do this first)

- **Scope**: `docs/github-actions.md`
- **Closes**: M-008
- **Correction**: Troubleshooting section (`:270-278`) says `CI=true vendor/bin/qa` reproduces
  CI locally. `CI` only controls interactivity (`includes/functions.inc.bash:262-289`,
  `detectReadOnly()`); the read-only gate that actually fails CI on Rector/CS-Fixer drift keys on
  `GITHUB_ACTIONS=true` or `QA_READONLY=1`. Running the documented command locally executes in
  **writable** mode — fixers silently apply changes, the run "passes" locally while CI keeps
  failing. This contradicts the same doc's own correct statement at `:117-120`. **Change the
  command to `QA_READONLY=1 vendor/bin/qa`.**
- **Effort**: S
- **Dependencies**: none. Prioritized first because it actively misleads someone debugging a
  live CI failure — real-world harm, zero cost to fix, zero code dependency.

### WP-D1 — Pipeline phase/tool inventory consolidation

- **Scope**: `CLAUDE.md`, `README.md`, `docs/pipeline.md`, `docs/phpqa-tools.md`
- **Closes**: M-031, M-032, M-033, M-034, M-056
- **Corrections**:
  - `CLAUDE.md` Phase 4 heading (`:92-101`) restarts numbering at 11/12 instead of continuing
    13/14 from Phase 3 — renumber.
  - `branchNamePolicy` (`includes/generic/allStaticAnalysisTools.inc.bash:1-6`, runs **first** in
    Phase 3, before PHPStan/PHPArkitect/SensitiveParameter) is absent from CLAUDE.md's phase
    list entirely — add it as the actual first item of Phase 3.
  - `packageType` (`includes/generic/allLintingTools.inc.bash:17-24`, always-on, runs between
    Composer Checks and Strict Types) is absent from CLAUDE.md's Phase 2 list and Tools
    Reference — add both, with a link to `docs/tools/packageType.md`.
  - Add a Preflight step 10, "Locking — acquires a run lock (`lock.inc.bash`) to prevent
    concurrent `qa` invocations; a held lock aborts the run", after step 9 (Pre-Hook). Evidence:
    `bin/qa:208-219`.
  - Add a new "Read-Only / CI Verification Mode" section to CLAUDE.md's Configuration System,
    covering `QA_READONLY`/`qaReadOnly` (auto-enabled on GitHub Actions via `detectReadOnly()`)
    and aggregate/`--json` modes (`bin/qa:97-122,330-397`). Currently zero mentions of
    `qaReadOnly`/`QA_READONLY`/`aggregate` in either root doc.
  - `docs/pipeline.md`: add the same 3 missing tools (`packageType`, `branchNamePolicy`, and —
    unlike CLAUDE.md — `phpArkitect` is *also* missing here per DS-009); fix the PHIVE-install
    description (folded into WP-D6, don't duplicate the fix here).
  - `docs/phpqa-tools.md`: add all 4 missing tools (`packageType`, `branchNamePolicy`,
    `phpArkitect`, `sensitiveParameterUsage`) to its tool inventory; fix 3 stale `#Lxx` anchors
    into `functions.inc.bash` (`runTool` off by 10 lines, `detectPlatform` off by 1,
    `checkForUncommittedChanges` off by 16).
  - **SSoT enforcement**: per §1.2, `README.md`'s own phase list (`:64-70`) should be trimmed to
    a short summary ending "— see CLAUDE.md § Pipeline Execution Order for the full ordered
    list" rather than independently re-enumerated; `docs/pipeline.md`'s numbered list must match
    CLAUDE.md's numbers exactly (copy, don't re-derive).
- **Effort**: M
- **Dependencies**: none — pure fact correction from code, no pending decision. Do early since
  WP-D2/WP-D6 touch the same files and should land after the numbering is stable.

### WP-D3 — Platform-detection fabrication (Laravel cluster)

- **Scope**: `docs/platform-detection.md`, `docs/pipeline.md`, `CLAUDE.md`, `README.md`
- **Closes**: M-005
- **Correction**: `detectPlatform()` (`includes/functions.inc.bash:6-13`) checks **only**
  `symfony.lock` → Symfony, else generic. There is no `artisan` check, no `platformLaravel`
  constant, zero `artisan`/`laravel` hits in any `.bash` file. Remove every Laravel/`artisan`
  reference from all 4 files. Per §1.2, `docs/platform-detection.md` becomes sole SSoT; the other
  3 replace their platform description with one line: "Symfony (`symfony.lock`) or generic — see
  `docs/platform-detection.md`."
- **Effort**: S — mechanical, well-evidenced, one shared root cause.
- **Dependencies**: none.

### WP-D2 — Config cascade + config variable corrections

- **Scope**: `CLAUDE.md`, `docs/configuration.md`
- **Closes**: M-006, M-035, M-041, M-055
- **Corrections**:
  - `CLAUDE.md` "Configuration Cascade" (`:124-129`) cites a nonexistent `configDefaults.inc.bash`
    and nonexistent `configDefaults/{platform}/` dirs (only `configDefaults/generic/` exists).
    Rewrite as the actual 3-level `configPath()` lookup (`includes/functions.inc.bash:66-78`):
    `{project}/qaConfig/{path}` → `configDefaults/{platform}/{path}` (currently always falls
    through — no platform dir exists) → `configDefaults/generic/{path}`. Drop the
    `configDefaults.inc.bash` reference entirely.
  - `docs/configuration.md:92-96` worked example cites `configDefaults/symfony/phpstan.neon`
    (nonexistent) — replace with a real example against `configDefaults/generic/`.
  - `docs/configuration.md:39-42`: `phpqaQuickTests` is described as running "only fast PHPQA
    tests." Actual effect: setting it to `1` **entirely skips PHPStan, PHPUnit, and Infection**
    (`allStaticAnalysisTools.inc.bash`, `allTestingTools.inc.bash`), not just faster tests inside
    a phase. Disambiguate explicitly from the narrower `phpUnitQuickTests` (skips slow tests
    *within* PHPUnit only).
  - `docs/configuration.md:76-86` file inventory omits `php_cs_finder.php`, `phparkitect.php`,
    `phparkitect-consumer-api-boundary.php`, `phparkitect-rules-default.php`,
    `phparkitect-rules-optional*.php` — add all 5.
  - `CLAUDE.md` Key Configuration Variables (`:146`): `phpUnitCoverage=${phpUnitCoverage:-0}` is
    documented; actual default is `1` (`setConfig.inc.bash:54`). Change to `1`, note the
    auto-downgrade to `0` when Xdebug is unavailable (`setConfig.inc.bash:56-60`).
    **Soft-danger note**: the code default (`1`) is corroborated as intentional by
    `infection.inc.bash`'s own header comment, which assumes the phpunit step already produced
    coverage in the full-pipeline case — this is a real behavioural dependency, not an
    accidental flip. Document `1` as correct. If a future investigation finds the flip *was*
    accidental, reverting is a CODE decision for the bash-refactor plan, not a doc-only fix —
    flag but do not block WP-D2 on it.
- **Effort**: M
- **Dependencies**: none (see soft-danger note above — does not block).

### WP-D4 — PHPStan / PHPArkitect rule-count corrections

- **Scope**: `README.md`, `docs/tools/phpstan.md`
- **Closes**: M-029
- **Corrections**:
  - README "Always-on rules" (`:197-209`) lists 6; `rules-default.neon` wires ~14 (the 6 listed
    plus `ForbidNewDateTimeRule`, `ForbidEmptyLanguageConstructRule`, `ForbidLooseComparisonRule`,
    `ForbidDeprecatedSerializableRule`, `ForbidNestedTernaryRule`,
    `RequireRuleIdentifierConstantRule`, `RequireApiOrInternalTagRule`,
    `ApiMustNotExposeInternalRule`). Per §1.2, replace the itemized list with "see
    `rules-default.neon` for the full authoritative list" + a link to
    `docs/tools/requireApiOrInternal.md` — do not hand-list, that's what caused the drift.
  - README "Optional rules" (`:242-245`) says "ten"; actual is `rules-optional.neon` (6 named +
    2 service-registered = 8) + `rules-optional-symfony.neon` (+4) = **12**. Fix the count and
    explicitly mention the 2 service-registered rules (`FactorySealedRule`,
    `ForbidDeprecatedPhpunitMethodRule`) that the current text omits.
  - `docs/tools/phpstan.md:50-58` lists 7 default-on rules — add the same 6 missing.
    `docs/tools/phpstan.md:66-69` says "10 additional opt-in rules" — fix to 12, same
    corroborating detail as above. This file is the designated SSoT (§1.2); once fixed, README's
    pointer stays accurate by construction.
- **Effort**: M
- **Dependencies**: none.

### WP-D5 — Composer plugin count + README doc-list completeness

- **Scope**: `README.md`
- **Closes**: M-030, M-059
- **Corrections**:
  - `:411-415` says "three Composer plugins"; `composer.json extra.class` registers **four**
    (`PhiveUpdatePlugin`, `SkillsDeployPlugin`, `PhpStanGuardPlugin`, and the omitted
    `ManagedSourceDeployPlugin`). Add the fourth with a one-line description + link to
    `CLAUDE/managed-source.md`.
  - Consolidated Docs list (`:429-433`) lists only 3 of 6 `docs/tools/*.md` files (missing
    `packageType.md`, `requireApiOrInternal.md` — `sensitiveParameterUsage.md` is linked
    elsewhere in the doc but not from this list). Add both missing files to the list.
- **Effort**: S
- **Dependencies**: none.

### WP-D6 — PHIVE install description + PHP version requirement

- **Scope**: `CLAUDE.md`, `docs/pipeline.md`
- **Closes**: M-040, M-042
- **Corrections**:
  - CLAUDE.md step 8 (`:67`) and `docs/pipeline.md:58` both say "If `phive.xml` exists, runs
    `scripts/phive-install.bash`." That script doesn't exist; the real script is
    `scripts/tool-install.bash`, run **unconditionally** at `bin/qa:197`. Inside it,
    `phive.xml` missing causes an `exit 1` (`tool-install.bash:46-49`) — the doc's conditional
    framing is backwards. New text: "Runs `scripts/tool-install.bash` (unconditional; requires
    `phive.xml` to exist, exits 1 otherwise) — installs PHAR dependencies to `vendor-phar/` plus
    the isolated Rector composer sub-project under `tools/rector/` on first use."
  - CLAUDE.md Environment Requirements (`:316-319`) says "PHP 7.4 or higher"; `composer.json:7`
    requires `^8.3` and README already correctly says "PHP 8.3+". Change to "PHP 8.3 or higher
    (PHP 8.4 supported and default on the `php8.4` branch)."
- **Effort**: S
- **Dependencies**: none.

### WP-D7 — Tool-specific doc corrections (infection, phpunit, coding-standards, github-actions)

- **Scope**: `docs/tools/infection.md`, `docs/tools/phpunit.md`, `docs/coding-standards.md`,
  `docs/github-actions.md`
- **Closes**: M-036, M-054, M-057, M-058, M-080
- **Corrections**:
  - `docs/tools/phpunit.md:87-92` claims coverage mode makes PHPUnit "fail on the first error"
    and "not enforce any time limits." Both are wrong: `--stop-on-*` only applies in the separate
    `phpUnitIterativeMode` branch (code comment: `# Note: Removed stop-on-failure flags to allow
    full test runs in CI`, i.e. deliberately reverted); `--enforce-time-limit` is only skipped in
    the **CI**+coverage branch — local-dev+coverage still enforces it. Rewrite both claims to
    match `includes/generic/phpunit.inc.bash`.
  - `docs/coding-standards.md:21` names the ruleset `@PHP84Migration`; the real key
    (`configDefaults/generic/php_cs.php:27`) is `@PHP8x4Migration`. Fix — a literal copy-paste
    would currently fail to match a real ruleset name.
  - `docs/tools/infection.md:11-18` implies `phpUnit.configDir` must be *added*; the shipped
    default (`infection.json`) already contains it — rephrase as "override this existing default"
    not "add this absent key." Same section's default Covered-MSI claim (90%) doesn't match the
    shipped default (80%) — fix.
  - `docs/github-actions.md`: `:176` lists 4 PHARs, omitting `phparkitect/arkitect` (added to
    `phive.xml` in commit `00f2d08`) — add it (5 total). `:100`/`:22` tell users to select "PHP
    QA Pipeline" as a required branch-protection check; that's the workflow name, not a
    selectable check-run name — actual check-run names are "Detect PHP Version", "PHP QA (8.4)"
    (dynamic), "Coverage Report". `:218-226` overstates cache-key precision — the Composer cache
    key doesn't include the PHP version (only the combined `var/qa/cache`+`vendor-phar` cache
    does), and there's no separate "PHPStan cache" block. Fix all three.
  - `docs/github-actions.md`'s qa-autofix section doesn't mention the same-commit
    deploy-key-coverage guard added in the most recent commit touching that section — add one
    sentence noting it verifies `CI_SSH_DEPLOY_BUNDLE` covers every private dependency before
    running.
- **Effort**: M
- **Dependencies**: none.

### WP-D9 — update-deps.yml self-referential branch (doc-only interim fix)

- **Scope**: `docs/github-actions.md`
- **Closes**: M-007 (doc-side only — see DANGER)
- **Correction (doc-only, do now)**: `:181-185` instructs `cp
  vendor/lts/php-qa-ci/.github/workflows/update-deps.yml .github/workflows/update-deps.yml`. That
  file hardcodes `ref: php8.4` (php-qa-ci's own dogfooding branch) with no templated/dynamic
  branch resolution, unlike `qa-autofix.yml` which resolves
  `github.event.repository.default_branch`. Following the instruction as written ships a workflow
  whose every scheduled run fails at checkout. Add an explicit warning immediately after the `cp`
  instruction: "You must manually edit the copied file's `ref: php8.4` to your project's own
  default branch before use — there is currently no generic template for this workflow."
- **DANGER — full fix is a code dependency, out of this plan's scope**: the real fix is a new
  `templates/github-actions/update-deps.yml` with dynamic branch resolution (mirroring
  `qa-autofix.yml`'s pattern) — that's a new artifact, not a doc edit. Flag to
  `consumer-scripts-1.md` / `bash-refactor-1.md` as a follow-up code work item. Once that template
  exists, revert this WP's warning and point the doc at the template copy instead.
- **Effort**: S (doc warning only)
- **Dependencies**: DANGER — full resolution blocked on a new template file (code change) outside
  this plan.

### WP-D10 — Branch-policy fabricated fallback list

- **Scope**: `CLAUDE/branch-policy.md`
- **Closes**: M-037
- **Correction**: `:52-54` claims default-branch detection falls back to "common candidates like
  `main`, `master`, `develop`, `DumbItDown`, `NewCheckout`" when git detection fails. Actual
  `includes/generic/branchNamePolicy.inc.bash:118-126` does the opposite — a code comment reads
  "NO hardcoded list — that's overfitting" — and on detection failure it only warns and requires
  `qaConfig/branchNamePolicy.yaml` configuration. Remove the fabricated fallback list; replace
  with the real behaviour (warn + require explicit config, no hardcoded guesses).
- **Effort**: S
- **Dependencies**: none.

### WP-D13 — Dead/orphan doc cleanup, deletions, and archival convention

- **Scope**: `docs/_config.yml` (delete), `CLAUDE/ANALYSIS/timing-schema-analysis.md` (delete),
  `CLAUDE/Plan/worktree-git-env-fix.md` (move), `CLAUDE/Plan/skills-deployment-system-2025-11.md`
  (annotate + move), `CLAUDE.md` (Included Hooks list), new `CLAUDE/Plan/Completed/` and
  `CLAUDE/Plan/Superseded/` directories
- **Closes**: M-039, M-060, M-061, M-062, M-084
- **Actions**:
  - **Delete** `docs/_config.yml` — confirmed dead: no GitHub Pages workflow, `gh api
    repos/.../pages` → 404, single commit, no Jekyll scaffolding (`Gemfile`, `index.md`,
    `_layouts/` all absent).
  - **Delete** `CLAUDE/ANALYSIS/timing-schema-analysis.md` — zero repo references anywhere; its
    own line-number citations ("line 680-683" etc.) don't resolve against the current 1083-line
    `locking-system.md`, confirming it reviews a stale, superseded draft; 4 of its 5 concerns were
    never acted on.
  - **Adopt** the `CLAUDE/Plan/Completed/` + `CLAUDE/Plan/Superseded/` two-bucket convention
    (extends this very plan's `NNNNN-name` numbering pattern). `Completed/` = implemented as
    described, safe to trust as historical record. `Superseded/` = implementation diverged enough
    that the prose would actively mislead if read as current.
  - **Move** `CLAUDE/Plan/worktree-git-env-fix.md` → `CLAUDE/Plan/Completed/` — implemented
    exactly as described, stable, no content changes needed.
  - **Move** `CLAUDE/Plan/skills-deployment-system-2025-11.md` → `CLAUDE/Plan/Superseded/`, after
    prepending a banner: "SUPERSEDED — historical proposal only, not a current how-to. The real
    `scripts/deploy-skills.bash` (705 lines) diverged substantially: different source directory
    layout, plus hooks-daemon detection, git-hooks deployment, PHPStan rule scaffolding, and
    CLAUDE.md injection this doc never anticipated. Do not use this as a guide to current
    behaviour." (Full rewrite of a 705-line-accurate replacement doc is out of scope for this
    pass — see §5 Out of scope.)
  - **CLAUDE.md Included Hooks list**: `.claude/hooks/` has a 7th file,
    `php-qa-ci__check-vendor-uncommitted.py` (516 lines), absent from the documented 6-hook list
    and not wired into `.claude/settings.json`/`.claude/settings.local.json` (confirmed via
    grep — zero matches). **Verify-before-write step** (self-contained, not a code decision):
    diff this file against the root-level `git-hooks/pre-commit-check-vendor-uncommitted` — the
    audits suspect but never confirmed a duplicate relationship. If duplicate/superseded, delete
    the orphan and don't document it. If distinct and intentionally dormant, add it to CLAUDE.md's
    list with a "(not currently registered)" note. If distinct and meant to be live, that's a code
    wiring gap — flag to bash-refactor plan instead of documenting a hook that doesn't fire.
- **Effort**: M
- **Dependencies**: none blocking. The hook-duplicate check is self-contained research (diff two
  files), not an external decision — do it within this WP.

### WP-D14 — Speculative findings: no action / deferred

- **Scope**: none (informational only)
- **Closes**: M-081, M-082, M-083 (dispositioned, not corrected)
- **Disposition**:
  - **M-081** (PHPLoc "cannot fail the pipeline" claim): `bin/qa:314` calls `runTool phploc`
    directly, not wrapped in any failure-swallowing construct — under `set -e` a non-zero phploc
    exit would plausibly abort the run, contradicting the doc. Not reproduced. **No doc edit until
    a failing-phploc repro confirms or refutes this** — flag to bash-refactor plan as a
    verification task (run phploc against intentionally-broken input under `set -e` and observe).
    If confirmed to abort: soften CLAUDE.md/README.md's "cannot fail" to "not part of the
    pass/fail gate" without the absolute claim.
  - **M-082** (install command unverifiable against live Packagist), **M-083** (external
    ltscommerce.dev URL unverifiable) — both require network access this environment lacks.
    Internally consistent; leave as-is, no action.
- **Effort**: N/A
- **Dependencies**: M-081's doc fix (if any) depends on a bash-refactor verification task, not on
  this plan.

---

## 3. Ordering

Pure-truth corrections first (zero code dependency), grouped to avoid re-touching the same file
twice; code-decision-dependent WPs last, explicitly gated.

1. **WP-D8** — CI-repro self-contradiction (highest real-world harm, trivial fix)
2. **WP-D3** — Laravel fabrication cluster (cheap, high-visibility, one root cause)
3. **WP-D1** — Pipeline phase/tool inventory consolidation (foundational — WP-D2/WP-D6 touch the
   same root docs, land after numbering is stable)
4. **WP-D2** — Config cascade + config variables
5. **WP-D4** — PHPStan/PHPArkitect rule counts
6. **WP-D5** — Composer plugin count + doc-list completeness
7. **WP-D6** — PHIVE install description + PHP version requirement
8. **WP-D7** — Tool-specific corrections (infection/phpunit/coding-standards/github-actions)
9. **WP-D9** — update-deps.yml doc-only interim warning (full fix flagged cross-plan)
10. **WP-D10** — Branch-policy fabricated fallback list
11. **WP-D13** — Dead/orphan doc cleanup, deletions, archival convention

   —— code-decision gate: the following cannot start until the named M-ID is resolved by the
   bash-refactor plan ——

12. **WP-D11** — Locking/timing subsystem promotion + fix — **gated on M-023** (wire
    `toolStart`/`toolComplete`/`toolFailed` into `runTool`, or formally retire them)
13. **WP-D12** — Silent-no-op tool docs (psr4Validate, phpunitAnnotations) — **gated on M-001**
    (restore vs. formally retire PSR-4 validation) **and M-002** (restore vs. formally retire
    PHPUnit annotations check)
14. **WP-D14** — Speculative dispositions — **soft-gated on M-081** verification (bash-refactor
    task); M-082/M-083 need no gate, they're simply left alone

### DANGER — explicit code-decision dependency list

| M-ID | Decision needed | Owner | Blocks |
|---|---|---|---|
| **M-001** | Restore `psr4Validate.inc.bash` wiring, or formally retire PSR-4 validation | bash-refactor-1.md | WP-D12 |
| **M-002** | Re-enable `phpunitAnnotations.inc.bash`, or formally retire the check | bash-refactor-1.md | WP-D12 |
| **M-023** | Wire `toolStart`/`toolComplete`/`toolFailed` into `runTool`, or delete the dead functions | bash-refactor-1.md | WP-D11 |
| **M-041** | Confirm `phpUnitCoverage` default `1` is intentional (soft — strong corroborating evidence from `infection.inc.bash`'s coverage-reuse assumption; does not block WP-D2, but if later found accidental, reverting the default is a code decision, not a doc fix) | bash-refactor-1.md (confirm only) | none (informational) |
| **M-007** (not in the prompt's list, added here) | Create a templated, dynamically-branched `templates/github-actions/update-deps.yml` | consumer-scripts-1.md / bash-refactor-1.md | WP-D9's full resolution (doc-only interim fix ships now) |

---

## 4. Verification

Per WP, in this order:

1. **Claim-by-claim re-check**: for every "old claim → new truth" line above, re-read the cited
   `file:line` after editing and confirm the new prose is a direct, literal match — not a
   paraphrase that reintroduces ambiguity. This plan's tables are written so an implementer can
   do this without re-opening the audits.
2. **`docs-conflict-checker` agent**: this repo already ships
   `php-qa-ci_docs-conflict-checker` (Read + Glob only). Re-dispatch it after each WP lands,
   scoped to the touched files, to catch contradictions with the skills/agents system that a
   pure code cross-check wouldn't surface.
3. **Targeted re-audit**: after all WPs land, re-run the `docs-rot-core` and `docs-rot-secondary`
   audit prompts scoped **only** to the files touched in this plan, diffing their new findings
   against the "old claim → new truth" table row by row. A closed-loop check — confirms the fix
   matches code, not just that the text changed.
4. **Link integrity**: run `vendor/bin/qa -t mdlinks` (this repo's own `bin/qa -t mdlinks`) after
   every WP that moves, deletes, or adds cross-references — especially WP-D13 (2 deletes, 1 move,
   1 relocation-pending-gate) and every WP that adds a new "see X" pointer per the SSoT-linking
   strategy in §1.2.
5. **No new duplication check**: for each WP that replaces an inline list with a link (WP-D1,
   WP-D3, WP-D4), grep the target repo for the fact being consolidated (e.g. rule class names,
   platform names) to confirm no third copy was missed by the original audits.

### Regression guard proposal

The audits show this drift pattern recurs because facts are hand-synced with no structural
enforcement. Two guards, staged by cost:

- **Immediate (this plan)**: the SSoT reassignment in §1.2 itself is the guard — collapsing 4
  copies of the phase list to 1 canonical + 3 pointers, and 2 copies of the rule inventory to 1 +
  1 pointer, means future drift requires someone to *independently re-author* a fact instead of
  just forgetting to update a sync target. Structurally cheaper to keep honest.
- **Structural (tied to M-011)**: once the bash-refactor plan's declarative tool-metadata
  registry exists (closing M-010/M-011 — the tool registry's hand-synced-3-4-ways twin problem),
  add a `docs-verify` (or `qa -t docs`) step that either generates the phase/tool inventory table
  directly from the registry, or diffs the registry's tool list against grep'd doc mentions and
  fails CI on mismatch. This is the only guard that makes M-029/M-031/M-032-style recurrence
  structurally impossible rather than just less likely. Flag as a follow-up work item for
  whichever plan implements M-011 — do not attempt to build it here, it has a hard dependency on
  code that doesn't exist yet.

---

## 5. Out of scope

- **Any CODE change** — restoring `psr4Validate` wiring, un-commenting `phpunitAnnotations`,
  wiring or deleting `toolStart`/`toolComplete`/`toolFailed`, creating the templated
  `update-deps.yml`, addressing `phploc`'s `set -e` exposure. This plan only prescribes what the
  doc text must say once each decision lands (see DANGER table, §3).
- **Deep semantic verification of individual rule behaviour** (e.g., does
  `ForbidNestedTernaryRule`'s doc paragraph exactly describe its logic) — the source audits
  explicitly scoped this as inventory/count-level only, not exhaustive; out of budget here too.
- **Docs independently verified ACCURATE** — do not touch: `docs/ci.md`, `docs/tools/packageType.md`,
  `docs/tools/requireApiOrInternal.md`, `docs/tools/sensitiveParameterUsage.md`,
  `CLAUDE/managed-source.md`, `CLAUDE/DefenceBeforeFix.md`, `templates/qaConfig-PHPStan-CLAUDE.md`,
  `templates/src-PHPStan-CLAUDE.md`, `configDefaults/README.md`, `qaConfig/README.md`,
  `phpstorm/README.md`, and every README.md/CLAUDE.md section not named in a WP above (Rector,
  PHP CS Fixer, Composer Require Checker, PHP Lint, Markdown Links, PHPArkitect's own dedicated
  section, Managed Source, SensitiveParameter usage check, Memory Configuration, Tool Runner
  System description).
- **M-082/M-083** — unverifiable without network access; no action taken or recommended.
- **M-044** (CI detection redundant in 2-3 places) — this is a code-deduplication finding
  (ARCH axis), not a doc-text correction; belongs to the bash-refactor plan.
- **M-028** (bash layer untested / no shellcheck CI gate) — PROCESS axis, belongs to the
  bash-refactor plan, not a doc fix.
- **Writing a new, fully-accurate `deploy-skills.bash` reference doc** to replace the archived
  `skills-deployment-system-2025-11.md` — flagged as a real gap (there is currently *no* accurate
  doc of the 705-line script's actual behaviour) but a full rewrite is a large, separate
  undertaking; this pass only stops the bleeding (archive + disclaimer per WP-D13). Recommend a
  follow-up plan item, not part of this WP set.
