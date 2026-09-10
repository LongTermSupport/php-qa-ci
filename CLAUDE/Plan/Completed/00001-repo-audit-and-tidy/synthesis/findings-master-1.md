# Findings Master Register — php-qa-ci Repo Audit (v1)

<!-- Authored by the synthesis agent (opus); persisted by the coordinator because the
     hooks-daemon policy requires subagents to return content as text rather than
     write report files. Content verbatim from the agent, reviewed by Fable. -->

**Date:** 2026-07-15 · **Inputs:** DC-*, DS-*, BQ-*, BS-*, AR-*, php-src-map, FS-* (FS notes win over any conflicting agent report).

**Verify legend:** FABLE-VERIFIED (coordinator confirmed against code) · AGENT-EVIDENCED (agent gave file:line, not re-checked) · SPECULATIVE (unverified/unverifiable). **Axis:** DOCS · BASH-CORE (`bin/qa`+`includes/**`) · BASH-SCRIPTS (`scripts/`,`git-hooks/`,`ci.bash`,`bin/*` stubs) · ARCH · PHP · PROCESS.

## 1. Master findings table

| M-ID | Sev | Title | Sources | Verify | Files (evidence) | Axis |
|---|---|---|---|---|---|---|
| M-001 | CRITICAL | PSR-4 validation silent no-op — wiring fragment 0 bytes (emptied 2023-12-18, e0240dc); `-t psr4`/Phase-2 report success validating nothing | FS-008=BQ-001=AR-005=DC-001=DS-002+php-src-map | FABLE-VERIFIED | `includes/generic/psr4Validate.inc.bash`(0B); `allLintingTools.inc.bash:7`; dead readarray `setConfig.inc.bash:32-33` | BASH-CORE |
| M-002 | CRITICAL | PHPUnit annotations check silent no-op — fragment 100% commented; `bin/phpunit-check-annotation`+`CheckAnnotations.php` never invoked | FS-004=BQ-007=AR-006=DC-002=DS-009+php-src-map | FABLE-VERIFIED | `includes/generic/phpunitAnnotations.inc.bash`; `allLintingTools.inc.bash:44` | BASH-CORE |
| M-003 | CRITICAL | Strict-types gate never scans `.php` — `find` precedence: `-exec` suppresses default `-print` on bare `*.php`; grep runs only on `.phtml` | FS-010 | FABLE-VERIFIED (repro) | `includes/generic/phpStrictTypes.inc.bash:4-7` | BASH-CORE |
| M-004 | MAJOR | `phpStrictTypes` ignores CI+read-only — interactive `read` no CI guard, `sed -i` no `qaReadOnly` guard, unquoted `$f`/`find $d` | BQ-002=AR-007 | AGENT-EVIDENCED | `phpStrictTypes.inc.bash:11,14` | BASH-CORE |
| M-005 | MAJOR | Laravel/`artisan` platform detection fabricated estate-wide; `detectPlatform()` only checks `symfony.lock` | DS-001 cluster+DC | FABLE-VERIFIED | `docs/platform-detection.md:15`,`docs/pipeline.md:47`,`CLAUDE.md`,`README.md` vs `functions.inc.bash:6-13` | DOCS |
| M-006 | MAJOR | Config "cascade" cites nonexistent `configDefaults.inc.bash`+platform dirs (`configDefaults/symfony/`); invents a layer | DC-003=DS-003(+DS-022) | FABLE-VERIFIED | `CLAUDE.md:124-129`,`docs/configuration.md:92-96` vs `functions.inc.bash:66-78` | DOCS |
| M-007 | MAJOR | `update-deps.yml` copy ships self-referential `ref: php8.4` to consumers — scheduled runs fail at checkout; no templated copy | DS-004 | AGENT-EVIDENCED | `docs/github-actions.md:181-185`; `.github/workflows/update-deps.yml:19-22` | DOCS |
| M-008 | MAJOR | Troubleshooting `CI=true vendor/bin/qa` runs writable (gate keys on `GITHUB_ACTIONS`/`QA_READONLY`); local "passes" while CI fails | DS-005 | FABLE-VERIFIED | `docs/github-actions.md:270-278` vs `functions.inc.bash:262-289` | DOCS |
| M-009 | MAJOR | Derive-before-override class — `setConfig`(`bin/qa:162`) derives from project vars before `qaConfig.inc.bash`(`:177`); useInfection/phpUnitCoverage stale (live), MSI frozen | FS-011=AR-001=AR-002 | FABLE-VERIFIED | `setConfig.inc.bash:68-72,81-83`; `bin/qa:162,177` | ARCH/BASH-CORE |
| M-010 | MAJOR | No shared runner driver — retry loop 11× in 5 variants, divergent crash codes/log policy/errexit idioms | BQ§3=AR-004 | AGENT-EVIDENCED | `includes/generic/*.inc.bash` | ARCH |
| M-011 | MAJOR | Tool registry hand-synced in 3–4 structures (usage/PATH arrays/alias map) with now-false "VERIFIED" comments | FS-009=AR-010 | FABLE-VERIFIED | `includes/options.inc.bash:24-60,85-114,164-195` | ARCH/BASH-CORE |
| M-012 | MAJOR | `bin/qa:229-230` `exitCode=$?` dead under `set -e` — non-zero runTool aborts at :229, :230 captures only 0 | FS-002 | FABLE-VERIFIED | `bin/qa:229-230` | BASH-CORE |
| M-013 | MAJOR | `IFS='|||'` is char-class (==`|`) not 3-char delim; `locked_commit` always empty, garbled SHA diagnostic | BS-001 | FABLE-VERIFIED (repro) | `git-hooks/pre-commit-check-vendor-uncommitted:159-161` (cf :143-144) | BASH-SCRIPTS |
| M-014 | MAJOR | deploy-skills register-then-undo window — Phase2 registers hooks gated only on `-d HOOKS_SOURCE`; interruption leaves dangling registrations | BS-002 | FABLE-VERIFIED | `deploy-skills.bash:131` vs `:214`,`:490-575` | BASH-SCRIPTS |
| M-015 | MAJOR | deploy-skills overwrites consumer skills/agents unconditionally (`rm -rf`+`cp`) — destroys local edits every update | BS-003 | AGENT-EVIDENCED | `deploy-skills.bash:106-108,125` | BASH-SCRIPTS |
| M-016 | MAJOR | Overwrite-protection 3 incompatible ways (signature/prompt/none) across consumer-write sites; no shared helper | BS-003=BS-005=BS-007 | AGENT-EVIDENCED | `deploy-skills.bash:342-354` vs `setup-claude-qa-agent.bash:44-56` vs `install-github-actions.bash:48-56` | BASH-SCRIPTS/ARCH |
| M-017 | MAJOR | `setup-branch-protection.bash` wholesale `PUT` — no GET/merge/diff; silently drops unmodeled checks/restrictions | BS-005 | AGENT-EVIDENCED | `setup-branch-protection.bash:112-118` | BASH-SCRIPTS |
| M-018 | MAJOR | `setup-claude-qa-agent.bash` hardcodes 4-up vendor path while own `detect_qa_binary()` reads bin-dir dynamically | BS-006 | AGENT-EVIDENCED | `setup-claude-qa-agent.bash:9` vs `:11-42` | BASH-SCRIPTS |
| M-019 | MAJOR | `setup-claude-qa-agent.bash` overwrites agent file unconditionally (`cat >`), unlike prompting sibling | BS-007 | AGENT-EVIDENCED | `setup-claude-qa-agent.bash:44-56` | BASH-SCRIPTS |
| M-020 | MAJOR | deploy-skills self-contradicts daemon version — "not detected" clone `v2.2.0` vs YAML path assumes `v3.9.0+` | BS-008 | AGENT-EVIDENCED | `deploy-skills.bash:587` vs `:390,410` | BASH-SCRIPTS |
| M-021 | MAJOR | `installUpdateInfection.bash` fully dead, pinned Infection `0.9.0` (PHIVE now `^0.34`), downloads PHAR but never verifies sig | BS-004 | FABLE-VERIFIED (grep) | `installUpdateInfection.bash:14,36-39,44-47` | BASH-SCRIPTS |
| M-022 | MAJOR | Dead functions ~90 ln — `checkForUncommittedChanges()`(live git add/commit path+own bug) + `phpunitReRunFailedOrFull()`, zero callers | FS-001=BQ-005 | FABLE-VERIFIED (grep) | `functions.inc.bash:108-174,176-209` | BASH-CORE |
| M-023 | MAJOR | Dead per-tool lock/timing ~100 ln — `toolStart/Complete/Failed` never called; `recordCommandTiming` keys empty `tool` → full-run timings never recorded; doc calls them active | BQ-006=AR-009=DS-007 | FABLE-VERIFIED (grep) | `lock.inc.bash:408-508,538`; `locking-system.md:611-762` | BASH-CORE |
| M-024 | MAJOR | `runTool packageType` bypasses `runToolGuarded` — failing packageType `exit 1`s whole run in aggregate mode | BQ-003 | AGENT-EVIDENCED | `allLintingTools.inc.bash:23` | BASH-CORE |
| M-025 | MAJOR | Unquoted `${array[@]}` to tools (SC2068) ~8 files — safe only via `IFS=$'\n\t'`; glob still active; invariant revoked in infection | BQ-004 | AGENT-EVIDENCED | `phpstan.inc.bash:59,61,75`,`phpunit.inc.bash`,`phpCsFixer.inc.bash`,`phploc.inc.bash:2`,`twigLint.inc.bash:8`,`yamlLint.inc.bash:5`,`setPaths.inc.bash:15-16` | BASH-CORE |
| M-026 | MAJOR | `eval` on tool invocation — phpstan crash path evals `$pathsStringArray`; phpLint launders array via `eval 'echo'`; injection surface | BQ-008 | AGENT-EVIDENCED | `phpstan.inc.bash:75`,`phpLint.inc.bash:1` | BASH-CORE |
| M-027 | MAJOR | `composerChecks` mutates in read-only — `composer normalize`/`dump-autoload` rewrite tracked files, no `qaReadOnly` gate | AR-008 | AGENT-EVIDENCED | `composerChecks.inc.bash` | BASH-CORE |
| M-028 | MAJOR | Bash layer untested — 1/31 files tested; no bats/shellcheck/e2e gate; a `-t <tool>` test + shellcheck would catch M-001/2/3 | BQ§6=AR§7+php-src-map | AGENT-EVIDENCED | `tests/Large/Infection/InfectionDiffModeTest.php` only | PROCESS |
| M-029 | MAJOR | PHPStan rule undercount — always-on list omits ~8 rules; opt-in says 10, actual 12 (6+2 services+4 symfony) | DC-007=DC-008=DS-012 | AGENT-EVIDENCED | `README.md:197-209,242-245`,`docs/tools/phpstan.md:50-58,66-69` vs `rules-default.neon`,`rules-optional*.neon` | DOCS |
| M-030 | MAJOR | README "three Composer plugins"; four registered (`ManagedSourceDeployPlugin` omitted) | DC-009+php-src-map | AGENT-EVIDENCED | `README.md:411-415` vs `composer.json extra.class` | DOCS |
| M-031 | MAJOR | Phase numbering broken (Phase4 restarts 11/12); `branchNamePolicy` (always-on) in no phase list | DC-010=FS-004=DS-009 | FABLE-VERIFIED | `CLAUDE.md:92-101` vs `allStaticAnalysisTools.inc.bash:1-6` | DOCS |
| M-032 | MAJOR | `packageType` (always-on Phase-2, own bin+doc) absent from root docs + pipeline/phpqa-tools | DC-011=DS-009 | AGENT-EVIDENCED | `allLintingTools.inc.bash:17-24`; `docs/tools/packageType.md` | DOCS |
| M-033 | MAJOR | Locking+timing subsystem (can abort a run) undocumented in root Preflight | DC-012=FS-004 | FABLE-VERIFIED | `bin/qa:208-219` vs `CLAUDE.md:29-70` | DOCS |
| M-034 | MAJOR | read-only/aggregate/`--json` modes undocumented; "Fail-fast design" claim now half-true | DC-013=FS-004 | FABLE-VERIFIED | `bin/qa:14-25,97-122` | DOCS |
| M-035 | MAJOR | `phpqaQuickTests` undersold — `1` skips PHPStan+PHPUnit+Infection entirely; not disambiguated from `phpUnitQuickTests` | DS-011 | AGENT-EVIDENCED | `docs/configuration.md:39-42` | DOCS |
| M-036 | MAJOR | `docs/tools/phpunit.md` coverage claims describe reverted behaviour ("fail on first error"/"no time limits") | DS-013 | AGENT-EVIDENCED | `docs/tools/phpunit.md:87-92` vs `phpunit.inc.bash` | DOCS |
| M-037 | MAJOR | `branch-policy.md` asserts hardcoded default-branch fallback list; code explicitly rejects one | DS-014 | AGENT-EVIDENCED | `branch-policy.md:52-54` vs `branchNamePolicy.inc.bash:118-126` | DOCS |
| M-038 | MAJOR | `locking-system.md` says commit `timing-data.json`; design reversed (d211348), lock gitignores `*` | DS-006 | AGENT-EVIDENCED | `locking-system.md:578-609` vs `lock.inc.bash:71-79` | DOCS |
| M-039 | MAJOR | `skills-deployment-system-2025-11.md` rotten — wrong source dirs, real script grew 10× | DS-008=DS-015 | AGENT-EVIDENCED | `skills-deployment-system-2025-11.md:52-90` vs `deploy-skills.bash:17-22` | DOCS |
| M-040 | MINOR | PHIVE-install names nonexistent `scripts/phive-install.bash` (real `tool-install.bash`, unconditional, requires phive.xml); frames mandatory step as optional | FS-003=DC-006=DS-010 | FABLE-VERIFIED | `CLAUDE.md:67`,`docs/pipeline.md:58` vs `bin/qa:197`,`tool-install.bash:46-49` | DOCS |
| M-041 | MINOR | Documented `phpUnitCoverage` default `0`; real `1` | DC-004=AR-013=FS-011 | FABLE-VERIFIED | `CLAUDE.md:146` vs `setConfig.inc.bash:54` | DOCS |
| M-042 | MINOR | CLAUDE.md claims PHP 7.4+; composer.json `^8.3`; README says 8.3+ | DC-005 | FABLE-VERIFIED | `CLAUDE.md:316-319` vs `composer.json:7` | DOCS |
| M-043 | MINOR | `skipUncommittedChangesCheck` documented live but only read by dead M-022 function | FS-001=FS-011 | FABLE-VERIFIED | `CLAUDE.md` Key Config vs `functions.inc.bash:108-174` | DOCS |
| M-044 | MINOR | CI detected/derived in 2–3 places; redundant, drift risk (no live bug) | AR-003=FS-011 | FABLE-VERIFIED | `bin/qa:81-95`,`setConfig.inc.bash:96-100` | ARCH |
| M-045 | MINOR | composerRequireChecker ~60 commented-out auto-fix lines | BQ-012 | AGENT-EVIDENCED | `composerRequireChecker.inc.bash:~40-95` | BASH-CORE |
| M-046 | MINOR | Dead TRAVIS/`phpenv config-rm` branch | BQ-010 | AGENT-EVIDENCED | `allTestingTools.inc.bash:16-19` | BASH-CORE |
| M-047 | MINOR | phpunit coverage uses `$phpBinPath` directly, bypassing `phpNoXdebug` → `phpqaMemoryLimit` not applied to heaviest run | AR-011 | AGENT-EVIDENCED | `phpunit.inc.bash`; `functions.inc.bash:94` | BASH-CORE |
| M-048 | MINOR | composerChecks unquoted `$(which composer)`×4 (SC2046); set+e/-e balance carries "won't fail" | BQ-009 | AGENT-EVIDENCED | `composerChecks.inc.bash:5,20,32,40` | BASH-CORE |
| M-049 | MINOR | branchNamePolicy `rm -f /tmp/branchNamePolicy.*.err` globs shared /tmp; err log never surfaced | BQ-011 | AGENT-EVIDENCED | `branchNamePolicy.inc.bash:261` | BASH-CORE |
| M-050 | MINOR | lock.inc.bash process-sub `tee` logging race (post-exit output lost) | BQ-013 | AGENT-EVIDENCED | `lock.inc.bash:110` | BASH-CORE |
| M-051 | MINOR | yamlLint typo `yamlLintExistCode`; no guard `config/` exists | BQ-014 | AGENT-EVIDENCED | `includes/symfony/yamlLint.inc.bash` | BASH-CORE |
| M-052 | MINOR | SC2155 (59 hits) masks exit status; sweep lock/timing | BQ-015 | AGENT-EVIDENCED | `lock.inc.bash`,`timing.inc.bash` | BASH-CORE |
| M-053 | MINOR | `bin/qa` composer-proxy self-parse fragile — regex keyed to literal `php-qa-ci` in quotes; breaks on fork/rename | FS-005 | FABLE-VERIFIED | `bin/qa:31` | BASH-CORE |
| M-054 | MINOR | `docs/coding-standards.md` ruleset `@PHP84Migration`; actual `@PHP8x4Migration` | DS-016 | AGENT-EVIDENCED | `docs/coding-standards.md:21` vs `php_cs.php:27` | DOCS |
| M-055 | MINOR | `docs/configuration.md` omits all PHPArkitect configs present on disk | DS-017 | AGENT-EVIDENCED | `docs/configuration.md:76-86` vs `configDefaults/generic/` | DOCS |
| M-056 | MINOR | `docs/phpqa-tools.md` 3 stale `#Lxx` anchors into functions.inc.bash | DS-018 | AGENT-EVIDENCED | `docs/phpqa-tools.md` | DOCS |
| M-057 | MINOR | `docs/tools/infection.md` implies add `phpUnit.configDir` (already default); also default Covered-MSI 90% vs shipped 80% | DS-019+DS | AGENT-EVIDENCED | `docs/tools/infection.md:11-18` vs `infection.json` | DOCS |
| M-058 | MINOR | `docs/github-actions.md` staleness — 4 PHARs (omits phparkitect); wrong check-run name; cache-key overstated | DS-020 | AGENT-EVIDENCED | `docs/github-actions.md:176,100,22,218-226` | DOCS |
| M-059 | MINOR | README Docs list omits packageType.md + requireApiOrInternal.md | DC-014 | AGENT-EVIDENCED | `README.md:429-433` vs `docs/tools/` | DOCS |
| M-060 | MINOR | Orphaned unregistered hook `php-qa-ci__check-vendor-uncommitted.py` not documented, not wired | DC-015 | AGENT-EVIDENCED | `.claude/hooks/php-qa-ci__check-vendor-uncommitted.py` | DOCS/PROCESS |
| M-061 | MINOR | `docs/_config.yml` dead — no Pages workflow, `gh api …/pages`→404 | DS-026 | AGENT-EVIDENCED | `docs/_config.yml` | DOCS |
| M-062 | MINOR | `timing-schema-analysis.md` obsolete — reviews stale draft, 0 repo refs, 4/5 concerns never acted | DS-021 | AGENT-EVIDENCED | `CLAUDE/ANALYSIS/timing-schema-analysis.md` | DOCS |
| M-063 | MINOR | `install-github-actions.bash` `check_php_version()` computed, never used | BS-013 | AGENT-EVIDENCED | `install-github-actions.bash:64-85,119` | BASH-SCRIPTS |
| M-064 | MINOR | `install-github-actions.bash` interpolates path into single-quoted inline PHP, no escaping | BS-014 | AGENT-EVIDENCED | `install-github-actions.bash:71-74` | BASH-SCRIPTS |
| M-065 | MINOR | `setup-branch-protection.bash` predictable uncleaned `/tmp/protection-result.txt`; SC2015+useless-cat | BS-015=BS-016 | AGENT-EVIDENCED | `setup-branch-protection.bash:116,112-118` | BASH-SCRIPTS |
| M-066 | MINOR | `tool-install.bash` `eval "phive … $TRUST_KEYS_ARG"` (no live injection but eval pattern) | BS-017 | AGENT-EVIDENCED | `tool-install.bash:107,110` | BASH-SCRIPTS |
| M-067 | MINOR | `tool-install.bash:63` `which phive 1>&2` prints path to stderr on success | BS-018 | AGENT-EVIDENCED | `tool-install.bash:63` | BASH-SCRIPTS |
| M-068 | MINOR | git-hook unquoted `${repo_dir#$PROJECT_ROOT/}` (SC2295) | BS-019 | AGENT-EVIDENCED | `pre-commit-check-vendor-uncommitted:92` | BASH-SCRIPTS |
| M-069 | MINOR | deploy-skills `rm -rf "$SKILLS_TARGET/$skill_name"` (SC2115); add `${SKILLS_TARGET:?}` | BS-009 | AGENT-EVIDENCED | `deploy-skills.bash:106` | BASH-SCRIPTS |
| M-070 | MINOR | ci.bash/installUpdateInfection — `cd $DIR` unquoted, `$0 $@` banner (SC2145), dead `standardIFS` | BS-010=BS-011=BS-012 | AGENT-EVIDENCED | `ci.bash:3,7,11,25` | BASH-SCRIPTS |
| M-071 | INFO | 4 redirect stubs 8/11 lines identical | BS-020 | AGENT-EVIDENCED | `bin/{composer-require-checker,infection,php-cs-fixer,phpstan}` | BASH-SCRIPTS/ARCH |
| M-072 | INFO | deploy-skills 705-line monolith, bash+5 python heredocs, 7 phases, 0 tests | BS-021 | AGENT-EVIDENCED | `deploy-skills.bash` | BASH-SCRIPTS/ARCH |
| M-073 | INFO | deploy-skills bare `python3` unguarded — `set -e` aborts mid-deploy (reinforces M-014) | BS-022 | AGENT-EVIDENCED | `deploy-skills.bash:145,232,516,639` | BASH-SCRIPTS |
| M-074 | INFO | Unnamespaced globals leak between sourced tools (no `local`) | AR-012 | AGENT-EVIDENCED | `includes/generic/*.inc.bash` | ARCH/BASH-CORE |
| M-075 | INFO | `phpNoXdebug` clobbers caller `set -x`; `archiveToolLog` leaves uncleaned `.warned_high_count_$$` markers | FS-007 | FABLE-VERIFIED | `functions.inc.bash:92-96` | BASH-CORE |
| M-076 | INFO | jq-tempfile+atomic-mv block recurs 5× in lock.inc.bash | BQ§3 | AGENT-EVIDENCED | `lock.inc.bash` | BASH-CORE/ARCH |
| M-077 | INFO | 4 Composer plugins + 16 PHPStan rules unit-untested | php-src-map§8 | AGENT-EVIDENCED | `src/ComposerPlugin/*`,`src/PHPStan/Rules/Forbid*.php` | PROCESS/PHP |
| M-078 | INFO | Two `cd`s split config/run phases — load-bearing, undocumented; `$DIR` vs `$qaDir` dual naming | AR§1.1=FS-006 | FABLE-VERIFIED | `bin/qa:49,192`;`options.inc.bash:2` | ARCH |
| M-079 | INFO | Header-comment richness bimodal; echo vs printf mixed | BQ-018=BQ-019 | AGENT-EVIDENCED | `includes/generic/*` | BASH-CORE |
| M-080 | INFO | github-actions.md qa-autofix section omits same-commit deploy-key guard | DS-024 | AGENT-EVIDENCED | `docs/github-actions.md` | DOCS |
| M-081 | SPECULATIVE | "PHPLoc cannot fail" not code-guaranteed — `runTool phploc` under `set -e`; not reproduced | DC-016 | SPECULATIVE | `bin/qa:314`,`phploc.inc.bash` | DOCS/BASH-CORE |
| M-082 | SPECULATIVE | Install command unverified against live registry (no network) | DC-017 | SPECULATIVE | `README.md:9-11` | DOCS |
| M-083 | SPECULATIVE | DefenceBeforeFix external URL unverifiable | DS-025 | SPECULATIVE | `CLAUDE/DefenceBeforeFix.md:7` | DOCS |
| M-084 | INFO | `worktree-git-env-fix.md` clean archival candidate once `Completed/` convention exists | DS-027 | AGENT-EVIDENCED | `CLAUDE/Plan/worktree-git-env-fix.md` | DOCS |

## 2. Severity arbitration notes
- **Silent-pass gates → CRITICAL** (FS override): M-001, M-002, M-003. M-002's BQ-007 called it "lower impact"; overridden — a claimed active gate enforcing nothing.
- **M-009 derive-before-override → MAJOR class-wide** (AR "High" + FS-011). Kept as one class row.
- **M-040 PHIVE-install: DC CRITICAL vs FS-003 MINOR → MINOR** (FS wins; install works, doc-accuracy only). Δ2.
- **M-041 phpUnitCoverage: DC CRITICAL vs AR Low → MINOR.** Δ noted.
- **M-042 PHP7.4: DC CRITICAL → MINOR** (Composer enforces ^8.3, self-defeating error). Δ noted.
- **M-005 Laravel: DS CRITICAL → MAJOR** (fabrication, breaks nothing). **M-006 cascade: DC/DS CRITICAL → MAJOR.** **M-007/M-008: DS CRITICAL → MAJOR** (bounded/opt-in). Δ noted each.
- **M-013/M-014 (BS-001/002): filed MAJOR by bash-scripts (self-downgraded from CRITICAL, bounded blast radius); FS confirmed both.** Kept MAJOR.
- **php-src-map correction (binding):** its "all 11 bin scripts called by pipeline" claim is WRONG (psr4-validate never invoked M-001; annotation bin commented M-002); phive-install.bash echo is M-040. Folded in, no standalone row.

## 3. Theme clusters
- **(a) Silent-pass gates:** M-001, M-002, M-003, M-004, M-024. Enabler: empty/commented fragment sources clean + no test (M-028). *Top priority.*
- **(b) Fabrications in docs:** M-005, M-006, M-040, M-037, M-038, M-039, M-054, M-058, M-081.
- **(c) Docs missing subsystems:** M-031, M-032, M-033, M-034, M-029, M-030, M-035, M-036, M-055–M-059.
- **(d) Derive-before-override ordering:** M-009, M-044, M-041.
- **(e) Duplicated patterns → shared driver:** M-010, M-011, M-016, M-076, M-071, M-070, M-044.
- **(f) Dead code:** M-022, M-023, M-021, M-045, M-046, M-063, M-075 (+ M-001/M-002 dead chains).
- **(g) Consumer-write safety:** M-014, M-015, M-016, M-017, M-018, M-019, M-020, M-069, M-073, M-072.
- **(h) Untested bash layer:** M-028, M-077, M-060.
- **(i) Misc correctness/hygiene:** M-012, M-013, M-025, M-026, M-027, M-047, M-048–M-053, M-064–M-068, M-070, M-074, M-078, M-079.

## 4. Counts

**Severity × Axis** (primary axis, one count each):

| Axis | CRIT | MAJOR | MINOR | INFO | SPEC | Total |
|---|--:|--:|--:|--:|--:|--:|
| DOCS | 0 | 11 | 11 | 3 | 3 | 28 |
| BASH-CORE | 3 | 8 | 9 | 3 | 0 | 23 |
| BASH-SCRIPTS | 0 | 7 | 8 | 3 | 0 | 18 |
| ARCH | 0 | 2 | 1 | 3 | 0 | 6 |
| PROCESS | 0 | 1 | 1 | 0 | 0 | 2 |
| PHP | 0 | 0 | 0 | 1 | 0 | 1 |
| **Total** | **3** | **29** | **30** | **13** | **3** | **84** |

**Per-source contribution / overlap:** DC 17 raised → 15 rows (6 sole). DS 27 → 21 rows (14 sole). BQ 19 → 18 rows (11 sole). BS 22 → 16 rows (15 sole). AR 13 → 12 rows (4 sole). php-src-map → 3 rows (1 sole). FS 11 → 12 rows (3 sole). Most-cross-reported: M-001 (6 sources); M-022/M-023 (2–3). Docs-core & docs-secondary overlap ~5 findings, each with a large sole-source tail; bash-quality & architecture converge on retry-dup + dead-code, AR adds the config-ordering-class framing + cross-cutting matrix.

## 5. Top-10 (impact × confidence)
1. M-001 restore PSR-4 validation (CRIT, 6 sources).
2. M-003 fix strict-types `find` precedence (CRIT, repro).
3. M-002 re-enable/retire PHPUnit annotations (CRIT).
4. M-028 bash integration + shellcheck CI gate (catches 1–3, prevents recurrence).
5. M-009 fix derive-before-override ordering.
6. M-014 gate deploy-skills registration on DAEMON_DETECTED.
7. M-007 ship templated update-deps.yml.
8. M-027+M-004 extend read-only to composerChecks/phpStrictTypes.
9. M-013 fix `IFS='|||'` in vendor pre-commit hook.
10. M-010/M-011 shared runner driver + declarative tool metadata (enables 1–4).

## 6. Open questions / unverified
- M-081 phploc "cannot fail" — needs a failing-phploc repro to confirm `set -e` abort.
- M-082/M-083 install command / DefenceBeforeFix URL — no-network SPECULATIVE.
- phpStrictTypes-in-CI dispute (FS arbitration): agents say "auto-skipped"; FS says errexit-suspension-dependent (aggregate: silent skip; non-aggregate: `read` EOF → abort). Moot given M-003 but must be pinned before the M-010 driver redesign. SYNTH-CHECKED: not re-run here; flagged for Phase-2.
- M-023 full-run timing — dead functions grep-verified; the "full-run never recorded" consequence should be confirmed by one instrumented run before delete-vs-wire.
- M-060 orphan hook vs root git-hook — suspected duplicate; diff both before document-vs-delete.
- M-041 severity — genuine DC-CRITICAL↔AR-Low spread; settled MINOR; confirm no consumer relied on the wrong documented default.
