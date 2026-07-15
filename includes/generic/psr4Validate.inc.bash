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

# shellcheck disable=SC2154 # psr4IgnoreList/binDir are set by bin/qa (setConfig) before this fragment is sourced
psr4IgnoreArgs=()
for psr4IgnorePattern in "${psr4IgnoreList[@]}"; do
  if [[ -n "${psr4IgnorePattern// /}" ]]; then
    psr4IgnoreArgs+=("$psr4IgnorePattern")
  fi
done

# Retry loop via the shared driver (M-010) — identical behaviour to the
# hand-written loop it replaces.
qaSimpleTool "PSR-4 Validation" phpNoXdebug -f "$binDir"/psr4-validate -- "${psr4IgnoreArgs[@]}"
