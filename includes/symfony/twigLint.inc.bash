# twigLint — Symfony `bin/console lint:twig` over twigDirectories (populated in
# includes/symfony/setConfig.inc.bash). Skips when the command or the twig
# bundle is absent.

# shellcheck disable=SC2154 # projectRoot is set by bin/qa (setConfig) before this fragment is sourced
if ! phpNoXdebug -f bin/console | grep -q 'lint:twig'; then
  echo "Twig Lint not found in bin/console, skipping"
elif [[ -d "$projectRoot/vendor/symfony/twig-bundle" ]]; then
  # shellcheck disable=SC2154 # twigDirectories is set by includes/symfony/setConfig.inc.bash
  qaSimpleTool "Twig Lint" phpNoXdebug -f bin/console -- lint:twig "${twigDirectories[@]}"
else
  echo "Twig Not Installed, nothing to do"
fi
