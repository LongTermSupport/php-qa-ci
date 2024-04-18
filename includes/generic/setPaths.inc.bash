# project tests folder
testsDir="$(findTestsDir)"
echo "testsDir: $testsDir"

# project src folder
srcDir="$(findSrcDir)"
echo "srcDir: $srcDir"

# project bin dir
binDir="$(findBinDir)"
echo "binDir: $binDir"

# An array of paths that are to be checked
pathsToCheck=()
pathsToCheck+=($testsDir)
pathsToCheck+=($srcDir)
#pathsToCheck+=($binDir)

# An array of paths that are to be ignored
pathsToIgnore=()
pathsToIgnore+=( "placeholder-ignore-item" )
