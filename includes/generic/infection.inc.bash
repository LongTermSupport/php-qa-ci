#!/usr/bin/env bash
# Infection — mutation testing (measures whether the TEST suite actually kills
# injected faults; the MSI/Covered-Code-MSI floors are the ratchet's quality gate).
#
# SELF-CONTAINED + KERNEL-BOOTING-SUITE-SAFE.
#
# This step must work BOTH as part of the full `bin/qa` pipeline AND standalone
# via `bin/qa -t infection` (the single-tool path bypasses the `useInfection`
# gate in allTestingTools, so it can be invoked directly). To make that safe:
#
#   1. Infection needs code coverage (coverage-xml + the junit log). In the full
#      pipeline the phpunit-with-coverage step has already produced it under
#      $varDir/phpunit_logs; standalone there is none. So we DETECT existing
#      coverage and REUSE it, or GENERATE it here with a single phpunit coverage
#      run (no double run when the pipeline already produced it). A failing
#      coverage run fails the step — and that same run proves the suite is green,
#      which is precisely what lets us skip Infection's own initial test run.
#
#   2. We ALWAYS pass --skip-initial-tests. Infection's built-in initial run
#      re-executes the WHOLE suite a second time; for a kernel-booting,
#      DB-coupled Symfony integration suite that redundant threaded pass
#      misbehaves. Coverage is guaranteed present after step 1, so Infection
#      never needs (and never runs) the initial suite. This lets a consuming
#      project drop any bespoke side-script that wrapped Infection with its own
#      coverage + --skip-initial-tests sequence — the harness now does it.
#
# The MSI floors come from setConfig.inc.bash (read from the project's
# qaConfig.inc.bash — mutationScoreIndicator / coveredCodeMSI). The SSoT floor
# stays enforced here; we never narrow or lower it.

# Coverage needs an Xdebug driver. If Xdebug is unavailable we cannot generate
# coverage, so mutation testing cannot run. Skip with a clear message and exit
# cleanly (return 0) — matching how bin/qa reports "infection not available"
# without Xdebug, and how a consumer-side mutation gate skips rather than fails
# on a coverage-less host. (In the full pipeline this branch is normally never
# reached, because setConfig sets useInfection=0 when xdebugEnabled=0 and
# allTestingTools then skips the step entirely; this guard covers the standalone
# `-t infection` invocation, which bypasses that gate.)
if [[ "1" != "${xdebugEnabled:-0}" ]]; then
    echo "Infection: Xdebug is not enabled — cannot generate coverage, so mutation testing cannot run. SKIPPING."
    return 0
fi

# DIFF-MODE PREFLIGHT: refuse a DIRTY working tree (uncommitted changes under the
# source or tests directories) before doing anything expensive. The diff lane's
# question is "did the COMMITTED change's tests kill its mutants?" — its verdict
# must be reproducible from the ref graph alone. Running it over a dirty tree
# measures a state that is neither the committed HEAD nor what the base-ref
# scoping describes, and has been observed to silently UNDER-REPORT (a coverage
# index built from the dirty tree left most changed files unmutated — a false
# GREEN that was trusted and later disproved on the committed state). Fail loud
# and early instead: commit the work first (a WIP commit is fine), then re-run.
# The check is scoped to srcDir/testsDir — dirt elsewhere (docs, tooling) cannot
# affect mutation results and must not block the lane.
function assertCleanTreeForInfectionDiffMode() {
    local -a dirtyScopePaths=( "$srcDir" )
    if [[ -n "${testsDir:-}" ]]; then
        dirtyScopePaths+=( "$testsDir" )
    fi
    local dirtyPaths=""
    dirtyPaths="$(git status --porcelain -- "${dirtyScopePaths[@]}")" || {
        echo "Infection: diff mode — 'git status' failed; cannot verify the working tree is clean."
        return 1
    }
    if [[ -n "$dirtyPaths" ]]; then
        echo "Infection: diff mode REFUSED — uncommitted changes under the source/tests directories:"
        echo "$dirtyPaths"
        echo "           A dirty-tree diff run can silently under-report (mutants for the uncommitted"
        echo "           code may never be generated), producing a false green. Commit the work first"
        echo "           (a WIP commit is fine), then re-run the diff lane against the committed state."
        return 1
    fi
    return 0
}

if [[ -n "${infectionDiffBase:-}" ]]; then
    if ! assertCleanTreeForInfectionDiffMode; then
        return 1
    fi
fi

# shellcheck disable=SC2154 # pharDir/varDir/phpBinPath/binDir/phpUnitConfigPath/standardIFS
#   are core pipeline variables set by bin/qa (setConfig, options.inc.bash) before
#   this fragment is sourced — genuine sourced-fragment architecture.
infectionPath="$pharDir/infection.phar"
# shellcheck disable=SC2154 # varDir is set by bin/qa (setConfig) before this fragment is sourced
coverageXmlDir="$varDir/phpunit_logs/coverage-xml"

# Resolve the MSI floors HERE, at run time, from the project's SSoT vars
# (qaConfig/qaConfig.inc.bash exports mutationScoreIndicator / coveredCodeMSI).
# setConfig.inc.bash derives infectionMutationScoreIndicator / infectionCoveredCodeMSI
# EARLIER in bin/qa — BEFORE the project override is sourced — so those derived
# vars freeze at the generic defaults (60/80) and would NOT carry the project's
# floor. By the time this tool actually runs the override IS in scope, so prefer
# the project SSoT vars and fall back to the derived values. This keeps the
# monotonic ratchet floor authoritative; never narrow or lower it.
minMsi="${mutationScoreIndicator:-${infectionMutationScoreIndicator}}"
minCoveredMsi="${coveredCodeMSI:-${infectionCoveredCodeMSI}}"

# Diff-lane covered-MSI floor (opt-in diff mode only — see OPT-IN DIFF MODE below).
# Defaults to 100 — the "kill every mutant in the code you touched" ideal — but is
# OVERRIDABLE, because a rigid 100 is not always HONESTLY achievable. A touched file
# can contain a PROVABLY EQUIVALENT mutant: a mutation whose result is semantically
# identical to the original, so NO test can ever distinguish (and therefore kill) it.
# These are frequently a form a static-analysis rule MANDATES (e.g. a required
# non-throwing variant of an API call on an already-guarded path, where the mutated
# argument makes no observable difference). Such a mutant cannot be killed AND must
# not be excluded/suppressed — so the honest response is to lower this floor a few
# points (95 is the usual choice; 90 the practical floor), NOT to add a suppression
# or contort tests to chase an unreachable 100. Override in qaConfig/qaConfig.inc.bash
# or per-run: `infectionDiffCoveredMsi=95`. The 100%-floor advisory emitted below
# spells this out whenever a 100 floor is in force.
infectionDiffCoveredMsi="${infectionDiffCoveredMsi:-100}"

# DETECT vs GENERATE coverage. Infection must mutate against coverage for the CODE
# UNDER TEST, so stale coverage from an earlier run would give a wrong score.
#   - Full-pipeline run ($singleToolToRun empty): the phpunit-with-coverage step ran
#     THIS invocation and produced coverage under $coverageXmlDir — REUSE it (avoids a
#     second, redundant coverage run).
#   - Standalone `bin/qa -t infection` ($singleToolToRun set): there was NO preceding
#     coverage step this invocation, so any coverage on disk is from a PRIOR run and may
#     be stale — GENERATE it fresh (clearing the old dir first). On a clean checkout
#     (CI) there is none anyway, so this is also the CI path.
# Generation uses $phpBinPath (the xdebug-enabled php), NOT phpNoXdebug — the coverage
# driver must be loaded. Exit code captured via the `if` condition so a non-zero status
# does not abort the run under errexit; a failure fails the step (and proves the suite
# green, which is what lets Infection skip its initial run).
if [[ -z "${singleToolToRun:-}" && -d "$coverageXmlDir" && -n "$(ls -A "$coverageXmlDir" 2>/dev/null)" ]]; then
    echo "Infection: reusing the coverage produced by the phpunit step this run ($coverageXmlDir)."
else
    echo "Infection: generating fresh coverage (xdebug) before mutation testing."
    echo "           (this also proves the suite is green, which lets Infection skip its initial run)"
    rm -rf "$coverageXmlDir"
    coverageGenExit=0
    # Runs the Xdebug-enabled binary directly (not phpNoXdebug), so the global
    # phpqaMemoryLimit must be applied explicitly here — otherwise coverage
    # generation falls back to PHP's default memory_limit.
    # shellcheck disable=SC2154 # phpBinPath/binDir/phpUnitConfigPath are set by bin/qa (setConfig)
    if XDEBUG_MODE=coverage "$phpBinPath" -d memory_limit="${phpqaMemoryLimit:-4G}" -f "$binDir"/phpunit -- \
        -c "$phpUnitConfigPath" \
        --coverage-xml "$coverageXmlDir" \
        --log-junit "$varDir/phpunit_logs/phpunit.junit.xml"; then
        coverageGenExit=0
    else
        coverageGenExit=$?
    fi
    if ((coverageGenExit > 0)); then
        echo "Infection: coverage generation FAILED (phpunit exit $coverageGenExit) — the test suite is not green, so mutation testing cannot proceed."
        tryAgainOrAbort "Infection (coverage generation)"
        # In CI tryAgainOrAbort exits non-zero; interactively it returns so the
        # caller can re-run. Re-source from the top to regenerate coverage.
        return 1
    fi
fi

# Run Infection against the (reused or freshly generated) coverage.
#   - --skip-initial-tests: coverage is guaranteed present; never re-run the
#     kernel-booting suite (see header).
#   - Infection itself runs WITHOUT Xdebug (phpNoXdebug) — it only consumes the
#     coverage we already produced, and dropping Xdebug here is faster, matching
#     the historic behaviour of this step.
#   - The floor flags (--min-msi / --min-covered-msi) are the SSoT ratchet.
#   - The invocation runs inside an `if` CONDITION, so under bin/qa's errexit a
#     non-zero exit does NOT abort the run (errexit is suspended for conditions);
#     it is handled explicitly via the retry loop below.
#
# OPT-IN DIFF MODE (infectionDiffBase). Set infectionDiffBase to a git ref
# (env var or project qaConfig, e.g. infectionDiffBase=origin/main) to scope the
# run to only the code changed against that base, instead of the whole codebase.
# This gives a fast, per-change mutation check whose question is "did the changed
# code's tests kill its mutants?" — answerable from the diff alone, without paying
# the whole-codebase cost on every iteration. When set, the step:
#   - restricts mutation to the changed source files (see the file-list computation
#     below) by passing them as POSITIONAL path arguments, so Infection mutates
#     only added/modified files;
#   - swaps the two monotonic floor flags for a single --min-covered-msi floor
#     ($infectionDiffCoveredMsi, default 100) — the "kill the mutants in the code you
#     touched" bar. 100 keeps the ideal ("kill everything you touched") as the default,
#     which is meaningful on the small changed-file denominator; but it is overridable
#     to a lower honest floor (e.g. 95) because a touched file may carry a provably
#     equivalent mutant that no test can kill and that must not be excluded — see the
#     $infectionDiffCoveredMsi definition and the 100%-floor advisory below;
#   - drops --log-verbosity=all back to Infection's default console verbosity (the
#     file loggers configured in infection.json stay authoritative), so a report
#     rendering issue over an escaped-mutant diff can never eat the run's result.
# When infectionDiffBase is UNSET the run is byte-for-byte identical to the full
# whole-codebase run against the SSoT floors — diff mode is purely additive.
#
# WHY WE COMPUTE THE FILE LIST OURSELVES INSTEAD OF Infection's --git-diff-base:
# Infection's own --git-diff-base runs `git diff` from the process CWD but resolves
# the resulting paths relative to that CWD, whereas `git diff` emits them relative
# to the REPOSITORY ROOT. Those agree only when the project sits AT the git root;
# for a project in a SUBDIRECTORY of the repo (a monorepo package) every path is
# mis-resolved and the diff silently matches NOTHING — a false "no changed files"
# pass. Running `git diff --relative` ourselves normalises the paths to the CWD, so
# diff scoping is correct whether the project is the repo root or a subdirectory; we
# then hand the explicit list to Infection as POSITIONAL path arguments (the
# non-deprecated replacement for the --filter option, which Infection deprecated
# in 0.34.0 and will remove in a future release).
# The changed-file set is computed from COMMITTED HISTORY ONLY: a three-dot
# `git diff base...HEAD` (merge-base of the base ref and HEAD, against HEAD).
# Two properties matter:
#   - it never reads the working tree, so (belt to the preflight's braces) the
#     scoping cannot be skewed by uncommitted state; and
#   - the merge-base form lists only files changed ON THIS BRANCH — a base ref
#     that has advanced since branching cannot leak ITS changed files into the
#     lane (a plain two-dot diff against the base tip would, forcing the lane to
#     judge code this change never touched).
# Sets $infectionDiffFilter (comma-joined, possibly empty). Returns 1 on git
# failure; the SKIP decision for an empty set stays with the caller.
function computeInfectionDiffFilter() {
    local diffChangedRaw=""
    local diffGitExit=0
    diffChangedRaw="$(git --no-pager diff "${infectionDiffBase}...HEAD" --diff-filter=AM --name-only --relative -- "$srcDir")" || diffGitExit=$?
    if ((diffGitExit != 0)); then
        echo "Infection: 'git diff' against base '${infectionDiffBase}' failed (exit ${diffGitExit}) — cannot determine the changed files for diff mode."
        echo "           Check that the base ref exists and shares history with HEAD (e.g. 'git fetch origin' first)."
        return 1
    fi
    # Keep only PHP files, collecting BOTH forms of the list: the authoritative
    # positional-args array (passed to Infection in runInfection) and a
    # comma-joined string (kept only for the human-readable log line and the
    # emptiness/SKIP check). A `grep`/pipeline here would abort the run under
    # bin/qa's `set -o pipefail`+errexit on a legitimate no-match, so we iterate
    # explicitly instead.
    local diffChangedFile
    while IFS= read -r diffChangedFile; do
        [[ "$diffChangedFile" == *.php ]] || continue
        # Positional paths are absolutised against the CWD. Infection's
        # PositionalPathsClassifier resolves a RELATIVE positional path against the
        # infection.json CONFIG dir (not the CWD), so a CWD-relative "src/..." would
        # resolve under qaConfig/ and be rejected. `git diff --relative` above emits
        # paths relative to the CWD, so ${PWD}/<path> is the correct absolute form.
        infectionDiffFilterPaths+=("${PWD}/${diffChangedFile}")
        if [[ -n "$infectionDiffFilter" ]]; then
            infectionDiffFilter="${infectionDiffFilter},${diffChangedFile}"
        else
            infectionDiffFilter="${diffChangedFile}"
        fi
    done <<< "$diffChangedRaw"
    return 0
}

infectionDiffFilter=""
# Parallel to the comma-joined string above: the SAME changed files as an ARRAY,
# handed to Infection as positional path arguments (see runInfection). This is the
# authoritative list for the run; the string exists only for the log line + the
# emptiness/SKIP check below.
infectionDiffFilterPaths=()
if [[ -n "${infectionDiffBase:-}" ]]; then
    echo "Infection: diff mode — scoping mutation to source files changed against '${infectionDiffBase}' (committed history only)."
    if ! computeInfectionDiffFilter; then
        return 1
    fi

    if [[ -z "$infectionDiffFilter" ]]; then
        echo "Infection: diff mode — no committed PHP source changes against '${infectionDiffBase}'; there are no new mutants to check. SKIPPING."
        return 0
    fi
    echo "Infection: diff mode — mutating only the changed files: ${infectionDiffFilter}"
fi

function runInfection() {
    local -a infectionArgs=( -- )
    if [[ "1" == "${infectionOnlyCovered:-0}" ]]; then
        infectionArgs+=( --only-covered )
    fi
    infectionArgs+=(
        --coverage="$varDir/phpunit_logs"
        --skip-initial-tests
        --threads="${infectionThreads}"
        --configuration="${infectionConfig}"
    )

    if [[ -n "${infectionDiffBase:-}" ]]; then
        # Diff-scoped lane: mutate only the changed files (computed above) and
        # enforce the "no new escaped mutants in the changed code" bar via the
        # covered-MSI floor $infectionDiffCoveredMsi (default 100, overridable —
        # see its definition above for why an honest run may need 95).
        # The changed files are passed as POSITIONAL path arguments (appended LAST,
        # after every option) — the non-deprecated replacement for the "--filter"
        # option, which Infection deprecated in 0.34.0 and will remove in a future
        # release. Both forms scope mutation to exactly the listed files; the
        # positional form additionally avoids the deprecation warning (and the
        # eventual hard break). Infection's own guidance: "infection run <path>
        # <path> ..." (the "paths" IS_ARRAY argument).
        infectionArgs+=(
            --min-covered-msi="${infectionDiffCoveredMsi}"
        )
        # Positional source paths MUST come after all options. In diff mode the
        # list is guaranteed non-empty (an empty diff SKIPs earlier, before this
        # function is ever called).
        infectionArgs+=( "${infectionDiffFilterPaths[@]}" )
    else
        # Full lane (default, unchanged): whole codebase against the SSoT ratchet
        # floors, with the verbose console report.
        infectionArgs+=(
            --min-msi="${minMsi}"
            --min-covered-msi="${minCoveredMsi}"
            --log-verbosity=all
        )
    fi

    # Daemon/host resilience (best-effort; never aborts the run). Mutation testing is the
    # heaviest QA stage (many parallel PHPUnit workers, each up to phpqaMemoryLimit). Run its
    # process tree at low CPU priority AND mark it as the preferred OOM victim, so it yields
    # to — and is killed before — long-lived processes (editors, the Claude hooks daemon)
    # under CPU/memory pressure. Scoped to a subshell so only Infection's children (which
    # inherit both attributes) are affected, not bin/qa. Raising niceness / oom_score_adj
    # needs no privilege; $BASHPID is the subshell's own pid.
    (
        renice -n 19 -p "$BASHPID" > /dev/null \
            || echo "Infection: renice unavailable — continuing at normal CPU priority"
        if [[ -w /proc/self/oom_score_adj ]]; then
            echo 900 > /proc/self/oom_score_adj
        fi
        phpNoXdebug -f "$infectionPath" "${infectionArgs[@]}"
    )
}

# 100%-MSI floor advisory. A 100 floor is a fine TARGET when it is honestly
# achievable, but it is NOT a good permanent goal to force: real code contains
# provably EQUIVALENT mutants (mutations with identical semantics that no test can
# kill), so demanding 100 eventually costs far more than it is worth — and the two
# ways to "reach" it from there are both dishonest: excluding/suppressing the mutant,
# or contorting production/test code purely to please the mutation tool (pure tech
# debt). When 100 stops being achievable honestly, the right move is to DROP the floor
# a few points (95 is good, 90 is fine), NOT to exclude or contort. This advisory
# fires whenever a 100 floor is actually in force for this run, so that decision is a
# conscious one. It never fails the run.
infectionActiveFloorIsHundred=0
if [[ -n "${infectionDiffBase:-}" ]]; then
    [[ "${infectionDiffCoveredMsi}" == "100" ]] && infectionActiveFloorIsHundred=1
else
    { [[ "${minMsi}" == "100" ]] || [[ "${minCoveredMsi}" == "100" ]]; } && infectionActiveFloorIsHundred=1
fi
if ((infectionActiveFloorIsHundred == 1)); then
    cat <<'INFECTION_MSI_100_ADVISORY'

------------------------------------------------------------------------------
Infection: a 100% MSI floor is in force for this run.
------------------------------------------------------------------------------
100% is a fine target WHILE it is honestly achievable — but it is usually not a
good idea to hold it as a permanent goal. Real code contains provably EQUIVALENT
mutants (semantically identical mutations no test can ever kill), so at some point
100 becomes unreachable without one of two DISHONEST moves:
  - excluding/suppressing the mutant (hides a real signal), or
  - contorting production or test code just to please Infection (pure tech debt).
Neither is acceptable. If 100 stops being honestly achievable, the correct course
is to LOWER the floor a few points — 95 is good, 90 is fine — via
  infectionDiffCoveredMsi=95   (diff lane)   or
  coveredCodeMSI / mutationScoreIndicator   (full lane, qaConfig.inc.bash)
An honest 90+% MSI is a healthy gate; a forced 100% is diminishing-returns busywork.
------------------------------------------------------------------------------

INFECTION_MSI_100_ADVISORY
fi

infectionExitCode=99
while ((infectionExitCode > 0)); do
    backupIFS=$IFS
    # shellcheck disable=SC2154 # standardIFS is set by bin/qa/options.inc.bash before this fragment is sourced
    IFS=$standardIFS

    rm -rf "$varDir"/infection/*
    if runInfection; then
        infectionExitCode=0
    else
        infectionExitCode=$?
    fi

    IFS=$backupIFS
    if ((infectionExitCode > 0)); then
        tryAgainOrAbort "Infection"
    fi
done
