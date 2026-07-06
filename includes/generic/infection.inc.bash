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

infectionPath="$pharDir/infection.phar"
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
    if XDEBUG_MODE=coverage "$phpBinPath" -f "$binDir"/phpunit -- \
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
#     below) via --filter, so Infection mutates only added/modified files;
#   - swaps the two monotonic floor flags for a single --min-covered-msi=100 — the
#     "no new escaped mutants in the changed code" bar. A diff-scoped percentage
#     floor is statistically meaningless (a handful of mutants makes any fixed
#     percentage either vacuous or noise), whereas "kill everything you touched"
#     is a stable, meaningful bar on a small denominator;
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
# then hand the explicit list to Infection via --filter.
infectionDiffFilter=""
if [[ -n "${infectionDiffBase:-}" ]]; then
    echo "Infection: diff mode — scoping mutation to source files changed against '${infectionDiffBase}'."
    diffChangedRaw=""
    diffGitExit=0
    diffChangedRaw="$(git --no-pager diff "${infectionDiffBase}" --diff-filter=AM --name-only --relative -- "$srcDir")" || diffGitExit=$?
    if ((diffGitExit != 0)); then
        echo "Infection: 'git diff' against base '${infectionDiffBase}' failed (exit ${diffGitExit}) — cannot determine the changed files for diff mode."
        echo "           Check that the base ref exists (e.g. 'git fetch origin' first)."
        return 1
    fi
    # Keep only PHP files and comma-join them for --filter. A `grep`/pipeline here
    # would abort the run under bin/qa's `set -o pipefail`+errexit on a legitimate
    # no-match, so we iterate explicitly instead.
    while IFS= read -r diffChangedFile; do
        [[ "$diffChangedFile" == *.php ]] || continue
        if [[ -n "$infectionDiffFilter" ]]; then
            infectionDiffFilter="${infectionDiffFilter},${diffChangedFile}"
        else
            infectionDiffFilter="${diffChangedFile}"
        fi
    done <<< "$diffChangedRaw"

    if [[ -z "$infectionDiffFilter" ]]; then
        echo "Infection: diff mode — no changed PHP source files against '${infectionDiffBase}'; there are no new mutants to check. SKIPPING."
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
        # enforce "no new escaped mutants in the changed code" via covered-MSI 100.
        infectionArgs+=(
            --filter="${infectionDiffFilter}"
            --min-covered-msi=100
        )
    else
        # Full lane (default, unchanged): whole codebase against the SSoT ratchet
        # floors, with the verbose console report.
        infectionArgs+=(
            --min-msi="${minMsi}"
            --min-covered-msi="${minCoveredMsi}"
            --log-verbosity=all
        )
    fi

    phpNoXdebug -f "$infectionPath" "${infectionArgs[@]}"
}

infectionExitCode=99
while ((infectionExitCode > 0)); do
    backupIFS=$IFS
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
