echo "

Setting Infection Minimums
--------------------------
"
# Monotonic ratchet — raise-only. The floors sit a few points under the last
# measured covered MSI to absorb run-to-run timeout variance. Raise them as the
# score rises; never lower them.
# shellcheck disable=SC2034 # consumed by includes/generic/infection.inc.bash (via the minMsi/
#   minCoveredMsi fallback chain) once this project override has been sourced by bin/qa.
infectionMutationScoreIndicator=82
# shellcheck disable=SC2034 # consumed by includes/generic/infection.inc.bash, same as above
infectionCoveredCodeMSI=82

pathsToIgnore=()
pathsToIgnore+=( "tests/assets" )
pathsToIgnore+=( "src/PHPUnit/TestDox" )
echo "
pathsToIgnore set to:
${pathsToIgnore[*]}

"

# SensitiveParameter usage check — OPT OUT (canonical worked example).
# php-qa-ci is a pure QA/tooling library: it never receives a password, token or
# secret of its own, so there is legitimately no #[\SensitiveParameter] anywhere
# in its src/. We therefore disable the always-on usage check for THIS project.
# This is exactly the escape hatch documented for downstream consumers.
export useSensitiveParameterCheck=0