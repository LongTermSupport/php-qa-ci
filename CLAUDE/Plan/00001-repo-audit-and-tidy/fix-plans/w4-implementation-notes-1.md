# Wave 4 — Consumer Scripts Implementation Notes (1)

**Date:** 2026-07-15 · **Branch:** php8.4 · **Commit:** `774e218`
**Scope:** M-007, M-015–M-021, M-060, M-063–M-070, M-073 (M-013/M-014 confirmed pre-fixed in Wave 1; M-071/M-072 skipped per instruction).

## What shipped

New shared library **`scripts/lib/consumer-write.inc.bash`** implements the binding
ownership model with ONE mechanism (replacing signature-check / prompt / no-check):

- `install_owned_file <src> <dst> [label]` — unconditional overwrite; informational
  "overwriting" line only when an existing target's bytes differ.
- `install_owned_tree <src_dir> <dst_dir> [label]` — dir variant; `${dst:?}`-guarded
  remove-then-copy; notice when the existing tree differs.

Sourced by `deploy-skills.bash`, `install-github-actions.bash`, `setup-claude-qa-agent.bash`.

### Per-item

| M-ID | File | Change |
|---|---|---|
| M-021 | `composerScripts/installUpdateInfection.bash` | Deleted (dead; the whole `composerScripts/` dir is now gone). |
| M-060 | `.claude/hooks/php-qa-ci__check-vendor-uncommitted.py` | Deleted (duplicate — see decision below). |
| M-015/16/19 | `deploy-skills.bash`, `setup-claude-qa-agent.bash`, `install-github-actions.bash` | Routed through the owned-install helpers; agents/skills/hooks/git-hook/qa.yml overwrite unconditionally with an informational notice. |
| M-017 | `setup-branch-protection.bash` | GET current protection → merge modeled fields (preserve existing contexts + push restrictions, normalise GET/PUT schema asymmetry) → diff summary → PUT. 404 ⇒ fresh PUT. |
| M-018 | `setup-claude-qa-agent.bash` | `PROJECT_ROOT` resolved dynamically (explicit arg → composer.json walk-up requiring `lts/php-qa-ci` → historic 4-up fallback) instead of hardcoded vendor depth. |
| M-020 | `deploy-skills.bash` | Single `readonly DAEMON_INSTALL_REF="v3.9.0"` used by the "not detected" clone, consistent with the YAML enforcement path's v3.9.0+ assumption. |
| M-063/M-064 | `install-github-actions.bash` | Deleted unused `check_php_version()`; that also removed the single-quoted inline-PHP path interpolation (M-064) since it lived inside that function. |
| M-065 | `setup-branch-protection.bash` | `mktemp` temps + `trap … EXIT` (no predictable `/tmp/protection-result.txt`); SC2015 replaced with if/else; useless-cat removed. |
| M-066/M-067 | `tool-install.bash` | phive invocation built as an args array (no `eval`); `command -v phive` replaces `which phive 1>&2`. |
| M-068 | `git-hooks/pre-commit-check-vendor-uncommitted` | `${repo_dir#"$PROJECT_ROOT"/}` (SC2295). |
| M-069 | `deploy-skills.bash` | `${VAR:?}` guards on the skills remove-then-copy (via helper) and the Phase-4 hook removal. |
| M-070 | `ci.bash` | `cd "$DIR"`; `"$0 $*"` banners (SC2145); dead `standardIFS` removed. |
| M-073 | `deploy-skills.bash` | System `python3` checked ONCE up front; missing ⇒ clear error + exit 1 before any mutation. |
| M-007 | `.github/workflows/update-deps.yml` | Dropped hardcoded `ref: php8.4`; checkout now defaults to the repo's own default branch, so a consumer's copied file works verbatim. |

## Decisions & evidence

- **M-060 (delete the orphan hook):** Diffed `.claude/hooks/php-qa-ci__check-vendor-uncommitted.py`
  against `git-hooks/pre-commit-check-vendor-uncommitted`. The `.py` (a Claude PreToolUse
  hook blocking `git commit` on dirty vendor sub-repos) is a **strict subset** of the git
  hook's job — the git hook does the same uncommitted-changes check **plus** a composer.lock
  HEAD-sync check, and fires on *any* commit (CLI/IDE/Claude), not only Claude's Bash calls.
  The `.py` was unregistered, undocumented, and absent from the canonical 6-hook list in
  `.claude/hooks/CLAUDE.md`. Duplicate → deleted (matches fix-plan D5 recommendation).

- **M-007 (real copy mechanism):** Consumers receive `update-deps.yml` via a **manual `cp`**
  documented in `docs/github-actions.md` (no install script copies it). There is nothing to
  parameterise at "install time"; the correct fix is to not hardcode a branch in the shipped
  workflow. Removing `ref:` makes `actions/checkout` default to the repo's own default branch
  on `schedule`/`workflow_dispatch` — correct for both php-qa-ci (default branch = php8.4) and
  any consumer.

## Register claims that proved wrong / already done

- **M-013 already fixed (Wave 1):** `pre-commit-check-vendor-uncommitted:157-164` already uses
  parameter-expansion splitting with an explanatory comment; the `IFS='|||'` bug is gone. Not redone.
- **M-014 already fixed (Wave 1):** the Phase-2 registration is already gated on
  `DAEMON_DETECTED == "false"` (`deploy-skills.bash`), with a comment describing the
  register-then-undo fix. Not redone.

## FLAGS — RESOLVED (Fable ruling, follow-up commit after 774e218)

Both flags below were accepted by the coordinator and are now implemented in
`scripts/lib/consumer-write.inc.bash` (the ownership-model SSoT, which documents
both exceptions in its header):

1. **git pre-commit hook → SHARED/signature exception (`install_signed`)**:
   `.git/hooks/pre-commit` is a singleton path shared with husky/lefthook, so a
   foreign file there is not php-qa-ci-owned. Overwrite only when the target is
   absent or carries the `PHP-QA-CI-HOOK-SIGNATURE` marker; a foreign hook gets
   a loud stderr warning with chaining instructions and is left intact (never
   fails composer install).
2. **GitHub Actions workflow → SEED-ONCE (`install_seed_once`)**: the documented
   contract tells consumers to customise it (matrix/triggers/secrets — not
   expressible via qaConfig/), so it is written only when absent and
   consumer-owned afterwards.

Verified by direct helper exercise: foreign hook untouched + warned; our stale
hook refreshed; absent hook installed; workflow seeded when absent, untouched
when present. NOTE: the coordinator and the w4 agent implemented these rulings
concurrently (messages crossed); the coordinator reconciled the collision and
landed the merged version.

## FLAGS for the user (original, as raised — binding decision applied, but worth a second look)

1. **git pre-commit hook now overwrites unconditionally.** Per the binding ownership model I
   removed the signature check that previously *skipped* a consumer's own custom
   `.git/hooks/pre-commit`. `.git/hooks/pre-commit` is a standard shared path a consumer may
   legitimately own (husky, lefthook, etc.). Deploy now replaces it every run (with an
   informational notice when it differs). This is more consequential than clobbering a
   `.claude/` artefact — flagging in case you want a carve-out (e.g. keep the signature skip
   *only* for the git hook while unifying the rest).

2. **`qa.yml` (GitHub Actions workflow) now overwrites unconditionally** in
   `install-github-actions.bash` (replacing the `read -p` prompt). The consumer-facing docs
   explicitly tell users to "review and customize .github/workflows/qa.yml", so this artefact
   is the one most likely to carry legitimate consumer edits. Unconditional overwrite (with a
   notice) is per the binding model, but this artefact arguably fits SEED-ONCE better than OWNED.

## Verification

- `bash -n` + `shellcheck -x -S warning` clean on all 8 changed scripts. The single remaining
  `ci.bash` SC2155 is **pre-existing** on line 2 (`readonly DIR=$(…)`), which I did not touch.
- **Fixture consumer** (`mktemp -d` with `.claude/`, `settings.json`, `composer.json`, `src/`,
  `git init`), `deploy-skills.bash <qaci> <fixture>` run **twice**:
  - Second run **idempotent** — `diff -r` of `.claude/`, `composer.json`, `CLAUDE.md`,
    `.git/hooks/pre-commit` shows no change; **zero** spurious "Overwriting" notices.
  - git pre-commit installed + executable + carries our signature.
  - 5 PreToolUse + 1 Stop hook registered in `settings.json`.
  - Hand-editing a deployed agent then re-running prints the "Overwriting existing agent" notice
    and **restores** the owned content (OWNED contract).
  - Running with `python3` absent from PATH exits 1 with the clear message and creates **no**
    `.claude/skills` (no partial mutation).
- **Branch-protection merge** exercised standalone against a realistic GET payload: preserves
  existing contexts (`existing-ci`, `other-check`) + adds `PHP QA Pipeline`; preserves
  users/teams/apps restrictions; overlays enforce_admins/signatures/approval count; strips
  GET-only review keys (`url`, `dismissal_restrictions`); normalises `{enabled:bool}` → bool.

## Coordination

- `docs/github-actions.md` is co-owned; I added only a 2-line M-007 note (workflow checks out
  the default branch; no edit needed) and did **not** stage/commit that file — it carried other
  docs agents' uncommitted edits. Messaged the docs agent to fold my note into their commit.

## Follow-up commit — lead rulings on the two flags (accepted)

The lead graded `774e218` A- and accepted both flags. Implemented as a follow-up commit (no
`--amend`; it's blocked). `scripts/lib/consumer-write.inc.bash` is now the ownership-model SSoT
and documents TWO deliberate non-OWNED exceptions in its header:

- **FLAG 1 — git pre-commit hook is SHARED/signature, not OWNED.** `.git/hooks/pre-commit` is a
  singleton path git shares with husky/lefthook/hand-rolled hooks, so a foreign file there is not
  ours to clobber. New `install_signed <src> <dst> <marker> [label]`: overwrite only when the
  target is absent OR already carries `PHP-QA-CI-HOOK-SIGNATURE` (line 2 of our shipped hook —
  matches any of our versions); otherwise print a loud warning naming the foreign hook (chain it
  from theirs, or remove theirs and re-run) and **leave it intact**. Never exits non-zero — must
  not fail `composer install`. `deploy-skills.bash` now calls `install_signed` and only sets the
  exec bit / prints the success line when the in-place hook is ours.

- **FLAG 2 — GitHub Actions workflow is SEED-ONCE, not OWNED.** The consumer docs contract is
  "customise this workflow" (matrix/triggers/secrets are per-project and cannot be expressed via
  `qaConfig/`), so php-qa-ci must not own or re-sync it. New `install_seed_once <src> <dst>
  [label]`: write only when absent; if present, leave untouched and print one info line pointing
  at the template for a manual re-sync. `install-github-actions.bash` now calls it (no prompt, no
  diff-check overwrite).

**Follow-up verification:** `bash -n` + `shellcheck -x -S warning` clean on the three changed
scripts. Unit-tested both helpers directly: `install_signed` — absent→install, ours→overwrite,
foreign→kept+warn+rc0; `install_seed_once` — absent→seed, present→untouched+info line. Full
fixture deploy still idempotent; fresh dir installs our git hook; a pre-planted foreign
`pre-commit` is preserved with the warning and the deploy still exits 0.

**Note on concurrent edits:** this file and `deploy-skills.bash`/`consumer-write.inc.bash` were
being edited in parallel during the follow-up (a duplicate `install_seed`/`install_seed_once`
briefly appeared and the git-hook block was rewritten with equivalent wording). Reconciled to a
single seed function named `install_seed_once` (the name the header + caller use) and confirmed
the final state is self-consistent and green.
