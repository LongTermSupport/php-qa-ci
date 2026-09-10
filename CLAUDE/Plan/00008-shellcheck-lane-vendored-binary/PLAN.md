# Plan 00008: shellcheck lane vendored binary

**Status**: In Progress
**Created**: 2026-09-10
**Owner**: Joseph Edmonds
**Priority**: Medium
**Recommended Executor**: Opus
**Execution Strategy**: Single-Threaded

## Overview

php-qa-ci's premise is that local QA and CI run **the same checks**, so a green
`bin/qa` means a green pipeline. ShellCheck breaks that premise: it runs only as a
separate job in `.github/workflows/ci.yml`, and `bin/qa` has never linted a shell
script. The consequence was observed, not theorised — `php8.5` sat red on an SC2034
finding in `scripts/build-phar.bash` while every local full pipeline reported exit 0,
and every PR targeting the branch was unmergeable until it was found by reading a CI
log (Plan 00007's merge round; fixed in `67bd110`).

The gap is exactly one tool. `ci.yml` has two jobs: `shellcheck`, run directly, and
`qa`, which runs `ci.bash` → `bin/qa`. Closing it means a `shellcheck` lane in the
pipeline and **deleting** the separate CI job, so there is one mechanism rather than
two that can drift.

Version drift is the second half of the problem. Three ShellCheck versions were in
play at once: `0.9.0` pinned in the CI job, `0.10.0` from the development host's
package manager, and `0.11.0` upstream. A check whose version depends on where it runs
is not the same check. The binary is therefore vendored and pinned, the way the PHARs
already are, so every environment runs one build.

## Goals

- `bin/qa` lints shell scripts, so a local full pipeline exit 0 means CI's shell check
  passes too.
- One pinned ShellCheck build runs everywhere — CI, developer host, container — with
  no dependency on what the host happens to have installed.
- The shell surface is **discovered**, not hand-listed, so a new script is checked the
  day it lands.
- A project can narrow or extend what is checked without forking the lane.
- `ci.yml`'s standalone `shellcheck` job is deleted, leaving one mechanism.

## Non-Goals

- Consolidating the self-built PHARs into a single aggregate PHAR. Considered and
  **deferred by owner ruling** — see Decision 4; they stay separate for now.
- Shipping ShellCheck as a PHAR. It is a native binary, not PHP bytecode; see
  Decision 2.
- Changing any ShellCheck severity or rule set beyond what CI already asserts
  (`-S warning`). Matching today's behaviour is the point; tightening is a later
  decision.

## Context & Background

- The defect that exposed the gap: `scripts/build-phar.bash:116`, SC2034
  "BUILD_VENDOR appears unused". Not dead code — `build/rector/prepare.bash` reads it,
  but it is `source`d so ShellCheck cannot see the use. Fixed by exporting, not
  deleting, in `67bd110`.

- CI's current invocation, which the lane must reproduce at minimum:

  ```bash
  mapfile -t shellFiles < <(find scripts qaConfig -type f \( -name '*.bash' -o -name '*.sh' \) | sort)
  shellcheck -S warning \
    bin/composer-require-checker bin/infection bin/php-cs-fixer bin/phpstan \
    bin/lib-redirect-stub.inc.bash \
    ci.bash \
    git-hooks/pre-commit-check-vendor-uncommitted \
    "${shellFiles[@]}"
  ```

  Note the four `bin/` wrappers are named individually: the list is hand-maintained
  and silently misses anything new.

- Upstream: ShellCheck `v0.11.0`, official static `linux.x86_64` build, 2.4 MB as
  `.tar.xz`. Static, so it has no runtime library dependency on the host.

- `composer.json` already wires the maintainer update path:
  `post-install-cmd → ./scripts/tool-install.bash`, and
  `post-update-cmd → ./scripts/tool-install.bash update`. `update-deps.yml` calls the
  same script on a schedule. That is where a ShellCheck refresh belongs.

- `assertwell/shellcheck` was evaluated as the "PHP wrapper" route and **rejected**:
  it bundles nothing. It is a ~3 KB bash script that locates a system ShellCheck and
  prints install instructions when absent, which would reproduce the very divergence
  this plan removes.

## Tasks

### Phase 1: Vendor the binary

- [x] ✅ **Task 1.1**: Vendor the pinned static ShellCheck and record the version in
  one place, so the lane and the updater read the same pin. `vendor-bin/shellcheck`
  (v0.11.0, static `linux.x86_64`, 16 MB) with `vendor-bin/shellcheck.version` as the
  pin and `vendor-bin/shellcheck.LICENSE.txt` for the GPLv3 obligation. The pin is a
  file rather than a PHP constant so the Bash updater can rewrite it without rewriting
  PHP source; `ShellCheckBinary` reads it.
- [x] ✅ **Task 1.2**: `bin/shellcheck-install` verifies the binary and its pin on install
  and fails loudly naming both paths; on `update` it re-resolves to the newest GitHub
  release, gated on phive being present for the same reason the PHAR rebuild is gated on
  Box — a consumer's `composer update` must not reach out to GitHub or rewrite a
  committed artefact. A rate limit or outage leaves the pin alone rather than failing the
  run. Written in PHP per the owner's "real scripting is PHP" ruling (Decision 5):
  `InstallDecider` is the pure decision table with a test per branch,
  `ShellCheckInstaller` does the HTTP fetch and a `PharData` extract with nothing shelled
  out, and `scripts/tool-install.bash` contributes one `php` call.
- [x] ✅ **Task 1.3**: Verified: `ldd` reports "not a dynamic executable", it runs with
  `PATH` pointing nowhere, and `--version` reports the pin. The host's own ShellCheck
  is 0.10.0, so the lane reporting v0.11.0 also proves it is not reaching for the
  system copy.

### Phase 2: The lane

- [x] ✅ **Task 2.1**: `ShellCheckTool` lane in the linting phase, identifier
  `phpqaci.shellCheck`, aliases `-t sc` / `-t shellcheck`, path-supporting (`-p`).
  - [x] ✅ Failing test: a fixture finding fails the lane, naming file, line and SC code.
  - [x] ✅ Passing test: a clean fixture passes.
  - [x] ✅ Exit 1 is findings; anything else is a **crash** — ShellCheck returns 2 when
    it cannot read a file, and calling that a finding would hide a broken checkout.
    A version mismatch against the pin is also a crash. A non-git project skips with a
    reason rather than passing.
- [x] ✅ **Task 2.2**: Discovery is every **git-tracked** file with a shell extension
  (`.bash`, `.sh`) or a shell shebang (`sh`, `bash`, `dash`, `ksh`, directly or through
  `env`).
  - [x] ✅ Tests for both routes, six shebang spellings, and five files that are neither.
  - [x] ✅ Untracked files are never candidates; a tracked file missing from a dirty
    working tree is skipped rather than handed over, since that would be exit 2.
- [x] ✅ **Task 2.3**: `withShellCheckGlobs(...)` in `qaConfig/qa.php` replaces discovery;
  `withIgnoredPaths()` subtracts from whichever set was produced. A glob list matching
  nothing **crashes** — silence from a hand-written list is a broken configuration.
  - [x] ✅ Tests: default discovery, narrowed globs, ignored-path subtraction, an
    ignored path matching on a directory boundary rather than a string prefix.
- [x] ✅ **Task 2.4**: Registered in `ToolRegistry`, `ShippedTools`, the three
  characterisation goldens, `PipelineTest`'s order golden, `docs/tools/shellCheck.md`,
  the identifier index, `CLAUDE.md`, `docs/pipeline.md` and `docs/upgrading-to-8.5.md`.

### Phase 3: Collapse the duplication

- [x] ✅ **Task 3.1**: The `shellcheck` job is gone from `.github/workflows/ci.yml`,
  leaving a comment where it was so the next reader knows the check moved rather than
  vanished.
  - [x] ✅ **The `ShellCheck (severity=warning)` context reports again — fixed, not removed.**
    A push to `php8.5` reports `2 of 2 required status checks are expected`, and `ci.yml` had
    exactly two jobs, so one required context is `ShellCheck (severity=warning)` — which after
    the job deletion never reported, blocking every PR for anyone without bypass.
    **Owner ruling: fix it, do not remove it.** Dropping the context from the ruleset would
    trade a check that cannot report for no check at all, on the branch whose whole premise is
    that a green pipeline means a green branch. So `ci.yml` carries a `shellcheck` job again —
    but it runs `bin/qa -t shellCheck`, the shipped lane over the pinned
    `vendor-bin/shellcheck`, not a separately-installed ShellCheck. One mechanism, invoked
    twice; the three-way version drift this plan removed cannot come back.
    The ruleset itself still cannot be read from here (`repos/.../rules/branches/php8.5`
    returns only `deletion`, `non_fast_forward` and `pull_request`, so the rule is org-level
    and `orgs/LongTermSupport/rulesets` needs `admin:org`) — but it no longer needs to be.
- [x] ✅ **Task 3.2**: Equivalence proved on the same tree before deleting: CI's exact
  old invocation exits 0, and the lane exits 0 over 72 files (against CI's 29). The
  known-positive holds — the pre-fix `scripts/build-phar.bash` from `67bd110^` fails on
  SC2034, the fixed one passes.

## Dependencies

- Depends on: `67bd110` (the SC2034 fix). Without it the lane goes red on php-qa-ci's
  own source the moment it is switched on — which is the correct behaviour, but it
  must be fixed first rather than discovered as a regression.
- Related: Plan 00007 (the `opcache` lane established the lane-shaped precedent and the
  "nothing checked it is a crash, not a pass" rule).

## Technical Decisions

### Decision 1: A new tool is justified

**Context**: [CLAUDE/tool-boundaries.md](../../tool-boundaries.md) requires a new tool to
answer yes to all three questions. **The answers**:

1. *Does it answer a question no existing tool asks?* Yes — nothing in the pipeline
   reads a shell script. `phpLint` parses PHP only.
2. *Would a user reasonably type its name?* Yes — `bin/qa -t sc` while working on
   `scripts/`.
3. *Does it stand on its own in the help text?* Yes — "lint shell scripts" needs no
   reference to a defect, a version or an incident.

**Decision**: ship it as a tool, not as an assertion inside another lane.
**Date**: 2026-09-10

### Decision 2: Vendor the static binary; it cannot be a PHAR

**Context**: every third-party tool here arrives as a PHAR, via phive or self-built
under `build/`. **Why ShellCheck cannot**: a PHAR is a PHP archive executed by the PHP
runtime; ShellCheck is a compiled Haskell executable. Boxing it would mean a PHAR that
extracts a binary to a temp path and execs it — a second mechanism, for no gain.
**Options considered**: (a) require it on `PATH` and skip when absent — rejected, a
skipped check is not the same check and rebuilds the divergence; (b) `assertwell/shellcheck`
— rejected, it bundles nothing (see Context); (c) commit the official static build.
**Decision**: (c). It matches the spirit of the existing convention — a committed,
self-contained executable artefact that consumers simply run, with nothing fetched at
run time — even though the artefact is not a PHAR. Size is not a concern (2.4 MB xz
against 55 MB of existing `vendor-phar/`), and owner has ruled that a consistent
ShellCheck across QA and CI is worth it. **Date**: 2026-09-10

### Decision 3: Discover by git-tracked shebang, override by glob

**Context**: CI's list names four `bin/` wrappers individually and finds the rest with
a two-directory `find`, so a new script outside `scripts/` or `qaConfig/` is silently
unchecked. **Decision**: the default set is every git-tracked file with a shell
extension or a shell shebang — the repository is the contract, so untracked scratch is
excluded automatically. A project overrides with an explicit glob list in
`qaConfig/qa.php`, consistent with `withCheckedPaths()` / `withYamlDirectories()`.
**Consequence to expect**: the lane will surface findings in files CI has never looked
at. Those are real and get fixed, not excluded. **Date**: 2026-09-10

### Decision 4: The self-built PHARs stay separate

**Context**: consolidating `rector`, `phpcpd`, `composer-dependency-analyser` and
`dead-code-detector` into one aggregate PHAR was proposed, on the grounds that
maintaining four of our own is silly. **What the evidence showed**: the four build
graphs share **zero** packages, so dependency conflict is largely a non-issue — but
`build/dead-code-detector/composer.json` carries `replace: {"phpstan/phpstan": "*"}`
because it is a PHPStan *extension* that must not bundle PHPStan, while
`build/rector/composer.lock` requires `phpstan/phpstan@2.2.13`. In one graph that
`replace` applies globally and strips PHPStan from Rector. The size argument is also
weak: Rector is 18 MB of the 18.3 MB total, so merging the other three saves ~210 KB.
**Decision**: keep them separate for now (owner ruling). Revisit only if maintenance
cost actually bites, and treat `dead-code-detector` as out of scope for any future
merge since it is an extension rather than a CLI. **Date**: 2026-09-10

### Decision 5: the updater is PHP, not Bash

**Context**: the updater was first written as ~90 lines of Bash inside
`scripts/tool-install.bash`, next to the PHIVE and Box blocks it resembles. The owner ruled
mid-build that real scripting should be PHP wherever possible. **Why the ruling is right
here specifically**: those 90 lines held a release lookup, a JSON parse, an archive unpack
and a five-way decision, none of which any test or PHPStan run could see — untested Bash
logic inside a tool whose entire purpose is catching untested logic. **Decision**: the
decision table is `InstallDecider` (pure, one test per branch), the I/O is
`ShellCheckInstaller`, the entry point is `bin/shellcheck-install`, and Bash keeps only the
invocation. Shipping `.tar.gz` rather than `.tar.xz` follows from it: `PharData` reads gzip,
so nothing is shelled out at all. The rule is recorded in `CLAUDE.md` as an owner ruling
rather than left in this plan. **Date**: 2026-09-10

## Success Criteria

- [x] A shell script with a known finding fails `bin/qa`, naming file, line and SC code.
- [x] The pre-fix `scripts/build-phar.bash` fails the lane; the fixed one passes.
- [x] The vendored binary runs on a host with no system ShellCheck, and its version
  matches the pin.
- [x] `composer update` in php-qa-ci re-resolves ShellCheck to the newest release.
- [x] The lane reports the same findings as the old CI invocation on the same tree.
- [x] `ci.yml` no longer installs a second ShellCheck. Its `shellcheck` job runs the shipped
  lane, so the required context reports again from one mechanism — see Task 3.1.
- [x] Full unfiltered pipeline exit 0 (Covered Code MSI 82%).

## Risks & Mitigations

| Risk                                                               | Impact | Probability | Mitigation                                                                                                         |
| ------------------------------------------------------------------ | ------ | ----------- | ------------------------------------------------------------------------------------------------------------------ |
| Discovery finds files CI never checked, so the lane lands red      | Med    | High        | Expected, not a defect. Sweep and fix before switching the lane on; that sweep is Task 3.2                         |
| Deleting the CI job while the branch rule still requires its check | High   | High        | Happened. Resolved by restoring the job around the shipped lane, not by weakening the rule; see Task 3.1           |
| The vendored binary is wrong for a consumer's architecture         | Med    | Low         | Pin `linux.x86_64` and fail loudly naming the architecture, rather than silently skipping                          |
| A pinned binary goes stale                                         | Low    | Med         | Task 1.2 puts the refresh on the existing `post-update-cmd` path that `update-deps.yml` already runs on a schedule |

## Delivery & Milestones

- `b191a1b` — Phases 1, 2 and 3: the vendored pinned binary, the `shellCheck` lane with
  git-tracked discovery and the glob override, the PHP updater, and the deletion of the
  duplicate CI job. Full unfiltered pipeline exit 0.
- The `shellcheck` CI job returns, running `bin/qa -t shellCheck` so the required context
  reports from the shipped lane rather than a second pinned ShellCheck (Task 3.1).
