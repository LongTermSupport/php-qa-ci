# Fix Plan — Consumer-Scripts Axis (`scripts/`, `git-hooks/`, `composerScripts/`, `bin/*` stubs, `ci.bash`)

**Plan:** 00001-repo-audit-and-tidy · **Axis:** BASH-SCRIPTS + theme (g) consumer-write safety
**Author:** consumer-scripts fixplan agent (opus) · **Date:** 2026-07-15 · **Mode:** PLANNING ONLY (no code changed)
**Closes:** M-013, M-014, M-015, M-016, M-017, M-018, M-019, M-020, M-021, M-063, M-064, M-065, M-066, M-067, M-068, M-069, M-070, M-071, M-072, M-073 (+ decision on M-060)
**Sources:** `synthesis/findings-master-1.md`; `audits/bash-scripts-1.md` (risk table §4, consolidation §5); code read in full: `scripts/deploy-skills.bash`, `scripts/write-claude-block.bash`, `scripts/setup-claude-qa-agent.bash`, `scripts/install-github-actions.bash`, `scripts/setup-branch-protection.bash`, `scripts/tool-install.bash`, `ci.bash`, `composerScripts/installUpdateInfection.bash`, `git-hooks/pre-commit-check-vendor-uncommitted`, `bin/phpstan`, `src/ComposerPlugin/SkillsDeployPlugin.php`.

---

## 0. The governing constraint

`deploy-skills.bash` runs **unattended** during every consumer `composer install/update`, invoked by
`SkillsDeployPlugin::deploySkills()` (`SkillsDeployPlugin.php:95-104`) via `\Safe\exec("bash <script> <qaci> <root>")`.
It is `set -euo pipefail` with **five bare `python3` heredocs** (lines 145, 232, 516, 639, plus the daemon-venv one).
Every design choice below is judged against three failure modes:

1. **Partial execution** — killed process / disk full / `set -e` abort mid-run must never leave a consumer in a
   worse state than before, and a re-run must converge (idempotency = interrupt-recovery).
2. **Missing `python3`** — must degrade with a clear message, never a bare `command not found` that aborts a
   half-applied deploy (M-073).
3. **Re-runs** — every `composer update` re-invokes this. No write may churn a consumer's tracked files
   (`settings.json`, `composer.json`, `CLAUDE.md`) unless content actually changed.

No behaviour change may break an existing consumer layout (default `vendor/`, custom `bin-dir`, monorepo
parent-`.claude/`, container-mounted daemon venv). The ownership model (§2) is the backbone that makes all of
this reasoning local instead of scattered across 705 lines.

---

## 1. Root-cause framing

The audit's §4 risk table shows the same problem — "is this artefact safe to overwrite?" — answered **three
incompatible ways** across the scripts (M-016): signature-check (git hook), `read -p` prompt
(install-github-actions), and no check at all (skills/agents, php-qa-specialist agent). Plus one register/undo
race (M-014) and one wholesale remote overwrite (M-017). None of it is tested (M-072 tail).

The fix is not to patch each site; it is to **name an ownership class for every artefact** and route every
consumer write through **one helper** that enforces that class's policy. Once an artefact is classified, its
overwrite behaviour, its idempotency guarantee, and its interrupt behaviour are all determined — no per-site
judgement calls.

---

## 2. Ownership model (HARD-CONSTRAINT DELIVERABLE)

Three ownership classes. Every artefact php-qa-ci writes into a consumer belongs to exactly one.

| Class | Meaning | Overwrite policy | Idempotency guarantee |
|---|---|---|---|
| **OWNED** | php-qa-ci fully controls the file; consumers must NOT hand-edit — customise via `qaConfig/` only. | Overwrite every run, but **write-only-if-changed** (compare bytes first). | Re-run = no-op unless php-qa-ci shipped a new version. Local edits are (by contract) discarded — this is the documented deal. |
| **SEED-ONCE** | php-qa-ci provides a starting point the consumer then owns and edits. | Write **only if absent**; never touch an existing file. | Re-run = no-op. Consumer edits always preserved. |
| **SHARED / delimited-merge** | A consumer-owned file in which php-qa-ci owns only specific keys/blocks. | Merge touching **only** php-qa-ci's portion; never remove or reorder foreign keys. Write-only-if-changed. | Re-run = no-op. Foreign content always preserved. |

### 2.1 Per-artefact ownership table

| Artefact | Consumer target | Class | Policy under helper | Finding |
|---|---|---|---|---|
| Skills | `.claude/skills/<name>/` | **OWNED** | `install_owned_tree` — rsync-equivalent, remove-then-copy only when source differs | M-015 |
| Agents (bundled) | `.claude/agents/*.md` | **OWNED** | `install_owned_file` | M-015 |
| `php-qa-specialist.md` (generated) | `.claude/agents/php-qa-specialist.md` | **OWNED** | `install_owned_file` (rendered to a temp, then owned-install) | M-019 |
| Classic hooks (`.py`) | `.claude/hooks/php-qa-ci__*.py` | **OWNED** (daemon-gated: deployed only when `DAEMON_DETECTED=false`) | `install_owned_file`, guarded by daemon check | M-014 |
| Git pre-commit hook | `.git/hooks/pre-commit` | **SHARED / signature** | `install_signed` — overwrite iff target carries our `PHP-QA-CI-HOOK-SIGNATURE` marker or is absent; else skip+warn | model (already correct) |
| `settings.json` hook entries | `.claude/settings.json` | **SHARED / merge** | python merge; touch only `php-qa-ci__` commands; never other keys; write-only-if-changed | M-014 |
| `hooks-daemon.yaml` required handlers | `.claude/hooks-daemon.yaml` | **SHARED / merge** | python merge; add/fix only the 4 required handler keys; never remove foreign handlers | (already correct) |
| `CLAUDE.md` `<phpqaci>` block | `CLAUDE.md` | **SHARED / delimited** | `write-claude-block.bash` (already the gold standard) | model (already correct) |
| `composer.json` `autoload-dev` `QaConfig\` | `composer.json` | **SHARED / merge** | python; add key only if missing; write-only-if-changed | (already correct) |
| `qaConfig/PHPStan/CLAUDE.md` | consumer `qaConfig/PHPStan/` | **SEED-ONCE** | `install_seed` | (already correct) |
| `src/PHPStan/CLAUDE.md` guardrail | consumer `src/PHPStan/` | **SEED-ONCE** | `install_seed` | (already correct) |
| GitHub workflow `qa.yml` | `.github/workflows/qa.yml` | **SEED-ONCE** | `install_seed` (drop the interactive `read -p` — hangs if ever auto-run) | M-063 vicinity |
| Branch protection (remote) | GitHub branches API | **SHARED / merge** | GET → merge required contexts → diff → PUT | M-017 |

**The three genuinely-correct sites today** (git-hook signature, CLAUDE.md block writer, seed-once PHPStan
scaffolds) become the reference implementations the helper generalises; the three broken sites (skills/agents,
php-qa-specialist agent, branch protection) are migrated onto it.

---

## 3. Shared helper design (M-016 convergence)

New sourced library: **`scripts/lib/consumer-write.inc.bash`** (name TBD; see handoff note §5). Pure bash + a
single shared python helper for JSON/YAML merges. Public API:

```bash
# OWNED: overwrite, but only if bytes differ (no mtime churn, idempotent).
install_owned_file  <src> <dst>            # returns: installed | unchanged
install_owned_tree  <src_dir> <dst_dir>    # dir variant (skills)

# SEED-ONCE: write only if target absent. Never clobber.
install_seed        <src> <dst>            # returns: created | kept

# SHARED / signature: overwrite iff target has our marker or is absent.
install_signed      <src> <dst> <marker>   # returns: installed | skipped-foreign

# SHARED / merge: routed to one python helper that loads, mutates a
# named-key subtree, and writes only on change. Used by settings.json,
# hooks-daemon.yaml, composer.json.
merge_json_keys <file> <python-merge-fn>   # thin wrapper, guarded python3
```

Cross-cutting guarantees baked into the helper (so no caller re-implements them):

- **`require_python3`** guard used by every merge site — resolves an interpreter, and on absence prints a
  single explicit "python3 required for <step>, skipping (deploy not aborted)" and returns non-fatally instead
  of letting `set -e` kill the run mid-deploy (fixes M-073 for all sites at once).
- **write-only-if-changed** for OWNED and every merge — the idempotency contract in one place.
- **`${VAR:?}` hardening** on every `rm -rf`/destructive path (fixes M-069 generally, not just line 106).

This directly discharges audit §5 item 3 ("converge on one shared helper … a small `safe-install-file.bash`").

---

## 4. Work packages

Effort key: S = mechanical, no design · M = design + code · L = structural. Blast radius = consumer impact.

### WP-S1 — Hygiene quick-wins batch (front-loaded, no design)
- **Closes:** M-013, M-021, M-063, M-064, M-065, M-066, M-067, M-068, M-069, M-070
- **Files:** `git-hooks/pre-commit-check-vendor-uncommitted`, `composerScripts/installUpdateInfection.bash` (delete), `scripts/install-github-actions.bash`, `scripts/setup-branch-protection.bash`, `scripts/tool-install.bash`, `scripts/deploy-skills.bash`, `ci.bash`
- **Change sketch:**
  - **M-013** (`pre-commit…:159-161`): replace `IFS='|||'; read -r …` with the parameter-expansion split already
    used at `:143-144` — `repo_path="${entry%%|||*}"; rest="${entry#*|||}"; locked_commit="${rest%%|||*}"; current_commit="${rest##*|||}"`. Verified-repro bug; fixes the garbled SHA diagnostic.
  - **M-021**: `git rm composerScripts/installUpdateInfection.bash` — dead (no wiring, grep-confirmed), pinned
    Infection `0.9.0` vs PHIVE `^0.34`, downloads a PHAR + pubkey but never verifies (latent security trap). Delete
    outright. Removes M-070's second instance and M-010's boilerplate copy for free.
  - **M-063** (`install-github-actions.bash:64-85,119`): delete `check_php_version()` + its unread assignment
    (`php_version=$(check_php_version)`), OR wire the detected version into a workflow matrix. Recommend **delete**
    (simpler; the workflow template is version-agnostic).
  - **M-064** (`install-github-actions.bash:71-74`): pass the path via `php -r '…' -- "$composer_file"` and read
    `$argv[1]`, matching the safe pattern in `pre-commit…:63-73`. Removes the single-quote-in-path injection.
  - **M-065** (`setup-branch-protection.bash:116`): `mktemp` + `trap 'rm -f "$tmp"' EXIT` instead of the
    predictable `/tmp/protection-result.txt` (mirror `ci-push-ssh-deploy-bundle.bash:49-50`).
  - **M-066** (`tool-install.bash:107,110`): replace `eval "phive … $TRUST_KEYS_ARG"` with an array:
    `phive --home "$PHIVE_HOME" install --copy --trust-gpg-keys "$(IFS=,; echo "${TRUSTED_KEYS[*]}")"` — no `eval`.
  - **M-067** (`tool-install.bash:63`): `command -v phive >/dev/null 2>&1` instead of `which phive 1>&2`.
  - **M-068** (`pre-commit…:92`): quote the prefix — `${repo_dir#"$PROJECT_ROOT"/}`.
  - **M-069** (`deploy-skills.bash:106`): `rm -rf "${SKILLS_TARGET:?}/$skill_name"` (subsumed by the helper in
    WP-S3, but land the one-liner now for safety).
  - **M-070** (`ci.bash:3,7,11,25`): quote `cd "$DIR"`; drop dead `standardIFS="$IFS"` (never exported, re-derived
    inside `bin/qa`); fix the `$(hostname) $0 $@` banner (SC2145) to `$0 $*` or a printf per-arg. `installUpdateInfection.bash` copy dies with M-021.
- **Effort:** S · **Risk:** very low; M-013 changes a diagnostic string only (gating decision unaffected), M-021 is
  a confirmed-dead delete. **Blast radius:** none — no consumer-visible behaviour change beyond a corrected message.
- **Verification:** `shellcheck -x` clean on each touched file; `bash -c` repro for M-013 (feed a synthetic
  `entry`, assert `locked_commit`/`current_commit` split correctly); grep-confirm zero references to the deleted
  installer before/after.
- **Rollback:** per-file git revert; each item is independent.

### WP-S2 — Close the register/undo window (interrupt safety) — do NOT wait for decomposition
- **Closes:** M-014, M-073 (deploy-skills instances)
- **Files:** `scripts/deploy-skills.bash`
- **Change sketch:** gate the Phase-2 registration block (`:212-325`, currently guarded only on `-d "$HOOKS_SOURCE"`)
  additionally on `DAEMON_DETECTED == "false"`, mirroring the Phase-1 copy guard at `:131`. Then a daemon-managed
  project **never** registers classic hooks, so the Phase-4 teardown (`:490-575`) has nothing to undo and the
  register→interrupt→dangling-registration window (files never copied, entries left pointing at them) cannot
  exist. Keep the Phase-4 `settings.json` cleanup as a **migration** path for consumers upgrading from the old
  behaviour (idempotent; safe to keep). Wrap the four bare `python3` heredocs (`:145, 232, 516, 639`) in the
  `require_python3` guard (interim inline version; folded into the helper in WP-S3).
- **Effort:** S (one gating condition + guard) · **Risk:** low. **Blast radius:** positive — removes a
  silent-broken-config outcome from every daemon consumer's `composer update`.
- **Verification:** fixture run (WP-S7) with `hooks-daemon.yaml` present asserts zero `php-qa-ci__` entries ever
  written to `settings.json`, even when the run is killed after `:325`.
- **Rollback:** revert the one-line guard; teardown path still self-heals as before.

### WP-S3 — Ownership model + shared helper (foundational)
- **Closes:** M-016 (and the machinery M-015/M-019/M-069 build on)
- **Files:** new `scripts/lib/consumer-write.inc.bash` + one shared python merge helper; docs stub in
  `scripts/lib/README.md` naming the three classes.
- **Change sketch:** implement the §3 API. Extract the git-hook signature logic (`deploy-skills.bash:342-361`) into
  `install_signed`; generalise the seed-once pattern (`:613-635`) into `install_seed`; add `install_owned_*` with
  write-only-if-changed. Do **not** yet migrate call sites (that's WP-S4/S5/S6) — this WP is the library + its own
  unit tests only, so the risky migrations land on a tested foundation.
- **Effort:** M · **Risk:** low (new code, no call sites changed yet). **Blast radius:** none until adopted.
- **Verification:** unit tests (bats or bash harness) for each helper: owned overwrites-on-diff / no-op-on-same;
  seed keeps existing; signed skips foreign, overwrites ours; merge preserves foreign keys; `require_python3`
  degrades cleanly. These are the seed of the WP-S7 suite.
- **Rollback:** delete the library; nothing depends on it yet.

### WP-S4 — Migrate OWNED artefacts onto the helper + version single-source
- **Closes:** M-015, M-019, M-020
- **Files:** `scripts/deploy-skills.bash`, `scripts/setup-claude-qa-agent.bash`; doc note in the deployed skills/agents
- **Change sketch:**
  - Route skills (`:99-117`), agents (`:119-128`), and the generated `php-qa-specialist.md`
    (`setup-claude-qa-agent.bash:44-58`, render to temp then `install_owned_file`) through `install_owned_*`.
    Declares them OWNED per the §2 decision — add a one-line banner in each deployed skill/agent header ("Managed
    by php-qa-ci — do not edit; customise via qaConfig/") and a sentence in README/deployment docs.
  - **M-020**: introduce one `DAEMON_INSTALL_REF` constant near the top of `deploy-skills.bash` and reference it in
    both the "not detected" clone instructions (`:587`) and the venv-upgrade guidance (`:390, 410`), OR drop the
    hardcoded `v2.2.0` tag entirely and point at the daemon's install docs. Recommend the constant (single SSoT).
- **Effort:** M · **Risk:** low-moderate — OWNED semantics mean a consumer who *did* hand-edit a skill loses it,
  but that is the documented contract and matches the repo's "customise via config, not vendored files" philosophy
  (§7 decision D1). **Blast radius:** consumers who hand-edited skills/agents (expected: none, undocumented use).
- **Verification:** fixture asserts an edited skill is restored on re-run; an unchanged tree produces no writes.
- **Rollback:** revert call sites to direct `cp`; helper stays.

### WP-S5 — Fix `setup-claude-qa-agent.bash` path detection
- **Closes:** M-018
- **Files:** `scripts/setup-claude-qa-agent.bash`
- **Change sketch:** replace the hardcoded `PROJECT_ROOT="${SCRIPT_DIR}/../../../.."` (`:9`) with the composer.json
  walk-up already proven in `install-github-actions.bash:19-28` (find nearest `composer.json` requiring
  `lts/php-qa-ci`), or accept `PROJECT_ROOT` as an explicit `$2` argument like `deploy-skills.bash`. This makes the
  script honour non-default `vendor-dir`/monorepo layouts, consistent with its own dynamic `detect_qa_binary()`.
  **Note:** evaluate folding this script's binary-detection into the OWNED agent template so `deploy-skills`
  produces `php-qa-specialist.md` directly and the standalone script retires (see decision D4).
- **Effort:** S–M · **Risk:** low. **Blast radius:** fixes wrong-location writes for custom layouts.
- **Verification:** fixture with a custom `bin-dir`/`vendor-dir` asserts the agent lands under the real project root.
- **Rollback:** revert to the fixed-path assumption.

### WP-S6 — Branch-protection GET-merge-PUT
- **Closes:** M-017
- **Files:** `scripts/setup-branch-protection.bash`
- **Change sketch:** before the `PUT` (`:112-118`), `gh api GET …/protection` into a temp; merge php-qa-ci's
  required `contexts` (`"PHP QA Pipeline"`) into the *existing* `required_status_checks.contexts` and required-review
  block rather than replacing the whole object; print a diff of what will change; require an explicit `--force`
  (or interactive confirm) only when the existing config conflicts with the target. Preserves pre-existing checks,
  restrictions, and unmodeled settings. Fold in M-065's `mktemp`/`trap` here too, and the SC2015/useless-cat
  cleanup (M-065's sibling BS-016). This is a maintainer-invoked script (not auto-run), so a confirm prompt is
  acceptable here (unlike deploy-skills).
- **Effort:** M · **Risk:** moderate (touches remote repo config) — mitigated by dry-run diff + capturing prior
  state to a backup file before PUT. **Blast radius:** repos with protection beyond this script's model stop losing it.
- **Verification:** hard to unit-test (remote API); provide a `--dry-run` that prints the merged JSON and the diff,
  and document a manual test against a throwaway repo. Assert the merged JSON is a superset of prior contexts.
- **Rollback:** revert; document the captured-prior-state backup as the manual restore path.

### WP-S7 — Fixture consumer project + idempotency/interrupt test suite
- **Closes:** M-072 (test-coverage precondition); verification vehicle for WP-S2/S4/S5
- **Files:** new `tests/…/consumer-deploy/` fixture skeleton + a bash/bats harness
- **Change sketch:** build a minimal fixture consumer under a test-fixtures path (a `composer.json` requiring
  `lts/php-qa-ci`, an empty `.claude/`, optionally a `.claude/hooks-daemon.yaml` variant, a `src/`). Harness runs
  `deploy-skills.bash <qaci> <fixture>` and asserts:
  1. **Fresh deploy** — all artefacts land per the ownership table; exit 0.
  2. **Idempotency** — a second run produces a byte-identical fixture (`git diff --quiet` on the fixture tree);
     specifically zero churn in `settings.json`, `composer.json`, `CLAUDE.md`.
  3. **Consumer edits preserved** — hand-edit a SEED-ONCE file and inject a foreign `settings.json` key + foreign
     `hooks-daemon.yaml` handler → re-run → both preserved.
  4. **OWNED overwrite** — edit a deployed skill → re-run → restored (documents the OWNED contract).
  5. **Interrupt recovery** — run the script truncated after Phase-2 (kill/return early), then re-run full →
     converges; with daemon present, assert **no** dangling `php-qa-ci__` `settings.json` entries at any point
     (the M-014 regression test).
  6. **Missing python3** — run with `python3` removed from `PATH` → assert a clear per-step skip message, non-zero
     only where appropriate, and no half-applied tracked-file writes (M-073).
- **Effort:** M · **Risk:** low (tests only). **Blast radius:** none.
- **Verification:** the suite is the verification; it registers into the M-028 shellcheck+bats CI gate owned by
  `bash-refactor-1.md` (handoff §5).
- **Rollback:** N/A (additive).

### WP-S8 — Consolidate the four `bin/*` redirect stubs
- **Closes:** M-071
- **Files:** `bin/composer-require-checker`, `bin/infection`, `bin/php-cs-fixer`, `bin/phpstan`
- **Change sketch:** extract the shared 8-of-11 lines (the `COMPOSER_RUNTIME_BIN_DIR` → project-root walk-up →
  `qaCmd` derivation, `bin/phpstan:2-8`) into one sourced snippet (`bin/_qa-redirect-stub.inc.bash`); each stub
  sources it and supplies only its tool name + example echo lines. **Handoff:** the same project-root-walk logic
  appears in `bin/qa`'s composer-proxy self-parse (M-053, bash-core) — coordinate with `bash-refactor-1.md` so a
  single walk-up helper serves both rather than minting two (§5).
- **Effort:** S · **Risk:** low. **Blast radius:** none (stubs only print guidance + `exit 1`).
- **Verification:** run each stub, assert identical guidance text and exit 1; shellcheck clean.
- **Rollback:** revert to four standalone stubs.

### WP-S9 — Decompose `deploy-skills.bash` (structural) — AFTER S3 + S7
- **Closes:** M-072
- **Files:** `scripts/deploy-skills.bash` → thin orchestrator + `scripts/deploy/phase-*.bash`
- **Change sketch:** split the 7 responsibilities into per-phase scripts sourced by a thin orchestrator, all
  built on the WP-S3 helper: `phase-owned-artefacts` (skills/agents), `phase-classic-hooks` (copy + register,
  daemon-gated — already interrupt-safe post-WP-S2), `phase-git-hook` (`install_signed`), `phase-daemon-config`
  (yaml enforce + classic teardown), `phase-phpstan-scaffold` (`install_seed`), `phase-claude-block`
  (`write-claude-block.bash`). Each phase becomes independently testable against the WP-S7 fixture. Update
  `SkillsDeployPlugin` only if the orchestrator path/args change (keep the two-arg contract stable). Refresh the
  rotten `CLAUDE/Plan/skills-deployment-system-2025-11.md` (M-039) as part of this — **handoff to docs plan**.
- **Effort:** L · **Risk:** moderate (moves a lot of load-bearing unattended code) — gated behind the WP-S7 suite
  so behaviour is pinned before and after. **Blast radius:** high if wrong; zero if the fixture suite is green.
- **Verification:** WP-S7 suite must pass byte-identically before and after the split (golden-master refactor).
- **Rollback:** the pre-split monolith is one git revert away; keep it on a branch until the suite is green.

---

## 5. Ordering, dependencies & handoffs

```
WP-S1 (hygiene)      ─┐
WP-S2 (M-014 gate)   ─┤  independent quick wins — land first, in parallel
                      │
WP-S3 (helper) ──────► WP-S4 (OWNED migrate) ──┐
              └──────► WP-S7 (fixture+tests) ──┼──► WP-S9 (decompose)
WP-S5 (path)         ─┘ (uses fixture)         │
WP-S6 (branch-prot)  ─ independent             │
WP-S8 (stub consol)  ─ independent (coord w/ bash-refactor)
```

- **WP-S1, WP-S2** have no dependencies — front-load them (M-014 explicitly must not wait for the M-072
  decomposition, per the task constraint; it is a one-line gating change in WP-S2).
- **WP-S3 precedes WP-S4/S5/S6** (they migrate onto the helper) and **WP-S7** (tests exercise it).
- **WP-S9 is last** — it needs both the helper (S3) and the golden-master fixture suite (S7) to be a safe
  refactor.

**Handoffs (named, not duplicated):**
- → `bash-refactor-1.md` (**M-028**): that plan owns the repo-wide `shellcheck` + `bats` CI gate. All WP-S1/S3/S7/S8
  tests **register into that gate** — this plan does not build a separate CI mechanism.
- → `bash-refactor-1.md` (**M-053 / project-root walk-up**): `bin/qa`'s composer-proxy self-parse and the WP-S8
  stub consolidation both need a project-root-from-bin walk. **One** shared helper should serve both; agree its
  location (likely `includes/` for bin/qa reuse) before WP-S8 lands.
- → `bash-refactor-1.md` (**M-010 runner driver / `standardIFS`**): WP-S1's `ci.bash` cleanup only *deletes* the
  dead `standardIFS` copy; the in-process `standardIFS` pattern inside `bin/qa`/`options.inc.bash` is bash-refactor's.
- → `docs-rot` plan (**M-039**): the skills-deployment doc must be rewritten after WP-S9; and (**M-005/M-006**
  fabrications) are docs-plan scope, not here.

**Naming coordination:** the helper library path (`scripts/lib/consumer-write.inc.bash`) and the walk-up helper
location must be agreed with bash-refactor before WP-S3/WP-S8 to avoid two parallel `lib/` conventions.

---

## 6. Decisions for the user

| # | Finding | Decision | Recommendation |
|---|---|---|---|
| **D1** | M-015 | Are php-qa-ci-provided **skills/agents** OWNED (overwrite freely, "never hand-edit") or protected by a signature/backup like the git hook? | **OWNED.** Matches the repo's stated philosophy ("everything is overridable via `qaConfig/`, not by editing the vendored file"). Signature-protecting skills invites divergent local forks that silently miss updates. Document the contract; route through `install_owned_*` (WP-S4). |
| **D2** | M-017 | Branch protection: **GET-merge-PUT** (preserve unmodeled settings) or keep the wholesale PUT but **document it as destructive** + capture prior state? | **GET-merge-PUT** (WP-S6). It is the only option that doesn't silently drop a repo's existing checks/restrictions. Add `--dry-run` + prior-state backup as the safety net. |
| **D3** | M-020 | Daemon version references disagree (`v2.2.0` clone vs `v3.9.0+` venv). Pin **one constant**, or **drop the tag** and point at install docs? | **Single constant** `DAEMON_INSTALL_REF` referenced in both places (WP-S4), or drop the tag entirely — either removes the self-contradiction. Prefer the constant so the "not detected" path installs a version the rest of the script can actually use. |
| **D4** | M-018/M-019 | `setup-claude-qa-agent.bash` overlaps `deploy-skills`' agent deployment. Fix its path (WP-S5) **and keep it**, or **fold** its binary-detection into the OWNED agent template and retire the standalone script? | **Fold + retire** if `php-qa-specialist.md` is a bundled agent (then `deploy-skills` owns it end-to-end). If it must stay standalone (maintainer tool), fix the path per WP-S5. Confirm whether any consumer/doc invokes it directly before retiring. |
| **D5** | M-060 | Orphaned, unregistered `.claude/hooks/php-qa-ci__check-vendor-uncommitted.py` — **document + wire** it, or **delete**? | **Delete**, pending a diff against `git-hooks/pre-commit-check-vendor-uncommitted` — it appears to duplicate that hook's job while being unwired and undocumented. If the diff shows it is the intended *daemon-handler* port (not a dupe), wire + document it instead. Cross-plan: the file is DOCS-axis but couples to the M-013 git-hook, so decide it alongside WP-S1. |
| **D6** | M-063 | `check_php_version()` — **delete** the dead computation or **wire** it into a workflow matrix? | **Delete** (WP-S1). The shipped workflow template is version-agnostic; wiring adds surface for no current benefit. |

---

## 7. Out of scope

- **Bash-core tool runners** (`includes/**`): the runner-driver/metadata consolidation, silent-gate class
  (M-001/M-002/M-003), config derive-before-override (M-009), array-quoting/`eval` in tool invocations
  (M-025/M-026), read-only gaps in `composerChecks`/`phpStrictTypes` (M-027/M-004) — all owned by `bash-refactor-1.md`.
- **Docs corrections** (M-005, M-006, M-039, M-058, etc.) — `docs-rot` plan. This plan only *flags* the doc refresh
  that WP-S9 triggers (M-039) and the OWNED-artefact doc line (WP-S4).
- **PHP internals** of `SkillsDeployPlugin`/`ManagedSource` beyond the two-arg invocation contract — PHP axis.
- **The M-028 CI gate mechanics** — consumed here, built there.
- `scripts/ci-push-ssh-deploy-bundle.bash` and `scripts/write-claude-block.bash` — already graded A; unchanged
  except as reference implementations.
- `scripts/parse-junit-logs.py` — Python, out of bash-review scope.

---

## 8. Verification-readiness summary

Every WP names a concrete test. The load-bearing one is **WP-S7's fixture consumer suite**, which pins
`deploy-skills` behaviour as a golden master so the OWNED migration (S4), the interrupt-window fix (S2), and the
decomposition (S9) are all provably behaviour-preserving. No WP that touches unattended consumer-write code lands
without a fixture assertion covering idempotency **and** interrupt-recovery.
