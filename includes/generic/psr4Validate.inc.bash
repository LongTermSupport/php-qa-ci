# PSR-4 validation — asserts every PHP file's namespace/class matches its
# composer.json autoload (psr-4/psr-0) mapping, via bin/psr4-validate
# (src/Psr4Validator.php).
#
# HISTORY: this fragment was accidentally emptied by commit e0240dc
# (2023-12-18), which made the gate a silent no-op for ~19 months — `-t psr4`
# and the Phase-2 pipeline step reported success while validating nothing.
# Restored 2026-07-15 (enforcing immediately). Guarded by the gate-liveness
# test in tests/ so an empty fragment can never pass silently again.
#
# Ignore patterns come from psr4-validate-ignore-list.txt (project override via
# qaConfig/, see configPath). Each non-empty line is a PHP regex passed as an
# argument to bin/psr4-validate.

psr4IgnoreArgs=()
for psr4IgnorePattern in "${psr4IgnoreList[@]}"; do
  if [[ -n "${psr4IgnorePattern// /}" ]]; then
    psr4IgnoreArgs+=("$psr4IgnorePattern")
  fi
done

psr4ExitCode=99
while ((psr4ExitCode > 0)); do
  if phpNoXdebug -f "$binDir"/psr4-validate -- "${psr4IgnoreArgs[@]}"; then
    psr4ExitCode=0
  else
    psr4ExitCode=$?
  fi

  if ((psr4ExitCode > 0)); then
    tryAgainOrAbort "PSR-4 Validation"
  fi
done
