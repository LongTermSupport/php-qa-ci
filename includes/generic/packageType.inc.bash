###############################################################################
# packageType — always-on check: composer.json MUST declare `type` explicitly.
#
# Composer silently defaults an omitted `type` to `library`, quietly deciding
# app-vs-library for you. This check rejects the silent default so the decision
# is conscious: the file must say `library` (an installable dependency — whose
# public surface is then @api/@internal-classified by RequireApiOrInternalTagRule)
# or `project` (an application), or any other explicit Composer type.
#
# Default-on, no opt-out: declaring one composer.json line is trivial and the
# app-vs-library decision must always be made.
#
# Runs in the linting phase, right after composerChecks. The PHP entrypoint is
# bin/package-type-check (delegates to
# LTS\PHPQA\PackageType\ExplicitPackageTypeCheck::main()).
###############################################################################

# Retry loop via the shared driver (M-010) — errexit-safe exit-code capture and
# tryAgainOrAbort handling, identical to the hand-written loop it replaces.
# shellcheck disable=SC2154 # binDir is set by bin/qa (setConfig) before this fragment is sourced
qaSimpleTool "Package Type Declaration Check" phpNoXdebug -f "$binDir"/package-type-check
