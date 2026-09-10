# Wave 2 — bash-core hygiene: implementation notes

Axis: BASH-CORE. Branch: php8.4. Landed by the bash-core wave-2 agent.

## What changed (by M-ID)

- **M-023** — Deleted the dead per-tool lock hooks `toolStart` / `toolComplete`
  / `toolFailed` from `includes/generic/lock.inc.bash` (grep-verified zero
  callers across bin/includes/scripts). Investigated the LIVE full-run timing
  path and found a small, provable fix: `releaseLock` guarded the timing record
  behind `[[ -n "$tool" ]]`, so a full pipeline run (which stores an EMPTY tool
  in the lock file) never recorded a timing — its ETA was frozen at the
  15-minute default forever. The command key derives from tool+path
  (`getCommandKey`), and an empty tool + empty path keys on `:full-suite`, which
  is EXACTLY what `calculateEta` reads back for a full run. Removed the guard so
  full-suite runs now record. `timing.inc.bash` itself was left untouched (its
  `recordCommandTiming` is live and correct); the recording path is NOT dead, so
  it was fixed, not deleted.
- **M-044** — Deleted the redundant CI re-detection block at the bottom of
  `includes/generic/setConfig.inc.bash`. Verified bin/qa establishes `CI`
  (honouring explicit `CI=true`, `CLAUDECODE=1`, and the no-TTY case) BEFORE it
  sources setConfig via `runTool setConfig`, so the block only ever recomputed
  the already-set value — a no-op that could only drift. Replaced with a comment
  documenting where CI is authoritatively set.
- **M-045** — Deleted the ~60 lines of commented-out auto-fix code in
  `includes/generic/composerRequireChecker.inc.bash`.
- **M-046** — Deleted the dead `TRAVIS`/`phpenv config-rm` branch in
  `includes/generic/allTestingTools.inc.bash`.
- **M-025** — Quoted unquoted `${array[@]}` expansions passed to tools:
  `phpstan.inc.bash`, `phpunit.inc.bash`, `phpCsFixer.inc.bash`,
  `phploc.inc.bash`, `setPaths.inc.bash`, `includes/symfony/twigLint.inc.bash`,
  `includes/symfony/yamlLint.inc.bash`. Two arrays needed RESTRUCTURING before
  quoting was safe (they held a sentinel element that would have become a
  literal argument once quoted): `phpunit.inc.bash` `paratestConfig=` (scalar
  empty string → `paratestConfig=()`) and `extraConfigs=(" ")` (leading
  single-space element → `extraConfigs=()`). Behaviour verified identical via
  the `-t unit` smoke run (no stray empty/space arg in the traced invocation).
- **M-026** — Removed the `eval` in the phpstan crash path (it referenced
  `$pathsStringArray`, a variable only ever defined in `phpLint.inc.bash` — so
  under `-t stan` standalone it was UNDEFINED and would trip `set -u`; in a full
  run it leaked in as a global holding an eval-echo-laundered value). Rewrote it
  to use the real quoted array `"${pathsToCheck[@]}"`. Deleted the now-unused
  `pathsStringArray=($(IFS=" " eval 'echo ...'))` laundering line at the top of
  `phpLint.inc.bash` (the real lint invocation already used
  `"${pathsToCheck[@]}"`). `pathsStringArray` now has zero references repo-wide.
- **M-027** — `composerChecks.inc.bash` no longer mutates in read-only runs:
  when `qaReadOnly=true` it runs `composer normalize --dry-run` and fails via the
  standard `reportReadOnlyWouldModify "Composer Normalize" "com"` if it would
  change composer.json. `diagnose` stays (informational). `dump-autoload` stays
  with a deliberate comment noting it only regenerates generated autoloader
  artefacts under vendor/, which is acceptable in read-only. Also removed the
  `set +e` around diagnose (replaced with an `if !` capture) so nothing is
  error-hidden.
- **M-047** — The phpunit coverage run and the infection coverage-generation run
  both invoke the Xdebug-enabled binary directly (bypassing `phpNoXdebug`, which
  applies `phpqaMemoryLimit` internally), so the global memory limit was not
  applied. Both now pass `-d memory_limit="${phpqaMemoryLimit:-4G}"` explicitly.
  Confirmed in the `-t unit` smoke: `/usr/bin/php -d memory_limit=4G -f bin/phpunit ...`.
- **M-048** — Quoted the `$(which composer)` command substitutions in
  `composerChecks.inc.bash`.
- **M-049** — `branchNamePolicy.inc.bash`: the err-log tempfile is now created
  under `${varDir:-$projectRoot/var/qa}` instead of shared `/tmp`, and the
  end-of-run cleanup removes ONLY this run's file (no more `rm -f
  /tmp/branchNamePolicy.*.err` shared glob). The captured git-probe diagnostics
  are now surfaced (`cat` to stderr) when the log is non-empty, so genuine git
  failures are no longer swallowed.
- **M-051** — `yamlLint.inc.bash`: fixed the `yamlLintExistCode` → `yamlLintExitCode`
  typo, added a guard that filters `yamlDirectories` to those that exist and
  skips cleanly when none do (lint:yaml errors on a missing config/ dir), and
  quoted the directory array. Rewrote the retry loop to capture the exit code
  via an `if` condition instead of `set +e` toggling.
- **M-075** — `functions.inc.bash`: `phpNoXdebug` now PRESERVES the caller's
  xtrace setting (records whether `-x` was on via `$-`, and only `set +x` when it
  was off), instead of unconditionally disabling tracing a caller had turned on
  (e.g. phpunit's own `set -x` block). `archiveToolLog` now cleans up stale
  `.warned_high_count_<pid>` marker files at the top of the function — a marker
  is stale when its PID is no longer a live process (checked via `/proc/<pid>`,
  avoiding any stderr redirect); the current run's marker is preserved.
- **Shellcheck** — `shellcheck -S error` is now clean across `bin/qa` +
  `includes/**/*.bash` + `includes/*.bash`. The only error-severity findings were
  two SC2145 in `bin/qa` (`$@` mixed into a display string) — fixed to `$*`.
  Added a `shellcheck` job (severity=error) to `.github/workflows/ci.yml`
  covering `bin/qa`, `ci.bash`, `includes/`, and `scripts/`. Verified `ci.bash`
  and all of `scripts/` already pass `-S error`, so the new gate is green on
  landing. Warning-level findings were intentionally left for a later wave.

## Verification (all green)

- `bash -n` on every changed file — OK.
- `shellcheck -S error bin/qa ci.bash includes/** scripts/**` — exit 0.
- `.github/workflows/ci.yml` — valid YAML; the exact shellcheck command the job
  runs was simulated locally (39 shell files) and passed.
- `bin/phpunit --no-coverage tests/Small/` — 149/149 OK.
- `bin/qa -t lint` — exit 0 (no syntax errors); xtrace preserve/restore visibly
  working in the trace.
- `bin/qa -t stan` — exit 0 (No errors); quoted `"${pathsToCheck[@]}"` expands
  correctly.
- `bin/qa -t unit -p tests/Small` — harness ran phpunit WITH coverage without a
  bash-level crash; exit 1 is a tool-level result (`--fail-on-risky` flagged 6
  risky tests, pre-existing), not a harness failure. No unbound-variable / bad
  substitution / syntax errors in the run. Memory-limit arg confirmed applied.

## Register claims — all held up

Every M-ID row I was assigned was re-verified against the live code before
changing it; none were found to be wrong. The only nuance worth recording is the
M-023 "tail": the full-run timing recording path was NOT irredeemably dead — a
one-line guard removal makes it record correctly against the pre-existing
`:full-suite` key that `calculateEta` already reads — so it was fixed rather than
deleted.
