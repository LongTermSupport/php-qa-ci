yamlLintExistCode=99
set +e
while (( yamlLintExistCode > 0 ))
do
    phpNoXdebug -f "./$binDir"/console -- lint:yaml ${yamlDirectories[@]}
    yamlLintExistCode=$?
    if (( yamlLintExistCode > 0 ))
    then
        tryAgainOrAbort "Yaml Lint"
    fi
done
set -e
