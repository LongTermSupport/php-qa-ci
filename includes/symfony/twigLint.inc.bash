# shellcheck disable=SC2154 # projectRoot is set by bin/qa (setConfig) before this fragment is sourced
if ! phpNoXdebug -f bin/console |grep -q 'lint:twig'; then
  echo "Twig Lint not found in bin/console, skipping"
elif [[ -d $projectRoot/vendor/symfony/twig-bundle ]]; then
  twigLintExitCode=99
  set +e

  while ((twigLintExitCode > 0)); do
    # shellcheck disable=SC2154 # twigDirectories is set by includes/symfony/setConfig.inc.bash
    phpNoXdebug -f bin/console -- lint:twig "${twigDirectories[@]}"
    twigLintExitCode=$?
    if ((twigLintExitCode > 0)); then
      tryAgainOrAbort "Twig Lint"
    fi
  done
  set -e
else
  echo "Twig Not Installed, nothing to do"
fi
