#!/usr/bin/env bash
#
# shellcheck disable=SC2154 # $projectRoot/$projectConfigPath/$platform/$DIR/$defaultConfigPath/
#   $noXdebugConfigPath/$phpBinPath are core pipeline variables bin/qa sets
#   before sourcing this file's functions are ever called — genuine
#   sourced-fragment architecture, not unset variables.
# shellcheck disable=SC1090 # runTool/runNonPlatformTool source a path built
#   from a runtime $tool/$platform argument — inherently non-constant; there
#   is no fixed target to point a `source=` directive at.

readonly platformGeneric="generic"
readonly platformSymfony="symfony"

function detectPlatform() {
  if [[ -f $projectRoot/symfony.lock ]]; then
    echo $platformSymfony
    return 0
  fi

  echo $platformGeneric
}

################################################################
# Normalise a -p specified path to a project-root-relative path.
#
# Accepts BOTH forms callers actually use:
#   - project-root-relative (src/Foo.php)      -> echoed unchanged
#   - absolute under the project root          -> relativised
# An absolute path OUTSIDE the project root (or equal to it) is an error —
# bin/qa composes "$projectRoot/$specifiedPath", so anything else would
# silently scan the wrong location. Automated callers (editor/agent lint
# hooks) pass absolute paths; humans pass relative ones. Both must work.
#
# Usage: qaNormaliseSpecifiedPath <path> <projectRoot>
# Echoes the normalised relative path; returns 1 with a message on stderr
# for an unusable absolute path.
function qaNormaliseSpecifiedPath() {
  local pathToNormalise="$1"
  local rootForPath="${2%/}"

  if [[ "$pathToNormalise" != /* ]]; then
    echo "$pathToNormalise"
    return 0
  fi

  if [[ "$pathToNormalise" == "$rootForPath" ]]; then
    echo "ERROR: -p path '$pathToNormalise' is the project root itself — omit -p for a full run" >&2
    return 1
  fi

  if [[ "$pathToNormalise" == "$rootForPath"/* ]]; then
    echo "${pathToNormalise#"$rootForPath"/}"
    return 0
  fi

  echo "ERROR: -p path '$pathToNormalise' is absolute and outside the project root '$rootForPath'" >&2
  return 1
}

################################################################
# Run a tool
# First check for a project qaConfig tool override
# Then a platform tool
# finally the Generic tool
function runTool() {
  local tool="$1"
  local projectOverridePath="$projectConfigPath/tools/$tool.inc.bash"
  local platformPath="$DIR/../includes/$platform/$tool.inc.bash"
  local genericPath="$DIR/../includes/generic/$tool.inc.bash"

  if [[ -f "$projectOverridePath" ]]; then
    echo "Running Project Override $tool"
    source "$projectOverridePath"
    return 0
  elif [[ -f "$platformPath" ]]; then
    echo "Running $platform $tool"
    source "$platformPath"
    return 0
  fi
  echo "Running generic $tool"
  source "$genericPath"
}

# This is very much like run tool
# It will check for a project level override
# Then it will run the generic tool explicitly
function runNonPlatformTool() {
  local tool="$1"
  local projectOverridePath="$projectConfigPath/tools/$tool.inc.bash"
  local genericPath="$DIR/../includes/generic/$tool.inc.bash"

  if [[ -f "$projectOverridePath" ]]; then
    echo "Running Project Override $tool"
    source "$projectOverridePath"
    return 0
  fi
  echo "Running generic $tool"
  source "$genericPath"
}

################################################################
# Get the path for a config file
# Config file will be search for in:
#   A qaConfig folder in the project root
#   The phpqa library's configDefaults/{platform}
#   The phpqa library's configDefaults/generic
#
# Usage:
#
# `configPath "relative/path/to/file/or/folder"
function configPath() {
  local relativePath="$1"
  local platformPath="$defaultConfigPath/$platform/$relativePath"
  local genericPath="$defaultConfigPath/generic/$relativePath"
  # -e FILE - True if the FILE exists and is a file, regardless of type
  if [[ -e $projectConfigPath/$relativePath ]]; then
    echo "$projectConfigPath/$relativePath"
  elif [[ -e $platformPath ]]; then
    echo "$platformPath"
  else
    echo "$genericPath"
  fi
}

###############################################################
# Half the available CPU threads, minimum 1 — the shared parallelism default for the
# heavy tools (Rector, PHPStan, Infection), so no single tool saturates the machine or
# starves long-lived processes (editors, the Claude hooks daemon). SSoT for the
# "50% of cores" number: computed here, exported by setConfig as $qaHalfCpuThreads.
function halfCpuThreadCount() {
  local cores
  if command -v nproc > /dev/null; then
    cores=$(nproc)
  else
    cores=$(grep -c ^processor /proc/cpuinfo)
  fi
  local half=$(( cores / 2 ))
  if (( half < 1 )); then
    half=1
  fi
  echo "$half"
}

###############################################################
# Function to run PHP without Xdebug enabled, much faster
# Applies global memory limit (phpqaMemoryLimit) by default
# Usage:
# `phpNoXdebug path/to/php/file.php -- -arg1 -arg2`
# Note: Tool-specific memory limits can override by passing -d memory_limit=X
#       (last -d wins in PHP)
function phpNoXdebug() {
  if [[ ! -f ${noXdebugConfigPath} ]]; then
    # Using awk to ensure that files ending without newlines do not lead to configuration error
    ${phpBinPath} -i | grep "\.ini" | grep -o -e '\(/[a-z0-9._-]\+\)\+\.ini' | grep -v xdebug | xargs awk 'FNR==1{print ""}1' >"$noXdebugConfigPath"
  fi
  # Trace the actual PHP invocation, but PRESERVE the caller's xtrace setting.
  # Historically this always ran `set +x` at the end, silently disabling tracing
  # that a caller had deliberately turned on (e.g. phpunit.inc.bash wraps its run
  # in set -x). Remember the incoming state and only restore it.
  local _xtraceWasOn=0
  case "$-" in
    *x*) _xtraceWasOn=1 ;;
  esac
  set -x
  # Apply global memory limit (can be overridden with explicit -d memory_limit=X after this)
  ${phpBinPath} -n -c "$noXdebugConfigPath" -d memory_limit="${phpqaMemoryLimit:-4G}" "$@"
  local exitCode=$?
  if (( _xtraceWasOn == 0 )); then
    set +x
  fi
  echo
  return $exitCode
}

###############################################################
# Re-apply config derivations that DEPEND on project-overridable variables.
#
# setConfig runs BEFORE the project's qaConfig/qaConfig.inc.bash is sourced
# (see bin/qa), so any value derived there from a project-overridable variable
# would freeze at the generic default and silently ignore the override. This
# function holds those derivations; it is called at the end of setConfig AND
# again by bin/qa after the project override is sourced, so overrides of
# phpUnitCoverage / useInfection / mutationScoreIndicator / coveredCodeMSI
# take effect. It must stay idempotent: gating only ever forces values OFF,
# never on.
function deriveDependentConfig() {
  # Coverage requires an Xdebug driver.
  if [[ "1" != "${xdebugEnabled:-0}" ]]; then
    phpUnitCoverage=0
  fi
  # Infection requires coverage.
  if [[ "0" == "${xdebugEnabled:-0}" || "0" == "${phpUnitCoverage:-1}" ]]; then
    # shellcheck disable=SC2034 # global consumed by bin/qa and toolRegistry/allTestingTools after this function returns
    useInfection=0
  fi
  # MSI floors: the project SSoT vars (mutationScoreIndicator / coveredCodeMSI)
  # win whenever set; otherwise keep the current derived value / defaults.
  infectionMutationScoreIndicator=${mutationScoreIndicator:-${infectionMutationScoreIndicator:-60}}
  infectionCoveredCodeMSI=${coveredCodeMSI:-${infectionCoveredCodeMSI:-80}}
}

function tryAgainOrAbort() {
  toolname="$1"
  if [[ "false" != "${CI:-'false'}" ]]; then
    echo "

    ==================================================

        $toolname Failed...

    ==================================================

        "
    exit 1
  fi
  echo "

    ==================================================

        $toolname Failed...

        would you like to try again? (y/n)

        (note: if you change config files, you might have to run from the top for it to take effect...)

    ==================================================

    "
  while read -r -n 1 tryAgainOrAbort; do
    if [[ "n" == "$tryAgainOrAbort" ]]; then
      printf "\n\nAborting...\n\n"
      exit 1
    fi
    if [[ "y" == "$tryAgainOrAbort" ]]; then
      break
    fi
    printf '\n\ninvalid choice: %s - should be y or n \n\n        would you like to try again? (y/n)' "$tryAgainOrAbort"
  done
  printf "\n\nTrying again, good luck!\n\n"
  # shellcheck disable=SC2034 # global consumed by bin/qa after the retry loop returns
  hasBeenRestarted="true"
}

###############################################################################
# Shared retry driver for the homogeneous leaf tools (M-010).
#
# The historic per-fragment pattern — a `while ((rc > 0))` retry loop that runs
# a single command, captures its exit code WITHOUT tripping errexit, and calls
# tryAgainOrAbort on failure (which exits in CI, loops interactively) — was
# duplicated with minor spelling variations across psr4Validate, packageType,
# sensitiveParameterUsage, phpLint and markdownLinks. This is the single
# implementation those fragments opt into; behaviour is identical to the loops
# it replaces.
#
# The command is invoked under an `if` condition so a non-zero exit is captured
# without aborting under the pipeline's errexit — no manual errexit toggling, no
# exit-code masking, no stderr suppression.
#
# Usage:  qaSimpleTool "<label>" <command> [args...]
#   e.g.  qaSimpleTool "PHP Lint" phpNoXdebug -f "$binDir"/parallel-lint -- "${paths[@]}"
#
# Bespoke fragments (rector, phpCsFixer, phpstan, phpArkitect, phpunit,
# infection, composerChecks, composerRequireChecker, branchNamePolicy) have
# genuinely different control flow (read-only dry-run mapping, tee+PIPESTATUS
# log archival, crash-code thresholds, output parsing) and deliberately do NOT
# use this driver.
function qaSimpleTool() {
  local label="$1"
  shift
  local qaSimpleToolExitCode=99
  while ((qaSimpleToolExitCode > 0)); do
    if "$@"; then
      qaSimpleToolExitCode=0
    else
      qaSimpleToolExitCode=$?
      tryAgainOrAbort "$label"
    fi
  done
}

###############################################################
# Decide whether this is a READ-ONLY (verification) run.
#
# Read-only is ORTHOGONAL to CI/interactivity:
#   - CI (see bin/qa) controls INTERACTIVITY: no prompts, no retry loops. It is
#     force-enabled for Claude Code / non-TTY shells so commands never hang.
#   - qaReadOnly controls whether the mutating tools (Rector, PHP CS Fixer) may
#     WRITE. A read-only run does not modify files; a pending change FAILS the
#     gate with remediation guidance.
#
# These were historically conflated under CI, which made it impossible to (a)
# run a real verification gate that fails-instead-of-applies, and (b) still let
# a non-interactive Claude/local session APPLY fixes. Splitting them fixes both.
#
# Precedence (first match wins):
#   1. explicit QA_READONLY=1/true  -> read-only   (reproduce CI locally)
#      explicit QA_READONLY=0/false -> writable    (force-apply anywhere)
#   2. real CI: GitHub Actions (GITHUB_ACTIONS=true) -> read-only
#   3. everything else (local TTY, Claude sessions, cron) -> writable
#
# Echoes "true" or "false".
function detectReadOnly() {
  case "${QA_READONLY:-}" in
    1 | true)
      echo "true"
      return 0
      ;;
    0 | false)
      echo "false"
      return 0
      ;;
  esac
  if [[ "${GITHUB_ACTIONS:-}" == "true" ]]; then
    echo "true"
    return 0
  fi
  echo "false"
}

###############################################################
# Emit standardized remediation guidance and FAIL when a mutating tool found
# pending changes during a read-only run, then exit 1.
#
# Usage: reportReadOnlyWouldModify "Rector" "rector"
#   $1 - human tool name (for the heading)
#   $2 - the `bin/qa -t <target>` target that applies the fix (e.g. rector, fixer)
function reportReadOnlyWouldModify() {
  local toolName="$1"
  local qaTarget="$2"
  echo "

    ==================================================

        $toolName: pending changes in a READ-ONLY run

    --------------------------------------------------

    This run is read-only (qaReadOnly=true), so $toolName did NOT modify any
    files. It found changes it WOULD make, which fails the gate. The diff is
    shown above.

    Read-only mode is auto-enabled on GitHub Actions. It is INDEPENDENT of CI /
    interactivity: a Claude Code or local run is non-interactive (so it never
    hangs) but still WRITES, so you can apply fixes there.

    TO FIX -- apply the changes where writes are allowed, then commit them:

        QA_READONLY=0 vendor/bin/qa -t $qaTarget
        git add -A && git commit

    Then push. CI passes because no pending changes remain.

    ==================================================

    "
  exit 1
}

###############################################################
# Run a leaf tool, honouring aggregate (non-fail-fast) mode.
#
# Fail-fast is correct for a local apply run: stop at the first problem so the
# developer fixes it and re-runs. But a read-only verification run (e.g. CI)
# is more useful when it reports EVERY failing tool in one pass, so nobody
# fixes phpstan, pushes, and only then discovers phpunit was also red.
#
# When qaAggregate=true this runs the tool in a SUBSHELL so its `exit` is
# contained, records a failure in qaFailedTools, and lets the pipeline carry
# on. When qaAggregate is not set it is a transparent passthrough to runTool,
# preserving the historic fail-fast behaviour (including retries) exactly.
#
# Aggregate mode never mutates: it is only enabled alongside read-only mode,
# where Rector / PHP CS Fixer run with --dry-run.
function runToolGuarded() {
  local tool="$1"
  if [[ "true" != "${qaAggregate:-false}" ]]; then
    runTool "$tool"
    return $?
  fi
  if (runTool "$tool"); then
    return 0
  fi
  qaFailedTools+=("$tool")
  echo ""
  echo ">>> $tool FAILED — continuing (aggregate mode); see the summary at the end."
  echo ""
  return 0
}

###############################################################
# Print the aggregate-mode summary and report overall pass/fail.
#
# Returns 0 when nothing failed (or aggregate mode is off), 1 when one or more
# tools failed. Does NOT exit — the caller owns lock release and the exit code.
function qaReportAggregate() {
  if [[ "true" != "${qaAggregate:-false}" ]]; then
    return 0
  fi
  if ((${#qaFailedTools[@]} == 0)); then
    echo "
    ==================================================

        Aggregate (read-only) run: every QA tool passed.

    ==================================================
    "
    return 0
  fi
  echo "
    ==================================================

        Aggregate (read-only) run: ${#qaFailedTools[@]} tool(s) FAILED

"
  local failedTool
  for failedTool in "${qaFailedTools[@]}"; do
    echo "          - $failedTool"
  done
  echo "
        Each tool's full output is above. Fix every item, then re-run.
        (This run did not fail fast: all tools ran so you see every problem.)

    ==================================================
    "
  return 1
}

function findTestsDir() {
  testsDir="$(find "$projectRoot" -maxdepth 1 -type d \( -name test -o -name tests \) | head -n1)"
  if [[ "" == "$testsDir" ]]; then
    echo "


    ##### ERROR ############################################


    You have no 'tests' or 'test' directory.

    This is not currently supported by phpqa

    Please create at least an empty 'tests' directory, eg:

    mkdir -p $projectRoot/tests


    ########################################################

        " 1>&2
    exit 1
  fi
  echo "$testsDir"
}

function findSrcDir() {
  srcDir="$projectRoot/src"
  if [[ ! -d "$srcDir" ]]; then
    echo "


    ##### ERROR ############################################


    You have no 'src' or directory.

    This is not currently supported by phpqa

    Please create at least an empty 'src' directory, eg:

    mkdir -p $projectRoot/src


    ########################################################

        " 1>&2
    exit 1
  fi
  echo "$srcDir"
}

function findBinDir() {
  binDir="$(cd "$projectRoot" && composer config bin-dir)"
  if [[ "" == "$binDir" ]]; then
    echo "


    ##### ERROR ############################################


    You have no 'bin' or directory.

    This is not currently supported by phpqa

    Please create at least an empty 'bin' directory, eg:

    mkdir -p $projectRoot/bin


    ########################################################

        " 1>&2
    exit 1
  fi
  echo "$binDir"
}

###############################################################
# Archive and rotate QA tool log files with retention policy
#
# This function provides standardized log rotation for QA tools:
# - Archives current log with timestamp
# - Separates full suite runs from path-specific runs
# - Keeps last 10 logs per pattern (full suite + each unique path)
# - Warns when total logs exceed 100 files
#
# Usage:
#   archiveToolLog "toolname" "$logDir" "logfile.ext" "$specifiedPath" "${pathsToCheck[@]}"
#
# Parameters:
#   $1 - toolName: Name of the tool (e.g., "phpunit", "phpstan")
#   $2 - logDir: Directory where logs are stored
#   $3 - logFileName: Base name of the log file (e.g., "phpunit.junit.xml")
#   $4 - specifiedPath: Non-empty if -p flag was used
#   $5+ - pathsToCheck: Array of paths that were checked
#
# Examples:
#   # Full suite run
#   archiveToolLog "phpunit" "$varDir/phpunit_logs" "phpunit.junit.xml" "" "${pathsToCheck[@]}"
#   # Creates: phpunit.junit.YYYYMMDD-HHMMSS.xml
#
#   # Path-specific run
#   archiveToolLog "phpstan" "$varDir/phpstan_logs" "phpstan.json" "1" "src/Entity tests/Unit"
#   # Creates: phpstan.json.src_Entity_tests_Unit.YYYYMMDD-HHMMSS.xml
function archiveToolLog() {
    local toolName="$1"
    local logDir="$2"
    local logFileName="$3"
    local specifiedPath="$4"
    shift 4
    local pathsToCheck=("$@")

    local logFilePath="$logDir/$logFileName"

    # Clean up stale high-count warning markers left by earlier runs. The marker
    # (.warned_high_count_<pid>) exists only to warn once per logDir per run; once
    # its owning process has exited it is dead weight. A marker is stale when its
    # PID is no longer a live process (checked via /proc, avoiding any stderr
    # redirect). The current run's marker (PID $$) is alive, so it is preserved.
    local staleMarker staleMarkerPid
    for staleMarker in "$logDir"/.warned_high_count_*; do
        [[ -e "$staleMarker" ]] || continue
        staleMarkerPid="${staleMarker##*_}"
        if [[ "$staleMarkerPid" =~ ^[0-9]+$ && ! -d "/proc/$staleMarkerPid" ]]; then
            rm -f "$staleMarker"
        fi
    done

    # Only archive if log file exists
    if [[ ! -f "$logFilePath" ]]; then
        return 0
    fi

    local timestamp
    timestamp=$(date +"%Y%m%d-%H%M%S")
    local baseFileName="${logFileName%.*}"  # Remove extension
    local extension="${logFileName##*.}"    # Get extension

    # Determine log file naming based on whether specific paths were specified
    local archivedLogs=()
    if [[ -n "$specifiedPath" ]]; then
        # Path-specific run - generate suffix from paths
        local pathSuffix
        pathSuffix=$(echo "${pathsToCheck[*]}" | tr -cs '[:alnum:]' '_' | sed 's/^_//; s/_$//')
        local archivedLog="$logDir/${baseFileName}.${pathSuffix}.${timestamp}.${extension}"
        local runType="Path-specific run: ${pathsToCheck[*]}"

        # Match only logs for this specific path suffix. find+sort-by-mtime (not
        # `ls | grep`, which mishandles non-alphanumeric filenames) feeds mapfile
        # so word-splitting never touches the file list.
        mapfile -t archivedLogs < <(find "$logDir" -maxdepth 1 -name "${baseFileName}.*.${extension}" -printf '%T@ %p\n' 2>/dev/null | sort -rn | cut -d' ' -f2- | grep -F ".${pathSuffix}.")
    else
        # Full suite run
        local archivedLog="$logDir/${baseFileName}.${timestamp}.${extension}"
        local runType="Full test suite run"

        # Match only full suite logs (timestamp directly after base name)
        mapfile -t archivedLogs < <(find "$logDir" -maxdepth 1 -name "${baseFileName}.*.${extension}" -printf '%T@ %p\n' 2>/dev/null | sort -rn | cut -d' ' -f2- | grep -E "${baseFileName}\\.[0-9]{8}-[0-9]{6}\\.${extension}$")
    fi

    # Archive with clear messaging
    # Use cp (not mv) so the live log stays at its known filename for any
    # downstream tool that reads it (e.g. infection consumes phpunit.junit.xml).
    # The timestamped copy is the rotated archive; the live file is the latest.
    cp "$logFilePath" "$archivedLog"
    echo "${runType}"
    echo "Log: $(basename "$archivedLog")"

    # Keep only last 10 logs matching this pattern
    local numLogs=${#archivedLogs[@]}
    if (( numLogs > 10 )); then
        for ((i=10; i<numLogs; i++)); do
            rm -f "${archivedLogs[$i]}"
        done
        echo "Cleaned up $((numLogs - 10)) old log(s)"
    fi

    # Check total log count across all patterns and warn if > 100
    # Use a marker file to track if we've already warned for this logDir in this run
    local warnMarkerFile="$logDir/.warned_high_count_$$"
    local totalLogs
    totalLogs=$(find "$logDir" -name "*.${extension}" -type f 2>/dev/null | wc -l)

    if (( totalLogs > 100 )) && [[ ! -f "$warnMarkerFile" ]]; then
        # Create marker to prevent duplicate warnings
        touch "$warnMarkerFile"

        echo ""
        echo "=========================================="
        echo "WARNING: HIGH LOG FILE COUNT"
        echo "=========================================="
        echo "Total log files in $logDir: $totalLogs"
        echo ""
        echo "Consider manual cleanup of old logs:"
        echo "  ls -lht $logDir | less"
        echo ""
        echo "To remove logs for specific paths:"
        echo "  rm $logDir/${baseFileName}.PATH_PATTERN.*.${extension}"
        echo ""
        echo "Retention: 10 per pattern (full suite + each -p path)"
        echo "=========================================="
    fi
}
