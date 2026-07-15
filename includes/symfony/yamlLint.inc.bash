# yamlDirectories is populated in includes/symfony/setConfig.inc.bash (defaults
# to $projectRoot/config). lint:yaml errors on a missing path, so filter to the
# directories that actually exist and skip cleanly when none do — a project
# without a config/ dir has nothing to lint.
yamlLintDirs=()
for yamlDir in "${yamlDirectories[@]}"; do
    if [[ -d "$yamlDir" ]]; then
        yamlLintDirs+=("$yamlDir")
    fi
done

if (( ${#yamlLintDirs[@]} == 0 )); then
    echo "Yaml Lint: none of the configured YAML directories exist (checked: ${yamlDirectories[*]}) — skipping."
    return 0
fi

# Exit code captured via the `if` condition so a non-zero status does not abort
# the run under errexit, and no errexit toggling is required.
yamlLintExitCode=99
while (( yamlLintExitCode > 0 ))
do
    if phpNoXdebug -f bin/console -- lint:yaml --parse-tags "${yamlLintDirs[@]}"; then
        yamlLintExitCode=0
    else
        yamlLintExitCode=$?
    fi
    if (( yamlLintExitCode > 0 ))
    then
        tryAgainOrAbort "Yaml Lint"
    fi
done
