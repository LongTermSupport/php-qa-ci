# Plan 00008: shellcheck lane vendored binary

**Status**: Not Started
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

- [ ] ⬜ **Task 1.1**: Vendor the pinned static ShellCheck and record the version in
  one place, so the lane, CI and the updater all read the same constant.
- [ ] ⬜ **Task 1.2**: Extend `scripts/tool-install.bash` so `composer install` verifies
  the binary is present and `composer update` re-resolves it to the newest release,
  mirroring how it already treats phive PHARs. A missing binary on install must fail
  loudly with the fetch command, never silently skip.
- [ ] ⬜ **Task 1.3**: Verify the vendored build runs on a host with no system
  ShellCheck, and that its `--version` matches the pin.

### Phase 2: The lane

- [ ] ⬜ **Task 2.1**: `ShellCheckTool` lane in the linting phase, identifier
  `phpqaci.shellCheck`, alias `-t sc`, path-supporting (`-p`).
  - [ ] ⬜ Failing test: a fixture script with a known finding fails the lane, naming
    file, line and SC code.
  - [ ] ⬜ Passing test: a clean fixture passes.
  - [ ] ⬜ A file the lane could not read, or a ShellCheck crash, is a **crash**, never
    a pass — the same rule Plan 00007's lane learned.
- [ ] ⬜ **Task 2.2**: Shell-file discovery. Default is every **git-tracked** file that
  either carries a shell extension (`.bash`, `.sh`) or opens with a shell shebang, so
  extensionless wrappers like `bin/phpstan` are found without being named.
  - [ ] ⬜ Tests for both discovery routes and for a file that is neither.
  - [ ] ⬜ Untracked and ignored files are not checked; the working tree is not the
    contract, the repository is.
- [ ] ⬜ **Task 2.3**: Per-project override in `qaConfig/qa.php` —
  `withShellCheckGlobs(...)` to replace the default set and the existing ignored-paths
  mechanism to subtract from it. Absent config keeps the discovered default.
  - [ ] ⬜ Tests: default discovery, narrowed globs, ignored-path subtraction.
- [ ] ⬜ **Task 2.4**: Register everywhere at once — `ToolRegistry`, `ShippedTools`,
  the characterisation goldens, `PipelineTest`'s order golden, `docs/tools/shellCheck.md`,
  the identifier index, `CLAUDE.md` and `docs/pipeline.md`.

### Phase 3: Collapse the duplication

- [ ] ⬜ **Task 3.1**: Delete the `shellcheck` job from `.github/workflows/ci.yml`. The
  `qa` job runs `ci.bash` → `bin/qa`, which now covers it. Confirm the required-checks
  branch rule on `php8.5` is updated to match, or the branch blocks on a check that no
  longer reports.
- [ ] ⬜ **Task 3.2**: Prove the equivalence before deleting: the lane must report the
  same findings as the old CI invocation on the same tree, including the pre-fix
  `build-phar.bash` as a known-positive fixture.

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

## Success Criteria

- [ ] A shell script with a known finding fails `bin/qa`, naming file, line and SC code.
- [ ] The pre-fix `scripts/build-phar.bash` fails the lane; the fixed one passes.
- [ ] The vendored binary runs on a host with no system ShellCheck, and its version
  matches the pin.
- [ ] `composer update` in php-qa-ci re-resolves ShellCheck to the newest release.
- [ ] The lane reports the same findings as the old CI invocation on the same tree.
- [ ] `ci.yml` no longer has a `shellcheck` job, and the branch rule matches.
- [ ] Full unfiltered pipeline exit 0.

## Risks & Mitigations

| Risk                                                               | Impact | Probability | Mitigation                                                                                                         |
| ------------------------------------------------------------------ | ------ | ----------- | ------------------------------------------------------------------------------------------------------------------ |
| Discovery finds files CI never checked, so the lane lands red      | Med    | High        | Expected, not a defect. Sweep and fix before switching the lane on; that sweep is Task 3.2                         |
| Deleting the CI job while the branch rule still requires its check | High   | Med         | Task 3.1 pairs the deletion with the rule change; a required check that never reports blocks the branch forever    |
| The vendored binary is wrong for a consumer's architecture         | Med    | Low         | Pin `linux.x86_64` and fail loudly naming the architecture, rather than silently skipping                          |
| A pinned binary goes stale                                         | Low    | Med         | Task 1.2 puts the refresh on the existing `post-update-cmd` path that `update-deps.yml` already runs on a schedule |

## Delivery & Milestones

- <!-- delivery commit hashes, added as phases land -->
