#!/usr/bin/env bash
# PHPArkitect — architectural rule enforcement (class naming, namespace
# dependencies, layering). https://github.com/phparkitect/arkitect
#
# ON BY DEFAULT. configPath always resolves an entry config:
#   - the project's qaConfig/phparkitect.php if present, else
#   - the shipped configDefaults/generic/phparkitect.php, which applies the
#     generic-safe BallicomDev baseline (phparkitect-rules-default.php) to the
#     detected source dir.
# This is the arkitect equivalent of the rules-default PHPStan baseline.
#
# Turn it off for a project with `export useArkitect=0` in qaConfig/qaConfig.inc.bash.
#
# Rule TIERS shipped by php-qa-ci (composable from a project's phparkitect.php):
#   PHPQACI_ARKITECT_RULES_DEFAULT           generic-safe baseline (on by default)
#   PHPQACI_ARKITECT_RULES_OPTIONAL          stricter generic, opt-in
#   PHPQACI_ARKITECT_RULES_OPTIONAL_SYMFONY  Symfony-specific, opt-in
# Each is resolved through configPath, so a project can override any tier by
# dropping its own copy in qaConfig/.
#
# NOTE: unlike most tools the paths to scan live INSIDE the config file (via
# ClassSet::fromDir(...)), so the pipeline's -p path specification does not
# apply — this tool is classified as non-path-supporting in options.inc.bash.

useArkitect=${useArkitect:-1}
if [[ "1" != "$useArkitect" ]]; then
  echo "PHPArkitect: disabled (useArkitect=$useArkitect) — skipping."
  return 0
fi

if [[ ! -f "$phpArkitectConfigPath" ]]; then
  # configPath normally resolves the shipped default entry config, so this only
  # happens if that file is missing from the install — fail safe by skipping.
  echo "PHPArkitect: no entry config resolved at $phpArkitectConfigPath — skipping."
  return 0
fi

echo "PHPArkitect: using config $phpArkitectConfigPath"

# Expose the detected source dir and the resolved rule-tier files to the config
# being evaluated, so a project's phparkitect.php can compose them without
# hard-coding the vendor layout, e.g.:
#   $default = require getenv('PHPQACI_ARKITECT_RULES_DEFAULT');
#   $config->add($classSet, ...$default, ...$projectRules);
# configPath honours project overrides (qaConfig/phparkitect-rules-*.php), so a
# project can also customise any tier. The phar's PHP process inherits these via
# phpNoXdebug.
export PHPQACI_ARKITECT_SRC_DIR="$srcDir"
export PHPQACI_ARKITECT_RULES_DEFAULT="$(configPath phparkitect-rules-default.php)"
export PHPQACI_ARKITECT_RULES_OPTIONAL="$(configPath phparkitect-rules-optional.php)"
export PHPQACI_ARKITECT_RULES_OPTIONAL_SYMFONY="$(configPath phparkitect-rules-optional-symfony.php)"

phpArkitectLogDir="$varDir/phparkitect_logs"
phpArkitectLogFile="phparkitect.log"
mkdir -p "$phpArkitectLogDir"

# Retry loop. The tool runs inside the `until` condition so that, under the
# pipeline's `set -e`, a non-zero exit does NOT abort the run — it is handled
# explicitly. `set -o pipefail` (set by bin/qa) propagates arkitect's exit code
# through the `tee`, so the condition reflects the real result rather than tee's.
# arkitect must run from the project root so relative ClassSet paths in the
# config resolve correctly; bin/qa has already `cd`-ed to $projectRoot.
until phpNoXdebug -f "$pharDir"/phparkitect.phar -- \
        check \
        --config="$phpArkitectConfigPath" \
        --autoload="$projectRoot/vendor/autoload.php" \
        --no-interaction \
        2>&1 | tee "$phpArkitectLogDir/$phpArkitectLogFile"
do
  # Archive the log (keep last 10) BEFORE tryAgainOrAbort so it survives in CI.
  archiveToolLog "PHPArkitect" "$phpArkitectLogDir" "$phpArkitectLogFile" "$specifiedPath" "${pathsToCheck[@]}"
  tryAgainOrAbort "PHPArkitect"
done

# Archive the successful run's log too, for parity with the failure path.
archiveToolLog "PHPArkitect" "$phpArkitectLogDir" "$phpArkitectLogFile" "$specifiedPath" "${pathsToCheck[@]}"
