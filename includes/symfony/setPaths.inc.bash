# Tests
testsDir="$(findTestsDir)"

# An array of paths that are to be checked
pathsToCheck=()
pathsToCheck+=("$projectRoot/src");
pathsToCheck+=("$projectRoot/tests");

# An array of paths that are to be ignored
pathsToIgnore=()
pathsToIgnore+=("placeholder-ignore-item")
