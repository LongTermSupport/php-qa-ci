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