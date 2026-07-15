# Documentation Rot Audit — Secondary Docs (Batch 1)

**Date**: 2026-07-15
**Scope**: `docs/*.md`, `docs/tools/*.md`, `docs/_config.yml`, `CLAUDE/branch-policy.md`, `CLAUDE/managed-source.md`, `CLAUDE/DefenceBeforeFix.md`, `CLAUDE/ANALYSIS/timing-schema-analysis.md`, `CLAUDE/Plan/` legacy loose files (`locking-system.md`, `skills-deployment-system-2025-11.md`, `worktree-git-env-fix.md`), `templates/qaConfig-PHPStan-CLAUDE.md`, `templates/src-PHPStan-CLAUDE.md`, `configDefaults/README.md`, `qaConfig/README.md`, `phpstorm/README.md`.
**Method**: six parallel read-only research passes, each reading every target doc in full and cross-checking every factual claim (paths, variable names, defaults, CLI flags, exit codes, execution order, thresholds) against the actual bash/PHP/YAML source — never doc-against-doc. Findings below are the synthesized, de-duplicated union of those passes. Evidence quoted as `file:line`. This is the audit report itself; no other file was modified.

---

## 1. Per-document verdict table

| Doc | Verdict | One-line rationale |
|---|---|---|
| `docs/ci.md` | ACCURATE | Every claim (ci.bash, workflow triggers, tee to var/qa/ci.log) verified against source. |
| `docs/pipeline.md` | DRIFTED | Phase-2/3 tool inventories miss 4 real tools; PSR-4 Validation step is a dead no-op; PHIVE-install description inaccurate. |
| `docs/configuration.md` | DRIFTED | Cascade example cites nonexistent `configDefaults/symfony/`; `phpqaQuickTests` effect undersold; config-file inventory stale. |
| `docs/platform-detection.md` | ROTTEN | Laravel (`artisan`) detection is entirely fabricated — no such check exists anywhere in code. |
| `docs/coding-standards.md` | DRIFTED | Wrong PHP-CS-Fixer ruleset name (`@PHP84Migration` vs actual `@PHP8x4Migration`); Rector section otherwise excellent. |
| `docs/github-actions.md` | DRIFTED | `update-deps.yml` copy instruction ships a broken, self-referential (`ref: php8.4`) workflow to consumers; Troubleshooting section gives stale "reproduce CI locally" advice that contradicts the doc's own newer section. |
| `docs/phpqa-tools.md` | DRIFTED | Core mechanics (retry semantics, config resolution, preflight) accurate; tool inventory missing `packageType`, `branchNamePolicy`, `phpArkitect`, `sensitiveParameterUsage`; stale line-number anchors. |
| `docs/tools/infection.md` | DRIFTED | Documented default Covered-MSI floor (90%) does not match shipped default (80%). |
| `docs/tools/packageType.md` | ACCURATE | Every claim verified line-for-line against source. |
| `docs/tools/phpstan.md` | DRIFTED | Default-on custom-rule list missing 6 rules; optional-rule count wrong (claims 10, actual 12). |
| `docs/tools/phpunit.md` | DRIFTED | "Fail on first error" / "no time limits" coverage claims describe behaviour the code comments show was deliberately reverted for CI, and misstate local-dev behaviour. |
| `docs/tools/requireApiOrInternal.md` | ACCURATE | Every claim verified against source, including rule identifiers and PHPArkitect boundary factory. |
| `docs/tools/sensitiveParameterUsage.md` | ACCURATE | Every claim verified against `SensitiveParameterUsageScanner.php` and the tool runner. |
| `docs/_config.yml` | DELETE-CANDIDATE | GitHub Pages confirmed disabled (`gh api .../pages` → 404); no workflow, no Jekyll scaffolding; orphaned since its single commit. |
| `CLAUDE/branch-policy.md` | DRIFTED | Enforcement mechanism/allow-list/CLI all match code, but the doc asserts a hardcoded default-branch fallback list (`main`, `master`, `develop`, ...) that does not exist — code deliberately has no such list. |
| `CLAUDE/managed-source.md` | ACCURATE | Every claim verified against `ManagedSourceGenerator`, `ManagedSourceDeployPlugin`, `bin/managed-source`, and covered by tests. |
| `CLAUDE/DefenceBeforeFix.md` | ACCURATE | 4-phase workflow reproduced near-verbatim in the real skill file; external URL reference is SPECULATIVE (unverifiable, no network access) but internally consistent. |
| `CLAUDE/ANALYSIS/timing-schema-analysis.md` | OBSOLETE-ARCHIVE | Point-in-time design review of an earlier plan draft; system now implemented; zero repo references to this file (true orphan); 4 of 5 concerns never acted on; borderline DELETE-CANDIDATE. |
| `CLAUDE/Plan/locking-system.md` | DRIFTED | Core locking/timing system faithfully implemented and in production use, but two specific claims are stale: git-tracking of `timing-data.json` was reversed, and `toolStart`/`toolComplete`/`toolFailed` are dead code (defined, never called). |
| `CLAUDE/Plan/skills-deployment-system-2025-11.md` | ROTTEN | Self-labeled "Proposal", never revised (1 commit); actual `deploy-skills.bash` diverged on source-directory layout and grew 10x beyond the doc's scope (hooks-daemon, git-hooks, PHPStan rule scaffolding, CLAUDE.md injection all undocumented). |
| `CLAUDE/Plan/worktree-git-env-fix.md` | OBSOLETE-ARCHIVE | Small, precise fix-plan; implemented exactly as described in the same commit, stable since; clean candidate for archival once a `Completed/` convention exists. |
| `templates/qaConfig-PHPStan-CLAUDE.md` | ACCURATE | Matches the real deploy mechanism (`deploy-skills.bash`) and consumer-project layout; correctly self-exempted in php-qa-ci's own repo. |
| `templates/src-PHPStan-CLAUDE.md` | ACCURATE | Same deploy mechanism; guardrail ("no PHPStan rules in src/") correctly scoped to consumer apps, not to php-qa-ci itself. |
| `configDefaults/README.md` | ACCURATE | Terse but not incorrect; doesn't flag that platform subfolders referenced elsewhere no longer exist (see DS-003). |
| `qaConfig/README.md` | ACCURATE | Matches actual self-QA config contents; corroborated by `docs/configuration.md`. |
| `phpstorm/README.md` | ACCURATE | All 3 referenced files exist exactly as named. |

**Verdict counts**: ACCURATE 11 · DRIFTED 10 · ROTTEN 2 · OBSOLETE-ARCHIVE 3 · DELETE-CANDIDATE 1 (note: `docs/_config.yml` is tagged both OBSOLETE-ARCHIVE and DELETE-CANDIDATE in the sub-report; counted once as DELETE-CANDIDATE, the stronger action) — total 27 documents audited.

---

## 2. Findings by severity

### CRITICAL

- **DS-001** — `docs/platform-detection.md:15` claims `**Laravel**: presence of \`artisan\``. Actual `detectPlatform()` (`includes/functions.inc.bash:6-13`) only checks `symfony.lock`; there is no `artisan` check, no `platformLaravel` constant (only `platformGeneric`/`platformSymfony` are declared), and a repo-wide search for `artisan`/`laravel` in `.bash` files found zero matches outside documentation. This fabrication is echoed in `docs/pipeline.md:47`, `CLAUDE.md`, and `README.md` — a doc-rot cluster, not an isolated typo. **Action**: remove Laravel from all four locations, or implement it if it's meant to exist.

- **DS-002** — `docs/pipeline.md:68` claims PSR-4 Validation performs real namespace/directory compliance checking. `includes/generic/psr4Validate.inc.bash` is a **0-byte empty file** — `runTool psr4Validate` sources it and does nothing, despite `psr4IgnoreListPath` being wired up in `setConfig.inc.bash:32-33`. **Action**: either the doc is describing a dead/removed feature (fix doc) or the tool was accidentally emptied (fix code) — needs a decision, flagging to the primary audit as a functional regression, not just a doc issue.

- **DS-003** — `docs/configuration.md:92-96` walks through a worked example citing `configDefaults/symfony/phpstan.neon` in the resolution cascade. `configDefaults/symfony/` does not exist anywhere in the repo (`find configDefaults -type d` → only `configDefaults` and `configDefaults/generic`). The cascade *mechanism* in `configPath()` (`includes/functions.inc.bash:66-78`) is correctly described in principle, but its only concrete example is fictional. Same root issue affects `configDefaults/README.md` (implicitly, MINOR) and is the underlying cause of DS-001's Symfony/Laravel confusion. **Action**: rewrite the example against a real file, or restore the symfony/ config tier if platform-specific configs were intended to exist.

- **DS-004** — `docs/github-actions.md:181-185` instructs consumers to `cp vendor/lts/php-qa-ci/.github/workflows/update-deps.yml .github/workflows/update-deps.yml`. That file hardcodes `ref: php8.4` (`.github/workflows/update-deps.yml:19-22`) — php-qa-ci's own dogfooding branch — with no templated/dynamic branch resolution (unlike `qa-autofix.yml`, which resolves `github.event.repository.default_branch`) and no `templates/github-actions/update-deps.yml` generic copy exists. Any consumer following this instruction ships a workflow whose every scheduled run fails at checkout. **Action**: create a generic templated version under `templates/github-actions/` (as already done for the other two workflows) and point the doc at that instead of the self-referential file.

- **DS-005** — `docs/github-actions.md:270-278` (Troubleshooting) tells users to reproduce CI locally with `CI=true vendor/bin/qa`. Per `includes/functions.inc.bash:262-289` (`detectReadOnly()`), `CI` only controls interactivity — the read-only gate that actually fails CI on Rector/CS-Fixer drift triggers on `GITHUB_ACTIONS=true` or `QA_READONLY=1`, not `CI`. The code's own comment explicitly names this exact confusion as a historical bug it fixed. Running the doc's suggested command locally executes in **writable** mode (fixers silently apply changes) so the local run "passes" while CI keeps failing — actively misleading mid-incident. This directly contradicts the same doc's own correct statement at `docs/github-actions.md:117-120`. **Action**: fix the Troubleshooting command to `QA_READONLY=1 vendor/bin/qa`.

- **DS-006** — `CLAUDE/Plan/locking-system.md:578-609` ("Git Tracking Rules") claims `timing-data.json` "SHOULD be tracked" / "MUST be tracked (committed to project git)" via a `.gitignore` negation pattern. Actual `includes/generic/lock.inc.bash:71-79` writes a `.gitignore` containing only `*` — nothing tracked. Commit `d211348` ("fix: Stop tracking QA runtime caches...") explicitly reversed this design because timing data varies per machine and created merge noise. A reader following the plan's "Phase 2: Project Adoption" (lines 969-979) would try to commit a file the system now deliberately excludes. **Action**: update or archive the plan doc; the design decision it documents was superseded.

- **DS-007** — `CLAUDE/Plan/locking-system.md:611-762` describes `toolStart()`/`toolComplete()`/`toolFailed()` as active hook points that populate the lock file's `tools` array and `current_tool` field, with a documented example of `displayLockStatus()` showing "Currently running: $currentTool". All three functions are fully implemented (`lock.inc.bash:401-508`) but have **zero call sites** anywhere in `includes/`, `bin/`, or `scripts/` — dead code. The `tools` array and `current_tool` field are therefore always empty, and the doc's example output can never actually occur. **Action**: either wire the hooks into `runTool` (matches original design intent) or strike the claim from the doc.

- **DS-008** — `CLAUDE/Plan/skills-deployment-system-2025-11.md:52-90` claims skills/agents source live at repo-root `php-qa-ci/skills/` and `php-qa-ci/agents/`. Neither directory exists; the real source tree is nested under `.claude/skills/` and `.claude/agents/` (confirmed in `scripts/deploy-skills.bash:17-22` and `src/ComposerPlugin/SkillsDeployPlugin.php:80`). Anyone using this doc to find "where do I add a new skill" looks in the wrong place entirely. **Action**: this doc is a "Proposal" (never marked Complete) that was superseded by a materially different implementation — treat as historical only, do not use as a how-to.

### MAJOR

- **DS-009** — `docs/pipeline.md` Phase 2/3 tool lists omit `packageType` (always-on, pipeline-failing, runs in `allLintingTools.inc.bash` between `composerChecks` and `phpStrictTypes`) and, in Phase 3, `branchNamePolicy` and `phpArkitect` alongside PHPStan (`allStaticAnalysisTools.inc.bash` actually runs all four). Same gap independently confirmed in `docs/phpqa-tools.md`, which is missing all four of `packageType`, `branchNamePolicy`, `phpArkitect`, `sensitiveParameterUsage` from its tool inventory, despite three of them having dedicated `docs/tools/*.md` pages it never links to. CLAUDE.md itself has the same gap for `packageType`/`branchNamePolicy` (though it does list `phpArkitect`/`sensitiveParameterUsage`), so this is a repo-wide documentation gap, not isolated to one file.

- **DS-010** — `docs/pipeline.md:58` describes PHIVE install as conditional-on-`phive.xml`-existing and simply installing PHARs. Actual `scripts/tool-install.bash` (sourced from `bin/qa:197`) makes `phive.xml` mandatory (exits 1 if missing), and PHARs are pre-committed to the repo (`vendor-phar/*.phar`) — the default `install` mode only verifies they exist; `phive install` itself only runs in `update`/`--force` (maintainer) modes. The doc also omits that this same step installs the isolated Rector composer sub-project (`tools/rector/`) via `composer install --no-dev` on first use.

- **DS-011** — `docs/configuration.md:39-42` describes `phpqaQuickTests` as running "only fast PHPQA tests." Actual effect (`allStaticAnalysisTools.inc.bash`, `allTestingTools.inc.bash`) is that setting it to `1` **entirely skips PHPStan, PHPUnit, and Infection** — not just faster tests, but full-phase skips including a static-analysis tool. The doc doesn't disambiguate this from the separate `phpUnitQuickTests` variable, which has a genuinely different, narrower effect (skip slow tests *within* PHPUnit).

- **DS-012** — `docs/tools/phpstan.md:50-58` ("Custom PHPStan Rules") lists 7 default-on rules; `rules-default.neon` actually registers 6 more (`ForbidNewDateTimeRule`, `ForbidEmptyLanguageConstructRule`, `ForbidLooseComparisonRule`, `ForbidDeprecatedSerializableRule`, `ForbidNestedTernaryRule`, `RequireRuleIdentifierConstantRule`) that don't appear anywhere on the page. `docs/tools/phpstan.md:66-69` also claims "10 additional opt-in rules" for the optional tier; ground truth (`rules-optional.neon` rules: block + services: block, plus 4 Symfony-specific rules) totals 12. `README.md:277` points readers to this doc as authoritative for the optional-rule count, so the drift propagates by reference.

- **DS-013** — `docs/tools/phpunit.md:87-92` ("Config Changes When Generating Coverage") claims PHPUnit "will fail on the first error rather than run the full suite" and "will not enforce any time limits" when coverage is on. Actual `includes/generic/phpunit.inc.bash`: the `--stop-on-*` flags only apply in the separate `phpUnitIterativeMode` branch — the CI+coverage branch has a code comment reading `# Note: Removed stop-on-failure flags to allow full test runs in CI`, i.e. this exact documented behaviour was deliberately reverted. Additionally, `--enforce-time-limit` is only skipped in the **CI**+coverage branch; the local-dev+coverage branch still enforces it, so a developer running `phpUnitCoverage=1 vendor/bin/qa` locally will hit time-limit failures the doc says can't happen.

- **DS-014** — `CLAUDE/branch-policy.md:52-54` claims default-branch detection falls back to "common candidates like `main`, `master`, `develop`, `DumbItDown`, `NewCheckout`" when git detection fails. Actual `includes/generic/branchNamePolicy.inc.bash:118-126` explicitly does the opposite — a comment reads "NO hardcoded list — that's overfitting" — and on detection failure it only emits a warning telling the user to configure `qaConfig/branchNamePolicy.yaml`. A project with a real default branch not auto-detectable (e.g. broken `origin/HEAD`) is NOT exempted as the doc promises, and CI fails with "DISALLOWED BRANCH" while the doc suggests it should have passed.

- **DS-015** — `CLAUDE/Plan/skills-deployment-system-2025-11.md`'s entire ~70-line inline example of `deploy-skills.bash` covers only "copy skills, copy agents, add `.claude/` to `.gitignore`." The real script (705 lines, 22 commits since) additionally implements hooks-daemon detection with monorepo parent-dir lookup, `php-qa-ci__` hook-name migration + settings.json rewriting, git-hooks deployment with signature-based safe redeploy, hooks-daemon YAML handler enforcement, automatic classic-hook removal, PHPStan rule scaffolding + composer.json autoload-dev injection, and root CLAUDE.md `<phpqaci>` block injection — none of which the doc anticipates.

### MINOR

- **DS-016** — `docs/coding-standards.md:21` names the PHP CS Fixer ruleset `@PHP84Migration`; actual key in `configDefaults/generic/php_cs.php:27` is `@PHP8x4Migration`. Not cosmetic — a literal copy-paste into a custom config would fail to match a real ruleset name.

- **DS-017** — `docs/configuration.md:76-86` lists `configDefaults/generic/` files but omits `php_cs_finder.php`, `phparkitect.php`, `phparkitect-consumer-api-boundary.php`, `phparkitect-rules-default.php`, and `phparkitect-rules-optional*.php`, all present on disk — the PHPArkitect config additions were never reflected here.

- **DS-018** — `docs/phpqa-tools.md` has three stale `#Lxx` line-number anchors into `includes/functions.inc.bash` (`runTool` off by 10 lines, `detectPlatform` off by 1, `checkForUncommittedChanges` off by 16) — all still resolve to the file, just land on the wrong line.

- **DS-019** — `docs/tools/infection.md:11-18` implies `phpUnit.configDir` must be *added* to override the default `infection.json`; the shipped default already contains `"phpUnit": {"configDir": "./"}` — the doc should describe changing an existing default, not adding an absent one.

- **DS-020** — `docs/github-actions.md:176` lists 4 PHARs installed via PHIVE, omitting `phparkitect/arkitect` (added to `phive.xml` in commit `00f2d08`, after this doc line was written). `docs/github-actions.md:100`/`:22` tell users to select "PHP QA Pipeline" as a required branch-protection check; the actual job names are "Detect PHP Version", "PHP QA (8.4)" (dynamic), and "Coverage Report" — "PHP QA Pipeline" is only the workflow name, not a selectable check-run name (this inaccuracy is also baked into the template's own header comment, so it isn't doc-vs-code drift specifically, just a shared inaccuracy). `docs/github-actions.md:218-226` overstates cache-key precision: the Composer cache key does not include the PHP version (only the combined `var/qa/cache`+`vendor-phar` cache does), and there is no separate "PHPStan cache" cache block as implied.

- **DS-021** — `CLAUDE/ANALYSIS/timing-schema-analysis.md` cites the plan doc it reviews by fixed line numbers ("line 680-683", "line 257-260", etc.) that don't correspond to anything in the current 1083-line `locking-system.md` — confirms it was written against a stale draft and never reconciled. Of its 5 concerns, path normalization (#1) is now partially addressed (a `normalizePathForKey()` exists and is wired into `calculateEta()`) but the deeper cross-platform/case/symlink issues it raised remain unaddressed; concerns #2 (10-sample retention), #3 (20% buffer truncation), and #4 (hardcoded 30s file ETA) were never acted on at all.

- **DS-022** — `configDefaults/README.md` makes no false claim itself, but its silence combined with `docs/configuration.md`/`docs/platform-detection.md` describing platform-specific config subdirectories (which don't exist — see DS-003) leaves a reader to independently discover that only `generic/` exists.

### INFO

- **DS-023** — `docs/ci.md`, `docs/tools/packageType.md`, `docs/tools/requireApiOrInternal.md`, `docs/tools/sensitiveParameterUsage.md`, `CLAUDE/managed-source.md`, `CLAUDE/DefenceBeforeFix.md`, `templates/qaConfig-PHPStan-CLAUDE.md`, `templates/src-PHPStan-CLAUDE.md`, `configDefaults/README.md`, `qaConfig/README.md`, `phpstorm/README.md` — all confirmed accurate, no findings beyond the cross-references noted above. No broken internal markdown links were found in any of the 27 audited documents (all relative paths and anchors resolve).

- **DS-024** — `docs/github-actions.md`'s newer "Inline-Barrier"/`qa-autofix.yml` section (added by commits `7e760fb`/`dd9dd5e`) matches the code almost word-for-word, including comment text — the best-maintained part of that doc. It doesn't yet mention the "verify deploy keys cover every private dep" fail-fast guard added in the same commit that most recently touched the doc — a same-commit omission, not old rot.

- **DS-025** — `CLAUDE/DefenceBeforeFix.md:7` references an external URL (`https://ltscommerce.dev/articles/defence-before-fix-static-analysis`) that cannot be verified without network access. **SPECULATIVE**: treat as unconfirmed, though it's referenced consistently by both the doc and the corresponding skill file, so at minimum internally consistent.

- **DS-026** — `docs/_config.yml` (`theme: jekyll-theme-slate`) has exactly one commit ever, no GitHub Pages workflow, no Jekyll scaffolding (no `Gemfile`, `index.md`, `_layouts/`), and `gh api repos/.../pages` returns 404 — confirmed dead configuration with no active consumer.

- **DS-027** — No `CLAUDE/Plan/Completed/` archival convention currently exists in php-qa-ci itself (the vendored `hooks-daemon` dependency documents this convention for its own repo, but php-qa-ci hasn't adopted a numbered-plan structure yet). `CLAUDE/Plan/worktree-git-env-fix.md` and `CLAUDE/Plan/locking-system.md` (once DS-006/DS-007 are resolved) are the cleanest candidates to become `NNNNN-*` archived plans if/when that convention is adopted; `skills-deployment-system-2025-11.md` is better treated as a superseded historical proposal than an archived "done" plan, given how far the implementation diverged.

---

## 3. Redundancy / contradiction map

| Claim | Doc A | Doc B | Code (ground truth) | Which side matches code |
|---|---|---|---|---|
| Platform list (Symfony/Laravel/generic) | `docs/platform-detection.md:15` | `docs/pipeline.md:47`, `CLAUDE.md`, `README.md` | `includes/functions.inc.bash:6-13` — Symfony/generic only | **Neither** — all four docs share the same fabricated Laravel claim (DS-001), a doc-rot cluster with a common origin, not independent drift. |
| Tool inventory completeness (packageType, branchNamePolicy, phpArkitect, sensitiveParameterUsage) | `docs/pipeline.md`, `docs/phpqa-tools.md` | `CLAUDE.md` "Main Tool Execution Phases" | `includes/generic/allLintingTools.inc.bash`, `allStaticAnalysisTools.inc.bash` | **CLAUDE.md is closer** (lists `phpArkitect`/`sensitiveParameterUsage` as items 11-12) but still misses `packageType`/`branchNamePolicy`; both `docs/pipeline.md` and `docs/phpqa-tools.md` are missing all four. |
| Reproducing CI locally | `docs/github-actions.md:270-278` (Troubleshooting, stale) | `docs/github-actions.md:117-120` (same file, newer section) | `includes/functions.inc.bash:262-289` `detectReadOnly()` | The doc's own newer section (`:117-120`) is correct; the Troubleshooting section contradicts it (DS-005) — an intra-document contradiction, not cross-doc. |
| PHPStan optional-rule count | `docs/tools/phpstan.md:66-69` ("10 additional") | `README.md:277` (points to phpstan.md as authoritative, doesn't independently claim a number) | `rules-optional.neon` + `rules-optional-symfony.neon` = 12 | Code; README inherits the drift by reference rather than contradicting it. |
| `qaConfig/PHPStan/Rules/` guidance ("rules MUST NOT live in src/") | `templates/src-PHPStan-CLAUDE.md:3,9` (consumer-facing template) | This repo's own structure | `src/PHPStan/Rules/` (33 files), `composer.json` requires phpstan/phpstan-strict-rules etc. in `require`, not `require-dev` | **Not a contradiction** — the template is deliberately consumer-scoped guidance; php-qa-ci itself is the framework authoring the rules, so it is correctly self-exempt. Flagged as INFO only. |
| Timing-data git tracking | `CLAUDE/Plan/locking-system.md:578-609` | (none — no other doc discusses this) | `includes/generic/lock.inc.bash:71-79`, commit `d211348` | Code — the design was deliberately reversed after the plan was written (DS-006). |

No cases were found where `docs/` and `CLAUDE.md`/`README.md` state materially different facts that both claim to be authoritative and diverge from each other independently of code — the pattern throughout is that all docs share a common ancestor claim that then drifted from code (Laravel, tool inventories), or one doc has an internal contradiction between its own sections (github-actions.md), rather than two docs actively disagreeing with each other while one stays correct.

---

## 4. Coverage statement

**Covered** (27 documents, full read + code cross-check): `docs/ci.md`, `docs/coding-standards.md`, `docs/configuration.md`, `docs/github-actions.md`, `docs/phpqa-tools.md`, `docs/pipeline.md`, `docs/platform-detection.md`, `docs/_config.yml`, `docs/tools/infection.md`, `docs/tools/packageType.md`, `docs/tools/phpstan.md`, `docs/tools/phpunit.md`, `docs/tools/requireApiOrInternal.md`, `docs/tools/sensitiveParameterUsage.md`, `CLAUDE/branch-policy.md`, `CLAUDE/managed-source.md`, `CLAUDE/DefenceBeforeFix.md`, `CLAUDE/ANALYSIS/timing-schema-analysis.md`, `CLAUDE/Plan/locking-system.md`, `CLAUDE/Plan/skills-deployment-system-2025-11.md`, `CLAUDE/Plan/worktree-git-env-fix.md`, `templates/qaConfig-PHPStan-CLAUDE.md`, `templates/src-PHPStan-CLAUDE.md`, `configDefaults/README.md`, `qaConfig/README.md`, `phpstorm/README.md`.

**Skipped / out of scope for this batch**:
- `CLAUDE.md` and `README.md` themselves were read only as cross-reference targets to check contradiction claims, not independently line-by-line audited (per instructions: doc-vs-doc is never the primary check, and these are presumably covered by a separate primary audit).
- External URLs (e.g. the `ltscommerce.dev` reference in `DefenceBeforeFix.md`) could not be verified — no network access from the research agents; marked SPECULATIVE (DS-025).
- JetBrains help-doc external links in `phpstorm/README.md` were not live-fetched; assumed stable per standard JetBrains documentation conventions.
- Deep functional testing of `psr4Validate.inc.bash` being empty (DS-002) was not performed beyond confirming the file is 0 bytes and is sourced unconditionally by `runTool` — whether this is a recent regression or long-standing was not traced further back in git history within this audit's time budget; flagged as CRITICAL for the primary audit to investigate as a possible functional bug, not purely a doc issue.
- The audit did not exhaustively diff every single PHP CS Fixer rule or every PHPStan rule class against its doc's prose description of *behaviour* (only presence/absence and naming) — deep semantic verification of rule logic (e.g., does `ForbidNestedTernaryRule` do exactly what its doc paragraph says) was spot-checked, not exhaustive, for `requireApiOrInternal.md` and `sensitiveParameterUsage.md` (both ACCURATE) but only inventory-level for `phpstan.md` (DRIFTED on inventory, not on per-rule behaviour description).
- `CLAUDE/Plan/00001-repo-audit-and-tidy/` itself (the active plan this audit lives under) was not audited — it is out of scope as the container for this work, not a target doc.

