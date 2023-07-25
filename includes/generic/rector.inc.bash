rectorExitCode=99
while ((rectorExitCode > 1)); do
  set +e
  phpNoXdebug -f "$binDir"/rector process ${pathsToCheck[@]} \
    --config "$projectRoot/vendor/thecodingmachine/safe/rector-migrate.php"
  rectorExitCode=$?
  set -e
  if ((rectorExitCode > 0)); then
    tryAgainOrAbort "Rector"
  fi
done
