echo "

Setting Infection Minimums
--------------------------
"
infectionMutationScoreIndicator=71
infectionCoveredCodeMSI=77

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