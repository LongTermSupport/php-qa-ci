csFixerExitCode=99
while ((csFixerExitCode > 1)); do
  set +e
  phpNoXdebug -f "$pharDir"/php-cs-fixer.phar -- \
    --config="$phpCsConfigPath" \
    --cache-file="$phpCsCacheFile" \
    --allow-risky=yes \
    --show-progress=dots \
    -vvv \
    fix
  csFixerExitCode=$?
  set -e
  if ((csFixerExitCode > 0)); then
    tryAgainOrAbort "PHP CS Fixer"
  fi
done
