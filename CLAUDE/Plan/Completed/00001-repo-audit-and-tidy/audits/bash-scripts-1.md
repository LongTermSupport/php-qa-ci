# Audit: Auxiliary Bash Scripts (`scripts/`, `git-hooks/`, `composerScripts/`, non-PHP `bin/*`, `ci.bash`)

## 1. Header & Method

**Scope**: `scripts/*.bash` (7 files), `scripts/parse-junit-logs.py` (sanity only), `ci.bash`,
`git-hooks/pre-commit-check-vendor-uncommitted`, `composerScripts/installUpdateInfection.bash`,
and the bash entries in `bin/` (`composer-require-checker`, `infection`, `php-cs-fixer`, `phpstan` —
`bin/qa` explicitly excluded, covered elsewhere).

**Method**:
1. `shellcheck -x` (v0.9.0) against every bash file.
2. Manual review: correctness, quoting, `set -e`/error handling, destructive operations against
   consumer projects, hardcoded paths/versions/URLs, duplication.
3. Deep-dive on `deploy-skills.bash` (largest, highest blast radius — writes into consumer `.claude/`,
   `.git/hooks/`, `composer.json`, `CLAUDE.md`).
4. Cross-checked `composer.json` `scripts` section and `SkillsDeployPlugin.php` against every script
   in scope to determine actual wiring vs. dead code.
5. One confirmed logic bug (`git-hooks/pre-commit-check-vendor-uncommitted`) was reproduced in an
   isolated `bash -c` repro to verify before reporting.

`scripts/parse-junit-logs.py` (579 lines): syntax-checked only (`ast.parse` clean) — Python, out of
deep bash-review scope per instructions.

## 2. Per-File Scorecard

| File | Lines | Purpose | Shellcheck | Findings | Grade |
|---|---|---|---|---|---|
| `ci.bash` | 27 | Local/CI entrypoint: sets env, runs `bin/qa`, tees log | 4 (1 warn dead-var, 1 warn masked-return, 1 info unquoted, 1 error SC2145) | 3 | C |
| `composerScripts/installUpdateInfection.bash` | 54 | Legacy manual Infection PHAR installer | 4 (same boilerplate as ci.bash) | 4 (incl. dead-code + unverified-download) | F |
| `scripts/ci-push-ssh-deploy-bundle.bash` | 69 | Bundles project SSH deploy keys into a GH Actions secret | clean | 1 (documented, self-flagged duplication) | A |
| `scripts/deploy-skills.bash` | 705 | 7-phase consumer-project provisioner (skills/agents/hooks/git-hook/daemon-config/PHPStan-scaffold/CLAUDE.md) | 1 warn (SC2115) | 6 | D+ |
| `scripts/install-github-actions.bash` | 145 | Installs `.github/workflows/qa.yml` into consumer repo | clean | 2 | B |
| `scripts/setup-branch-protection.bash` | 131 | Applies GitHub branch-protection rules via `gh api` | 3 (SC2015, SC2002, + a benign parser warning) | 3 | C |
| `scripts/setup-claude-qa-agent.bash` | 323 | Generates `.claude/agents/php-qa-specialist.md` | clean | 3 | C |
| `scripts/tool-install.bash` | 145 | PHIVE PHAR + isolated-Rector installer, wired to composer events | clean | 2 | B+ |
| `scripts/write-claude-block.bash` | 139 | Idempotent tag-delimited block injector for `CLAUDE.md` | clean | 0 | A |
| `git-hooks/pre-commit-check-vendor-uncommitted` | 222 | Pre-commit hook blocking dirty/out-of-sync vendor git deps | 3 (info/style) | 2 (1 confirmed logic bug) | B- |
| `bin/composer-require-checker`, `bin/infection`, `bin/php-cs-fixer`, `bin/phpstan` | ~11 each | Redirect stubs telling the user to use `qa -t …` | clean | 1 (duplication, shared across 4 files) | B |

**Coverage**: 12 files/groups reviewed, all in scope. 100% shellchecked. `bin/qa` intentionally
skipped per instructions.

## 3. Findings by Severity

### CRITICAL

None. No finding here rises to "breaks the build for everyone" — the closest candidates
(BS-001, BS-002) are downgraded to MAJOR because their blast radius is bounded (a message-only
corruption, and a state that self-heals within the same script run under normal conditions).

### MAJOR

**BS-001 — `git-hooks/pre-commit-check-vendor-uncommitted:159-161` — `IFS='|||'` does not split on the 3-char delimiter; it silently corrupts the diagnostic message**

```bash
OLD_IFS="$IFS"
IFS='|||'
read -r repo_path locked_commit current_commit <<< "$entry"
IFS="$OLD_IFS"
```

`IFS` is a **character class**, not a literal string — `IFS='|||'` is identical to `IFS='|'`. Given
`entry="vendor/org/pkg|||abcd1234|||ef567890"`, this produces (verified with a live repro):

```
repo_path=[vendor/org/pkg]
locked_commit=[]
current_commit=[|abcd1234|||ef567890]
```

`locked_commit` is always empty (triggering the wrong "not found" branch at line 165 even when
`composer.lock` DOES have an entry) and `current_commit` displays a garbled string with stray pipe
characters instead of the clean SHA. This only corrupts the **user-facing diagnostic** printed to
help a developer fix the problem (lines 164-171) — the actual gating decision (`COMPOSER_OUT_OF_SYNC`
population at lines 111-117, and the exit-1 block) is computed separately with correct string
comparison and is unaffected, so the hook still correctly blocks the commit. But the message a
developer sees to understand *why* is wrong, which undermines the whole point of this carefully
UX-designed hook.

Contrast with the `DIRTY_REPOS` split two blocks earlier (lines 143-144), which correctly uses
parameter-expansion pattern matching (`${entry%%|||*}` / `${entry#*|||}` — these treat `|||` as a
literal 3-char glob pattern, which *is* correct) — i.e. the file contains both the correct and the
broken idiom side by side.

**Fix**: replace the `IFS='|||'`/`read` pair with the same `%%`/`#` pattern already used for
`DIRTY_REPOS`, e.g. `repo_path="${entry%%|||*}"; rest="${entry#*|||}"; locked_commit="${rest%%|||*}"; current_commit="${rest#*|||}"`.

---

**BS-002 — `scripts/deploy-skills.bash:212-325` vs `:131` vs `:490-575` — classic-hook registration runs unconditionally, relies on a later phase to undo it when the daemon is active; an interrupted run leaves consumer `settings.json` pointing at files that were never deployed**

- Phase 1 (line 131) only **copies** the hook `.py` files into the consumer's `.claude/hooks/` when
  `DAEMON_DETECTED == false`.
- Phase 2 (line 214) **registers** those same hooks into the consumer's `settings.json`, gated only on
  `-d "$HOOKS_SOURCE"` (php-qa-ci's *own* source tree, which always has the files) — **not** on
  `DAEMON_DETECTED`. So when the daemon *is* detected, this phase still writes entries like
  `.claude/hooks/php-qa-ci__auto-continue.py` into the consumer's `settings.json`, even though that
  file was deliberately never copied there.
- Phase 4 (lines 490-575) then detects `DAEMON_DETECTED == true` and removes exactly those
  registrations again, a few hundred lines later in the same run.

Net effect on a clean, uninterrupted run is correct. But this script is invoked non-interactively
from a Composer plugin (`SkillsDeployPlugin.php:77`) during `composer install/update` — any
interruption between Phase 2 and Phase 4 (process killed, disk full, `python3` unavailable and `set -e`
aborting mid-script — see BS-004) leaves the consumer's `settings.json` referencing hook commands that
point at files that do not exist on disk. That's a silently broken hook configuration committed into
a real project, with no human in the loop to notice the transient state.

**Fix**: gate the Phase 2 registration loop on `DAEMON_DETECTED == false` (mirroring Phase 1's guard),
so daemon-managed projects never have classic hooks registered in the first place — no register/
unregister dance needed.

---

**BS-003 — `scripts/deploy-skills.bash:106-108,125` — skills and agents are overwritten unconditionally and silently, inconsistent with the script's own overwrite-protection pattern used two phases later**

```bash
rm -rf "$SKILLS_TARGET/$skill_name"
cp -r "$skill_dir" "$SKILLS_TARGET/$skill_name"
...
cp "$agent_file" "$AGENTS_TARGET/$agent_name"
```

Any local edits a consumer made inside a php-qa-ci-provided skill directory or agent file are
destroyed on every `composer update`, with no diff, no backup, and no warning. This is a real
consumer-visible risk given the script runs unattended. It is also **inconsistent with the same
script's own conventions**: the git-hook deployment (Phase 3, lines 342-354) checks a signature
before overwriting and skips + warns if the target is "custom"; the `CLAUDE.md` PHPStan scaffold
(Phase 6, lines 613-621) only writes "if not exists". Skills/agents get neither protection.

**Fix**: either document skills/agents as fully php-qa-ci-owned (never hand-edit — put customisation
in `qaConfig/` overrides only, matching the project's stated design philosophy of "everything is
overridable via config, not by editing the vendored file"), or add the same signature-check pattern
already proven out for git hooks.

---

**BS-004 — `composerScripts/installUpdateInfection.bash` — entirely dead code, hardcoded to a stale version, and downloads an executable PHAR without verifying the signature it goes out of its way to fetch**

- Not referenced by `composer.json` `scripts` (only `tool-install.bash` is wired there), not
  referenced by any other script, doc, or workflow in the repo (confirmed via repo-wide grep).
  `phive.xml` shows Infection is now managed via PHIVE at version `^0.34`, while this dead script is
  hardcoded to `infectionVersion="0.9.0"` (line 14) — over 20 minor versions stale.
- It downloads `infection.phar` **and** `infection.phar.pubkey` from GitHub releases (lines 36-39) but
  never verifies the signature — the one place that would do so (`self-update`, lines 44-47) is
  commented out with `# this doesn't currently work unfortunately`. If anyone ever resurrected this
  script (e.g. copy-pasted as a starting point for a new installer), it silently executes an
  unverified downloaded binary.
- It also writes to `./../bin/infection.phar`, a path that no longer matches how Infection is invoked
  (`bin/infection` is now a stub redirecting to `qa -t infection`; the PHAR lives under
  `vendor-phar/infection.phar`), so even if run manually today it would do nothing useful.

**Fix**: delete the file. It carries zero live functionality and is a latent security/rot trap if
copy-pasted.

---

**BS-005 — `scripts/setup-branch-protection.bash:112-118` — wholesale `PUT` overwrite of branch protection with no diff against existing settings**

```bash
echo "$PROTECTION_JSON" | gh api --method PUT ... "/repos/${REPO}/branches/${BRANCH}/protection" --input -
```

This unconditionally **replaces** the entire branch-protection ruleset (`contexts: ["PHP QA
Pipeline"]` is hardcoded, `restrictions: null`, etc.) with no attempt to read and merge the existing
configuration first, no dry-run, no confirmation prompt. If a repo already has additional required
status checks, extra restricted users/teams, or other protections configured outside this script's
model, they are silently dropped. The script does document a manual undo command at the end, but
nothing restores the *previous* configuration — the prior state isn't captured anywhere.

**Fix**: `gh api GET .../protection` first, merge/diff, and print what will change before the `PUT`
(or at minimum require an explicit `--force` / confirmation when an existing config differs from the
target).

---

**BS-006 — `scripts/setup-claude-qa-agent.bash:9` vs its own `detect_qa_binary()` — hardcoded 4-levels-up vendor path assumption, inconsistent with the dynamic detection used two lines away in the same file**

```bash
PROJECT_ROOT="${SCRIPT_DIR}/../../../.."
```

This assumes php-qa-ci is always installed at exactly `vendor/lts/php-qa-ci/scripts/` (4 fixed path
segments). `detect_qa_binary()` (lines 11-42), by contrast, deliberately reads Composer's
`config."bin-dir"` via `jq` because — per this repo's own `CLAUDE.md` — the bin directory location is
explicitly configurable. Composer's `vendor-dir` is equally configurable (project-level
`config.vendor-dir`, or path-repository installs, or a monorepo layout), and none of that is
accounted for here. A project with a non-default `vendor-dir` gets a `PROJECT_ROOT` pointing at the
wrong place, and every subsequent write in `create_qa_agent()` lands in the wrong location (or fails).

**Fix**: derive `PROJECT_ROOT` the same way `deploy-skills.bash` does — take it as an explicit argument
from the caller (Composer plugin / composer.json script), or walk up from `SCRIPT_DIR` looking for the
nearest `composer.json` that actually requires `lts/php-qa-ci` (the same pattern already used
correctly in `install-github-actions.bash:19-28`).

---

**BS-007 — `scripts/setup-claude-qa-agent.bash:44-56` — `.claude/agents/php-qa-specialist.md` is overwritten unconditionally, unlike its sibling `install-github-actions.bash`**

`create_qa_agent()` calls `cat > "$agent_file"` with no existence check. `install-github-actions.bash`
(the sibling script solving the same class of problem — installing a generated file into a consumer
repo) explicitly checks for an existing file and prompts `Do you want to overwrite it? (y/N)` before
touching it (lines 48-56). This script has no equivalent guard, so any manual customisation of the
generated agent file is silently destroyed on re-run.

**Fix**: adopt the same prompt-before-overwrite pattern as `install-github-actions.bash` for
consistency.

---

**BS-008 — `scripts/deploy-skills.bash:587` vs `:390,410` — self-contradictory daemon version guidance within the same file**

The "daemon not detected" fallback instructions tell the user to `git clone -b v2.2.0
.../claude-code-hooks-daemon.git` (line 587), while ~200 lines earlier the same script's own
YAML-enforcement fallback says "install/upgrade the daemon (v3.9.0+)" for the fingerprinted-venv
feature it depends on (lines 390, 410). A user following the "not detected" path literally installs a
version the rest of the same script already assumes is too old to work correctly.

**Fix**: pin one canonical version reference (ideally read from a single constant/env var used in both
places, or drop the hardcoded tag and point at the latest release / main branch install docs).

### MINOR

**BS-009 — `scripts/deploy-skills.bash:106` — `rm -rf "$SKILLS_TARGET/$skill_name"` (shellcheck SC2115)**. Mitigated in practice by `set -u` at the top of the file (an unset `SKILLS_TARGET` would abort earlier), and the glob loop that produces `skill_name` cannot yield `.`/`..`/empty — but still worth the defensive `${SKILLS_TARGET:?}` shellcheck suggests, since a future refactor could reintroduce the unset-var risk.

**BS-010 — `ci.bash:7` / `composerScripts/installUpdateInfection.bash:7` — dead `standardIFS` variable**. Both files set `standardIFS="$IFS"` without `export`; the value is invoked from a separate process (`bin/qa`, itself an independent script that re-derives its *own* `standardIFS` at `bin/qa:53` and again in `includes/options.inc.bash:7`), so the assignment in these two entrypoint scripts does nothing and is just confusing duplication of a pattern that only matters *inside* `bin/qa`'s own process tree.

**BS-011 — `ci.bash:3` / `installUpdateInfection.bash:3` — `cd $DIR` unquoted (SC2086)**. Would break on a checkout path containing whitespace/glob characters. Low likelihood, trivial fix (`cd "$DIR"`).

**BS-012 — `ci.bash:11,25` / `installUpdateInfection.bash:11,51` — `$(hostname) $0 $@` (SC2145, error-level from shellcheck)**. Inside a single pair of double quotes, `$@` degrades to `$*`-like behaviour (args joined by first `IFS` char) rather than per-argument expansion. Harmless here (purely a decorative log banner), but it's the exact pattern that silently breaks the moment an argument contains spaces.

**BS-013 — `scripts/install-github-actions.bash:64-85,119` — `check_php_version()` result is computed but never used**. `main()` assigns `php_version=$(check_php_version)` (line 119) and never reads `$php_version` again; the function's only real effect is a couple of `echo` side messages. Dead computation — either use the detected version to select a workflow matrix entry (seems to be the original intent) or delete the dead assignment.

**BS-014 — `scripts/install-github-actions.bash:71-74` — project path interpolated directly into inline PHP source**. `\$json = json_decode(file_get_contents('$composer_file'), true);` embeds a bash variable straight into single-quoted PHP source with no escaping. `$composer_file` is built from `$PROJECT_ROOT`, itself derived by walking up from `$PWD` — not attacker-controlled in a normal CI flow, but a directory name containing a single quote (a legal, if unusual, filesystem path component) breaks out of the PHP string and is interpreted as PHP code. Low likelihood, easy fix: pass the path via `$argv` (`php -r '...' -- "$composer_file"` and reference `$argv[1]`), the same safe pattern already used correctly in `git-hooks/pre-commit-check-vendor-uncommitted:63-73`.

**BS-015 — `scripts/setup-branch-protection.bash:116` — predictable, uncleaned `/tmp/protection-result.txt`**. No `mktemp`, no `trap ... EXIT` cleanup (contrast with `ci-push-ssh-deploy-bundle.bash:49-50`, which does this correctly in the same scripts/ directory). On a shared multi-user host this is a predictable-filename race/info-disclosure smell (API response, which could include repo metadata, left world-readable and never removed).

**BS-016 — `scripts/setup-branch-protection.bash:112-118` — `A && B || C` pattern (shellcheck SC2015)**, plus `cat file | jq ...` useless-cat (SC2002) at the same line. Style-level; the outer `set -o pipefail` (line 3, inherited from `set -euo pipefail`) makes the pipeline's exit code correct, but the `&&/||` idiom is fragile if the `echo` "success" branch is ever changed to something fallible.

**BS-017 — `scripts/tool-install.bash:107,110` — `eval "phive ... $TRUST_KEYS_ARG"`**. `TRUST_KEYS_ARG` is built entirely from a hardcoded internal array (`TRUSTED_KEYS`), so there's no live injection vector today — but `eval` is exactly the pattern that turns into one the moment someone adds a variable sourced from outside the script. Trivially avoidable with an array (`phiveArgs+=(--trust-gpg-keys "$(IFS=,; echo "${TRUSTED_KEYS[*]}")")`) and no `eval`.

**BS-018 — `scripts/tool-install.bash:63` — `if ! which phive 1>&2`**. Redirects `which`'s stdout to stderr rather than discarding it — on the *success* path this prints the resolved `phive` binary path to stderr for no reason. Cosmetic; `command -v phive >/dev/null 2>&1` is the idiomatic check (and avoids depending on `which` being installed at all).

**BS-019 — `git-hooks/pre-commit-check-vendor-uncommitted:92` — `relative_path="${repo_dir#$PROJECT_ROOT/}"` (SC2295)**. Unquoted expansion inside `${...#...}` is interpreted as a glob pattern, not a literal string; a `PROJECT_ROOT` containing `*`, `?`, or `[` would strip more or less than intended. Exotic in practice (checkout paths rarely contain glob metacharacters) but a one-line fix: `${repo_dir#"$PROJECT_ROOT"/}`.

### INFO

**BS-020 — Four near-identical redirect stubs**: `bin/composer-require-checker`, `bin/infection`,
`bin/php-cs-fixer`, `bin/phpstan` are each ~11 lines, and 8 of those 11 lines are byte-identical
across all four (the `COMPOSER_RUNTIME_BIN_DIR` → `composer.json` walk-up + `qaCmd` derivation). Only
the tool name and one or two example-command echo lines differ. See §5.

**BS-021 — `scripts/deploy-skills.bash` mixes 5 separate inline Python heredocs (hook migration,
hook registration, daemon YAML enforcement, classic-hook cleanup, composer.json autoload patch) with
bash orchestration across 7 phases in one 705-line file.** Each phase is individually reasonable; the
aggregate is a script doing config-file surgery across `settings.json`, `hooks-daemon.yaml`,
`composer.json`, `CLAUDE.md`, and the filesystem, all in one place with no unit tests. See §5.

**BS-022 — `scripts/deploy-skills.bash` Phase 2's bare `python3` invocations (lines 145, 232, 516,
639) have no `command -v python3` guard**, unlike Phase 4's YAML step, which carefully resolves a
daemon-venv-specific interpreter and explicitly refuses to fall back to system Python. Given `set -e`,
a missing `python3` here aborts the whole deployment (partially applied — reinforces BS-002's
half-state risk) with a bare "command not found" rather than the informative errors the script uses
everywhere else.

## 4. Risk Section — Operations That Write Into CONSUMER Projects

| Script | Consumer-side target | Guarded? | Failure mode if interrupted/wrong |
|---|---|---|---|
| `deploy-skills.bash` | `.claude/skills/*`, `.claude/agents/*.md` | **No** — unconditional `rm -rf` + `cp` (BS-003) | Local customisations silently destroyed on every `composer update` |
| `deploy-skills.bash` | `.claude/settings.json` (hook registrations) | Partial — idempotent JSON merge, but registration/deregistration split across two phases (BS-002) | Dangling hook registrations pointing at undeployed files if the run is interrupted between phases |
| `deploy-skills.bash` | `.git/hooks/pre-commit` | **Yes** — signature check before overwrite | None identified — this is the model the other write sites should follow |
| `deploy-skills.bash` | `.claude/hooks-daemon.yaml` | Partial — only reachable with a daemon-venv Python; silently skips (with a warning) otherwise | Required handlers stay unenforced in that environment; no data loss |
| `deploy-skills.bash` | `composer.json` (autoload-dev) | Partial — checks for existing key before adding | Full-file `json.dumps` re-serialization; low risk of formatting churn, no data loss |
| `deploy-skills.bash` | `CLAUDE.md` | **Yes** — delegates to `write-claude-block.bash`, which fails loudly on multiple/malformed blocks rather than corrupt | Best-guarded write path in the whole script |
| `setup-branch-protection.bash` | GitHub repo branch-protection API (remote, not filesystem) | **No** — wholesale `PUT`, no diff against existing config (BS-005) | Silently drops any pre-existing protection settings the script doesn't model |
| `setup-claude-qa-agent.bash` | `.claude/agents/php-qa-specialist.md` | **No** — unconditional overwrite (BS-007) | Manual customisation silently destroyed |
| `install-github-actions.bash` | `.github/workflows/qa.yml` | **Yes** — prompts before overwrite | Good model, but interactive-only (`read -p`) — will hang/behave oddly if ever run non-interactively |
| `ci-push-ssh-deploy-bundle.bash` | GitHub repo secret `CI_SSH_DEPLOY_BUNDLE` (remote) | N/A (secret is fully replaced by design, re-runnable by construction) | None — this is the intended idempotent behaviour, well-documented |
| `git-hooks/pre-commit-check-vendor-uncommitted` | Nothing written; read-only gate | N/A | BS-001 corrupts its own diagnostic output, not consumer state |

**Overall pattern**: the script that gets the write-safety story right (`write-claude-block.bash`,
plus the git-hook signature check inside `deploy-skills.bash`) is the exception, not the rule. Three
separate write sites across two scripts (BS-003, BS-005, BS-007) perform unconditional overwrites of
content a consumer could plausibly have hand-edited, with three different justifications and no shared
helper — this is the same problem solved three different ways (once well, twice not at all).

## 5. Duplication / Consolidation Opportunities

1. **`bin/composer-require-checker`, `bin/infection`, `bin/php-cs-fixer`, `bin/phpstan`** (BS-020):
   extract the shared `COMPOSER_RUNTIME_BIN_DIR` → project-root → `qaCmd` derivation (8 of 11 lines in
   each file) into one sourced helper or a single parameterised script (`bin/_qa-redirect-stub.bash
   "$0-name" "phpstan -p src/Service"`), keeping only the tool-specific echo lines per file.

2. **`ci.bash` and `composerScripts/installUpdateInfection.bash`** share near-identical boilerplate
   (`readonly DIR=...`, `cd $DIR`, `standardIFS`, the `$(hostname) $0 $@` banner) — moot for the
   latter since BS-004 recommends deleting it outright, but if any future entrypoint script is added
   in this style, factor the banner/IFS boilerplate into one sourced snippet rather than copy-pasting
   a fourth time (`bin/qa` and `includes/options.inc.bash` already have their own independent copies
   of the `standardIFS` pattern).

3. **Overwrite-protection**: three different "is this safe to overwrite" strategies exist across the
   scripts in scope — signature-comment check (git hook in `deploy-skills.bash`), existence-check +
   `read -p` prompt (`install-github-actions.bash`), and "no check at all" (skills/agents in
   `deploy-skills.bash`, the agent file in `setup-claude-qa-agent.bash`). Worth converging on one
   shared helper (e.g. extend `write-claude-block.bash`'s idempotent-block pattern, or a small
   `safe-install-file.bash` used everywhere a generated artefact lands in a consumer repo).

4. **`deploy-skills.bash` itself** (BS-021) is the biggest consolidation target: 7 independently
   understandable responsibilities (skills/agents/hooks copy, hook-name migration, hook registration,
   git-hook deploy, daemon-config enforcement + classic-hook teardown, PHPStan scaffold, `CLAUDE.md`
   block) living in one 705-line file mixing bash and 5 inline Python heredocs. Splitting into
   per-phase scripts (sourced or invoked in sequence by a thin orchestrator) would make BS-002's
   partial-failure risk far easier to reason about and to unit-test — right now none of the 7 phases
   has any test coverage (confirmed: no references to `deploy-skills.bash` outside docs and the
   Composer plugin that invokes it).

## 6. Coverage Statement

All files listed in the assigned scope were read in full and shellchecked, except:
- `bin/qa` — explicitly excluded per instructions (covered elsewhere).
- `scripts/parse-junit-logs.py` — Python, sanity-only (`ast.parse` succeeded, 579 lines, not
  shellchecked or manually reviewed beyond that, per instructions).

No files in scope were skipped. `composer.json` `scripts` section was checked against every script in
scope: only `scripts/tool-install.bash` is wired to composer events (`post-install-cmd`,
`post-update-cmd`); `scripts/deploy-skills.bash` is invoked separately via
`src/ComposerPlugin/SkillsDeployPlugin.php`; `install-github-actions.bash`,
`setup-branch-protection.bash`, `setup-claude-qa-agent.bash`, and `ci-push-ssh-deploy-bundle.bash` are
standalone maintainer/consumer-invoked utilities with no composer-event wiring (by design — none of
these are documented as auto-run); `composerScripts/installUpdateInfection.bash` has **no** wiring
anywhere, confirming BS-004.
