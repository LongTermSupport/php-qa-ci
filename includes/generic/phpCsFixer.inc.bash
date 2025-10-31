csFixerExitCode=99
while ((csFixerExitCode > 1)); do
  set +e

  # Capture output to detect linting errors
  # Pass pathsToCheck directly - when paths are specified, Finder in config is ignored
  csFixerOutput=$(phpNoXdebug -f "$pharDir"/php-cs-fixer.phar -- \
    --config="$phpCsConfigPath" \
    --cache-file="$phpCsCacheFile" \
    --allow-risky=yes \
    --show-progress=dots \
    -vvv \
    fix \
    ${pathsToCheck[@]} 2>&1)
  csFixerExitCode=$?

  # Display output
  echo "$csFixerOutput"

  set -e

  # Check for linting errors in output
  if echo "$csFixerOutput" | grep -q "Files that were not fixed due to errors"; then
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
