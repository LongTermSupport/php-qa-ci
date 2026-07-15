echo "

Setting Infection Minimums
--------------------------
"
# Monotonic ratchet — raise-only. Measured 84.68% covered MSI on 2026-07-15
# (first complete Infection run on this branch, after the T6.6 mutant-killing
# wave lifted it from 75.92%); floors set 2.7pp under the measurement to absorb
# run-to-run timeout variance (~0.4pp observed). Raise again as the score rises.
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