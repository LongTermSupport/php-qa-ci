# shellcheck disable=SC2154 # pharDir/pathsToIgnore/pathsToCheck/testsDir/projectRoot are core
#   pipeline variables bin/qa (setConfig, setPaths) sets before this fragment is sourced —
#   genuine sourced-fragment architecture, not unset variables.
#
# Rector — automated refactoring (Safe-function conversion, PHPUnit upgrades, PHP 8.4).
#
# Rector MUTATES code. Whether it is allowed to write is governed by qaReadOnly
# (see detectReadOnly() in functions.inc.bash), NOT by CI:
#   - writable run  -> apply changes, retry loop on failure (historic behaviour)
#   - read-only run -> --dry-run; a pending change FAILS with remediation guidance
#     (used by GitHub Actions so the gate verifies instead of silently rewriting)

# Rector is delivered as a committed, self-contained PHAR (a peer of the other
# vendor-phar/ tools) — NOT an isolated composer sub-project. The PHAR bundles
# its own (extracted) phpstan, so phpstan/phpstan never leaks into any composer
# graph, and there is no consumer-side composer subprocess. Maintainers rebuild
# it with scripts/build-rector-phar.bash (see CLAUDE/Plan/00002-phar-vendored-rector).
rectorBin="$pharDir/rector.phar"
if [[ ! -f "$rectorBin" ]]; then
  echo "ERROR: Rector PHAR not found at $rectorBin"
  echo "It should be committed under vendor-phar/. Reinstall php-qa-ci, or"
  echo "(maintainer) rebuild it: scripts/build-rector-phar.bash"
  exit 1
fi

# Note: Rector does not support -v or -vv verbosity flags
# Using empty string for default verbosity level
rectorVerbosity=""
rectorIgnorePaths="";
if [[ "placeholder-ignore-item" != "${pathsToIgnore[*]}" ]]; then
  rectorIgnorePaths=$(printf '%s\n' "${pathsToIgnore[@]}")
fi

# Run one Rector config against one or more paths, honouring read-only mode.
#
# Usage: runRectorConfig <label> <configFile> <path> [<path>...]
#
# Exit codes are captured via an `if` condition so a non-zero status does not
# abort the run under errexit (no errexit toggling needed). Read-only dry-run
# codes (verified): 0 = clean, 2 = pending changes; any other non-zero is a
# genuine Rector error (bad config, parse failure).
function runRectorConfig() {
  local label="$1"
  local configFile="$2"
  shift 2

  local -a rectorArgs=(
    process
    --autoload-file "$projectRoot/vendor/autoload.php"
    --config "$configFile"
    --clear-cache
  )
  if [[ "true" == "${qaReadOnly:-false}" ]]; then
    rectorArgs+=(--dry-run)
  fi
  rectorArgs+=("$@")

  if [[ "true" == "${qaReadOnly:-false}" ]]; then
    # READ-ONLY: single pass, no retry (retrying a dry-run cannot change the result).
    echo "Running Rector ('$label') in read-only check mode"
    local readOnlyExit=0
    if rectorIgnorePaths="$rectorIgnorePaths" phpNoXdebug -f "$rectorBin" -- $rectorVerbosity "${rectorArgs[@]}"; then
      readOnlyExit=0
    else
      readOnlyExit=$?
    fi
    if ((readOnlyExit == 0)); then
      return 0
    fi
    if ((readOnlyExit == 2)); then
      reportReadOnlyWouldModify "Rector ('$label')" "rector"
    fi
    echo "Rector ('$label') failed with exit code $readOnlyExit (a genuine error, not a pending-change diff) — see output above."
    exit 1
  fi

  # WRITABLE: apply changes, retry loop as before.
  local rectorExitCode=99
  while ((rectorExitCode > 1)); do
    echo "Running Rector ('$label')"
    if rectorIgnorePaths="$rectorIgnorePaths" phpNoXdebug -f "$rectorBin" -- $rectorVerbosity "${rectorArgs[@]}"; then
      rectorExitCode=0
    else
      rectorExitCode=$?
    fi
    if ((rectorExitCode > 0)); then
      tryAgainOrAbort "Rector '$label'"
    fi
  done
}

# First we run the Safe Rectors to implement safe versions of functions.
runRectorConfig "Safe" "$(configPath rector-safe.php)" "${pathsToCheck[@]}"

# Then PHPUnit Rector on the tests directory.
echo "Running PHPUnit Rector on $testsDir"
runRectorConfig "PHPUnit" "$(configPath rector-phpunit.php)" "$testsDir"

projectRectorFound=false
# Then we check for project specific Rectors.
for rectorConfig in "$projectRoot/rector.php" "$projectRoot/qaConfig/rector.php"; do
  if [[ -f $rectorConfig ]]; then
    projectRectorFound=true
    echo "Running Project Specific Rector as configured in $rectorConfig"
    runRectorConfig "Project Specific" "$rectorConfig" "${pathsToCheck[@]}"
  fi
done

if [[ $projectRectorFound == false ]]; then
  # Run PHP 8.4 specific rectors
  runRectorConfig "PHP 8.4" "$(configPath rector-php84.php)" "${pathsToCheck[@]}"
else
  echo "Skipping standard PHP 8.4 Rector as we assume its handled in project rector"
fi

# ---------------------------------------------------------------------------
# WRITABLE-RUN ADVISORY (agent-facing). Rector only MUTATES in a writable run;
# a read-only run just reports pending changes, so this guidance is irrelevant
# there and is suppressed. It fires once, after all Rector configs have run.
if [[ "true" != "${qaReadOnly:-false}" ]]; then
  cat <<'RECTOR_WRITABLE_ADVISORY'

------------------------------------------------------------------------------
Rector ran in WRITABLE mode and may have rewritten files. DO NOT FIGHT IT.
------------------------------------------------------------------------------
Rector is a DETERMINISTIC, CONFIG-DRIVEN autofixer. Every edit it just made is
intentional and part of the standard — treat it as authoritative, not as a
suggestion to negotiate:

  - Do NOT revert, undo, partially back out, or hand-"tidy" a Rector edit to
    keep a smaller or "cleaner" diff. The change is reproducible from the config,
    so a local revert is simply re-applied on the next run — reverting Rector is
    wasted effort and is forbidden.
  - KEEP every change. When a Rector edit surfaces a DOWNSTREAM error (most
    commonly a PHPStan type error from a newly-precise \Safe\* return type, or
    mutation debt dragged in when a touched file enters the diff-MSI gate), fix
    the CAUSE the honest way — narrow the type, write the missing test — NEVER by
    reverting the Rector edit or suppressing the follow-on check.
  - COMMIT what Rector produced. Prefer an ATOMIC commit of just the autofix
    output (e.g. "style: apply Rector autofixes"), kept separate from your
    feature/bugfix change so the mechanical rewrite is trivial to review.

Roll with Rector. It is part of the gate, not an obstacle to it.
------------------------------------------------------------------------------

RECTOR_WRITABLE_ADVISORY
fi
