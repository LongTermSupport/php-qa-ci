#!/usr/bin/env bash
# PHPArkitect — architectural rule enforcement (class naming, namespace
# dependencies, layering). https://github.com/phparkitect/arkitect
#
# OPT-IN tool. Architecture rules are inherently project-specific, so this tool
# only runs when the project supplies a config at qaConfig/phparkitect.php
# (resolved through configPath, so a platform/generic default is honoured too).
# When no config is present it skips cleanly, leaving existing consumers
# unaffected.
#
# NOTE: unlike most tools the paths to scan live INSIDE the config file (via
# ClassSet::fromDir(...)), so the pipeline's -p path specification does not
# apply — this tool is classified as non-path-supporting in options.inc.bash.

if [[ ! -f "$phpArkitectConfigPath" ]]; then
  echo "PHPArkitect: no architecture config found — skipping (opt-in tool)."
  echo "  Enable it by copying the template into your project:"
  echo "    cp $(readlink -f ./../templates/qaConfig-phparkitect.php) ${projectConfigPath}phparkitect.php"
  return 0
fi

echo "PHPArkitect: using config $phpArkitectConfigPath"

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
