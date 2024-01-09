yamlLintExistCode=99
set +e
while (( yamlLintExistCode > 0 ))
do
    phpNoXdebug -f "$binDir"/console -- lint:yaml --parse-tags ${yamlDirectories[@]}
    yamlLintExistCode=$?
    if (( yamlLintExistCode > 0 ))
    then
        tryAgainOrAbort "Yaml Lint"
    fi
done
set -e
