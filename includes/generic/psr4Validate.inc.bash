# psr4Validate — asserts every PHP file's namespace/class matches its
# composer.json autoload (psr-4/psr-0) mapping, via bin/psr4-validate
# (src/Psr4Validator.php). The gate-liveness test in tests/Small/Pipeline
# guarantees this fragment always contains executable code.
#
# Ignore patterns come from psr4-validate-ignore-list.txt (project override via
# qaConfig/, see configPath). Each non-empty line is a PHP regex passed as an
# argument to bin/psr4-validate.

# shellcheck disable=SC2154 # psr4IgnoreList/binDir are set by bin/qa (setConfig) before this fragment is sourced
psr4IgnoreArgs=()
for psr4IgnorePattern in "${psr4IgnoreList[@]}"; do
  if [[ -n "${psr4IgnorePattern// /}" ]]; then
    psr4IgnoreArgs+=("$psr4IgnorePattern")
  fi
done

qaSimpleTool "PSR-4 Validation" phpNoXdebug -f "$binDir"/psr4-validate -- "${psr4IgnoreArgs[@]}"
