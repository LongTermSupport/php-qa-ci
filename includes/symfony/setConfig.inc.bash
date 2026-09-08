# setConfig (symfony) — the generic config plus the directories the Symfony
# twig/yaml linters scan. Consumed by later-sourced fragments.
# shellcheck disable=SC2034,SC2154 # twigDirectories/yamlDirectories are read by twigLint/yamlLint; DIR/projectRoot are set by bin/qa
# shellcheck source-path=SCRIPTDIR
# shellcheck source=../generic/setConfig.inc.bash
source "${DIR}/../includes/generic/setConfig.inc.bash"

## Directories that should have the twig lint tool run against them - see php bin/console help lint:twig
twigDirectories=()
twigDirectories+=("${projectRoot}/templates")

## Directories that should have the yaml lint tool run against them - see php bin/console help lint:yaml
yamlDirectories=()
yamlDirectories+=("${projectRoot}/config")
