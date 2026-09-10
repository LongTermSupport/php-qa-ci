# Wave 3 (Docs Rot) — Implementation Notes

**Agent**: w3-docs
**Date**: 2026-07-15
**Scope**: DOCS-axis findings — every claim re-verified against code at edit time.

## Summary

Eliminated documentation rot across `CLAUDE.md`, `README.md`, `docs/**`, `CLAUDE/branch-policy.md`,
and two loose `CLAUDE/Plan/*.md` docs. Deleted two confirmed-dead files. Every corrected claim was
re-read against the actual source **at the time of writing** (not against the pre-change audits),
because Wave 1/2/4 changed ground truth mid-flight.

Markdown links checker (`bin/qa -t ml`) passes (exit 0; only anonymous-GitHub-token skips, no
broken internal/relative links).

## Ground-truth corrections that DIFFERED from the audit (caught by re-verification)

- **PHP version**: the audit (and the task brief, M-041/M-042) said `composer.json` requires `^8.3`.
  Commit `76a9265` bumped it to **`^8.4`** during this wave. Docs now say PHP **8.4+** on this branch
  (with a note that the separate `php8.3` branch supports 8.3). This is the single biggest
  rot-2.0 trap avoided.
- **update-deps.yml `ref: php8.4`** (M-007/WP-D9): the Wave-4 scripts agent FIXED the workflow to
  use the repo's own default branch (no hardcoded `ref:`) and left the matching doc note in
  `docs/github-actions.md` for me to own. No warning needed — the doc now states the true (fixed)
  behaviour. See "Shared file" below.
- **PSR-4 validation**: restored & live (Wave 1) — CLAUDE.md's PSR-4 section is now TRUE, left as-is.
- **PHPUnit annotations**: fully removed (fragment/bin/src all gone) — deleted every doc mention;
  none remained after the sweep.
- **phpStrictTypes**: scans .php+.phtml, read-only reports+fails, writable auto-fixes, NO prompt —
  docs updated (CLAUDE.md tool section was already correct; fixed docs/phpqa-tools.md wording).
- **Per-tool lock/timing hooks** (`toolStart`/`toolComplete`/`toolFailed`): confirmed removed from
  the codebase (grep: zero defs/callsites). Documented run-level locking only.
- **Orphan hook** `php-qa-ci__check-vendor-uncommitted.py` (M-060/DC-015): already deleted from
  `.claude/hooks/`; the 6-hook lists in both root docs are now accurate — no edit needed.

## Verified counts (from source, not the audit)

- **Always-on PHPStan rules = 14** (`rules-default.neon`: 10 in `rules:` + 4 service-tagged:
  ForbidMockingFinalClassRule, RequireSensitiveParameterAttributeRule, RequireApiOrInternalTagRule,
  ApiMustNotExposeInternalRule). `RequireTypeSuffixRule` is comment-only (migrated to arkitect).
- **Opt-in PHPStan rules = 12** (`rules-optional.neon`: 6 `rules:` + 2 service = 8;
  `rules-optional-symfony.neon`: +4). `ForbidMagicStringAssertionRule` is in neither bundle.
- **Composer plugins = 4** (`extra.class`): Phive/SkillsDeploy/PhpStanGuard/ManagedSourceDeploy.
- **PHIVE PHARs = 5** (`phive.xml`): phpstan, php-cs-fixer, infection, composer-require-checker,
  phparkitect/arkitect.
- **Infection default MSI floors = 60 (MSI) / 80 (covered)** (`deriveDependentConfig`).
- **phpUnitCoverage default = 1** (`setConfig.inc.bash:53`).

## Files changed

Root docs: `CLAUDE.md`, `README.md`.
docs/: `pipeline.md`, `configuration.md`, `platform-detection.md`, `coding-standards.md`,
`phpqa-tools.md`, `github-actions.md`, `tools/phpstan.md`, `tools/phpunit.md`, `tools/infection.md`.
CLAUDE/: `branch-policy.md`, `Plan/locking-system.md` (historical banner),
`Plan/skills-deployment-system-2025-11.md` (superseded banner).
Deleted: `docs/_config.yml`, `CLAUDE/ANALYSIS/timing-schema-analysis.md`.

## M-IDs addressed

M-005 (Laravel fabrication removed everywhere), M-006 (config cascade rewritten to real
`configPath()` 3-level lookup; `configDefaults.inc.bash` claim deleted), M-007 (resolved by Wave-4;
doc reflects fix), M-008 (CI-repro → `QA_READONLY=1`), M-029 (rule counts), M-030 (4 plugins),
M-031/M-032 (phase numbering + packageType + branchNamePolicy), M-033/M-034 (read-only/aggregate/
`--json` modes; fail-fast qualified), M-035 (phpqaQuickTests skips whole phases), M-036/M-080
(phpunit coverage claims), M-037 (branch-policy fallback), M-040 (tool-install unconditional,
phive.xml required), M-041/M-042 (PHP `^8.4`; coverage default 1), M-054 (`@PHP8x4Migration`, 3
places), M-055 (config file inventory), M-056 (pipeline numbering), M-057 (github-actions PHIVE
list + check-run names + caching precision), M-058 (infection covered-MSI 80 + configDir framing),
M-059 (README docs list), M-060 (orphan hook already gone), M-061 (`_config.yml` deleted), M-062
(`timing-schema-analysis.md` deleted), M-084 (plan archival left in place per instruction — banners
added, no moves; no `Completed/` convention created).

Also: locking preflight step + run-level locking documented (DC-012); phpqa-tools stale anchors
(runTool #L30→#L20, detectPlatform #L7→#L6) fixed and re-verified against current HEAD; deleted the
dead "Preflight: Uncommitted Changes Check" section (feature removed with
`checkForUncommittedChanges`).

## Claims DELETED rather than corrected (unverifiable / no longer true)

- The fabricated branch-policy fallback list (`main`/`master`/`develop`/`DumbItDown`/`NewCheckout`)
  — replaced with the real "warn + require explicit config" behaviour.
- `configDefaults.inc.bash` and per-platform `configDefaults/{platform}/` cascade layers — do not
  exist; removed.
- The "Preflight: Uncommitted Changes Check" doc section — feature deleted in Wave 1/2.

## Shared file / coordination

`docs/github-actions.md` was jointly touched: the Wave-4 scripts agent added the update-deps
"default branch" note (correct, pairs with their committed `update-deps.yml` fix) and left it
unstaged for me as docs owner. I committed the doc (my M-008/M-057/caching edits + their note) via
`git commit -- <explicit paths>` so no other agent's staged/committed work was swept in. Flagged the
overlap to team-lead.

## NOT done / deferred (out of scope or gated)

- Deep semantic per-rule behaviour verification — inventory/count level only, per audit scope.
- Any CODE change — none made; docs describe current code truthfully.
- Plan-tree archival convention (`Completed/`/`Superseded/`) — NOT created (task instruction M-084:
  leave archival alone absent an existing convention). Rotten/drifted plan docs made honest via
  in-place banners instead of moves.
