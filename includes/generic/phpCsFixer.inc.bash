# shellcheck disable=SC2154 # varDir/pharDir/pathsToCheck are set by bin/qa (setConfig, setPaths)
#   before this fragment is sourced — genuine sourced-fragment architecture.
#
# PHP CS Fixer — code-style fixing.
#
# PHP CS Fixer MUTATES code. Whether it may write is governed by qaReadOnly
# (see detectReadOnly() in functions.inc.bash), NOT by CI:
#   - writable run  -> apply fixes, retry loop on failure
#   - read-only run -> --dry-run; a pending fix FAILS with remediation guidance
#     (used by GitHub Actions so the gate verifies instead of silently rewriting)
#
# Exit codes are captured via an `if` condition so a non-zero status does not
# abort the run under errexit. Read-only dry-run codes (verified): 0 = clean,
# 8 = pending fixes. A "Files that were not fixed due to errors" line means a
# lint/parse failure in the scanned code (any mode) and is always fatal.

csFixerCommonArgs=(
  --config="$phpCsConfigPath"
  --cache-file="$phpCsCacheFile"
  --allow-risky=yes
  --show-progress=dots
  --path-mode=intersection
  -vvv
  fix
)

csFixerOutputFile="$varDir/php-cs-fixer-output.log"

if [[ "true" == "${qaReadOnly:-false}" ]]; then
  # READ-ONLY: single dry-run pass, no retry (retrying cannot change the result).
  echo "Running PHP CS Fixer in read-only check mode"
  csFixerExitCode=0
  if phpNoXdebug -f "$pharDir"/php-cs-fixer.phar -- \
    "${csFixerCommonArgs[@]}" \
    --dry-run \
    "${pathsToCheck[@]}" > "$csFixerOutputFile" 2>&1; then
    csFixerExitCode=0
  else
    csFixerExitCode=$?
  fi
  cat "$csFixerOutputFile"

  if grep -q "Files that were not fixed due to errors" "$csFixerOutputFile"; then
    echo ""
    echo "ERROR: PHP CS Fixer encountered linting errors that prevented checking files"
    echo "These errors must be fixed before continuing"
    echo ""
    exit 1
  fi

  if ((csFixerExitCode == 0)); then
    return 0
  fi
  if ((csFixerExitCode == 8)); then
    reportReadOnlyWouldModify "PHP CS Fixer" "fixer"
  fi
  echo "PHP CS Fixer failed with exit code $csFixerExitCode (a genuine error, not a pending-fix diff) — see output above."
  exit 1
fi

# WRITABLE: apply fixes, retry loop as before.
csFixerExitCode=99
while ((csFixerExitCode > 1)); do
  csFixerRunExit=0
  if phpNoXdebug -f "$pharDir"/php-cs-fixer.phar -- \
    "${csFixerCommonArgs[@]}" \
    "${pathsToCheck[@]}" > "$csFixerOutputFile" 2>&1; then
    csFixerRunExit=0
  else
    csFixerRunExit=$?
  fi
  csFixerExitCode=$csFixerRunExit
  cat "$csFixerOutputFile"

  # Check for linting errors in output
  if grep -q "Files that were not fixed due to errors" "$csFixerOutputFile"; then
    echo ""
    echo "ERROR: PHP CS Fixer encountered linting errors that prevented fixing files"
    echo "These errors must be fixed before continuing"
    echo ""
    exit 1
  fi

  if ((csFixerExitCode > 0)); then
    tryAgainOrAbort "PHP CS Fixer"
  fi
done
