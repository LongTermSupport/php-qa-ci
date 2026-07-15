#!/usr/bin/env bash
# shellcheck disable=SC2154 # phpArkitectConfigPath/srcDir/varDir/pharDir/projectRoot/
#   specifiedPath/pathsToCheck are core pipeline variables bin/qa (setConfig)
#   sets before this fragment is sourced — genuine sourced-fragment architecture.
#
# PHPArkitect — architectural rule enforcement (class naming, namespace
# dependencies, layering). https://github.com/phparkitect/arkitect
#
# ON BY DEFAULT. configPath always resolves an entry config:
#   - the project's qaConfig/phparkitect.php if present, else
#   - the shipped configDefaults/generic/phparkitect.php, which applies the
#     generic-safe default baseline (phparkitect-rules-default.php) to the
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
# apply — this tool is classified as non-path-supporting in the tool registry
# (includes/generic/toolRegistry.inc.bash).

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
export PHPQACI_ARKITECT_RULES_DEFAULT
PHPQACI_ARKITECT_RULES_DEFAULT="$(configPath phparkitect-rules-default.php)"
export PHPQACI_ARKITECT_RULES_OPTIONAL
PHPQACI_ARKITECT_RULES_OPTIONAL="$(configPath phparkitect-rules-optional.php)"
export PHPQACI_ARKITECT_RULES_OPTIONAL_SYMFONY
PHPQACI_ARKITECT_RULES_OPTIONAL_SYMFONY="$(configPath phparkitect-rules-optional-symfony.php)"
# Reusable consumer API-boundary rule FACTORY (not a tier). A consumer of a
# type:library package loads this from its qaConfig/phparkitect.php to forbid its
# own code from depending on that library's @internal namespaces — reaching it
# only via the public @api namespace. See configDefaults/generic/phparkitect-consumer-api-boundary.php.
export PHPQACI_ARKITECT_CONSUMER_API_BOUNDARY
PHPQACI_ARKITECT_CONSUMER_API_BOUNDARY="$(configPath phparkitect-consumer-api-boundary.php)"

# Project-declared generated/excluded paths. A project lists extra paths to
# exclude from arkitect (on top of the built-in 'Generated' convention) in
# qaConfig/qaConfig.inc.bash, e.g.:
#   arkitectExcludePaths+=("Quote/API")
# This is how a project tells arkitect to ignore GENERATED code that lives at an
# arbitrary path (a jane-php OpenAPI client, protobuf stubs, etc.) without having
# to copy the whole entry config. Each entry is matched by arkitect
# (Arkitect\Glob::toRegex) against the path RELATIVE to src/, so "Quote/API"
# excludes src/Quote/API/**. Newline-delimited, mirroring how rectorIgnorePaths
# is passed in rector.inc.bash. Empty/unset => only the built-in 'Generated'
# exclude applies (fully backward compatible).
arkitectExcludePathsExport=""
if [[ -n "${arkitectExcludePaths[*]:-}" ]]; then
  arkitectExcludePathsExport="$(printf '%s\n' "${arkitectExcludePaths[@]}")"
fi
export PHPQACI_ARKITECT_EXCLUDE_PATHS="$arkitectExcludePathsExport"

phpArkitectLogDir="$varDir/phparkitect_logs"
phpArkitectLogFile="phparkitect.log"
mkdir -p "$phpArkitectLogDir"

# Retry loop. Mirrors phpstan.inc.bash / phpunit.inc.bash:
#   - The invocation runs inside an `if` CONDITION, so under bin/qa's errexit
#     option a non-zero exit does NOT abort the run (errexit is suspended for
#     conditions) — handled explicitly here.
#   - We read ${PIPESTATUS[0]} to get arkitect's OWN exit code, independent of the
#     `tee` (a tee write failure must not be misread as an arkitect failure).
#   - arkitect's CLI returns 0 = ok, 1 = rule violations, >1 = a genuine error
#     (bad config, parse failure, INCOMPLETE AUTOLOADER for IsA/ancestry rules).
#     We special-case >1 as a crash — retrying it cannot help — exactly as
#     phpstan/phpunit do for their crash codes.
# arkitect must run from the project root so relative ClassSet paths in the
# config resolve correctly; bin/qa has already `cd`-ed to $projectRoot.
arkitectExitCode=99
while ((arkitectExitCode > 0)); do
  if phpNoXdebug -f "$pharDir"/phparkitect.phar -- \
        check \
        --config="$phpArkitectConfigPath" \
        --autoload="$projectRoot/vendor/autoload.php" \
        --no-interaction \
        2>&1 | tee "$phpArkitectLogDir/$phpArkitectLogFile"
  then
    arkitectExitCode=0
  else
    arkitectExitCode=${PIPESTATUS[0]}
  fi

  # Archive the log (keep last 10) every iteration so it survives in CI, on both
  # success and failure.
  archiveToolLog "PHPArkitect" "$phpArkitectLogDir" "$phpArkitectLogFile" "$specifiedPath" "${pathsToCheck[@]}"

  if ((arkitectExitCode > 1)); then
    printf "\n\nPHPArkitect crashed (exit %s) — this is an ERROR, not a rule violation.\n" "$arkitectExitCode"
    printf "Likely causes: an invalid phparkitect config, an unparseable source file, or a bad\n"
    printf "ClassSet path. Inspect the output above; retrying will not help.\n"
    printf "NOTE: an INCOMPLETE autoloader does NOT crash — instead the optional/symfony tiers'\n"
    printf "ancestry rules (IsA) silently match nothing (a false green). If those rules seem to\n"
    printf "find no violations, make your classes autoloadable: run 'composer dump-autoload'.\n\n"
    exit 1
  fi

  if ((arkitectExitCode > 0)); then
    tryAgainOrAbort "PHPArkitect"
  fi
done
