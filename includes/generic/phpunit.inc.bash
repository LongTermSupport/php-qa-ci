# shellcheck disable=SC2154 # testsDir/phpUnitCoverage/phpBinPath/binDir/varDir/
#   phpUnitIterativeMode/phpUnitQuickTests/phpUnitConfigPath are core pipeline
#   variables bin/qa (setConfig + project qaConfig.inc.bash) sets before this
#   fragment is sourced — genuine sourced-fragment architecture, not unset vars.
#
# Note the phpUnitQuickTests=$phpUnitQuickTests
# this sets a config variable which you can then use
# to allow tests to run less thoroughly but more quickly
# @see https://github.com/edmondscommerce/phpqa#quick-tests

# Check for bootstrap file and create a placeholder if it doesn't exist
bootstrapFile="$testsDir/bootstrap.php"
if [[ ! -f "$bootstrapFile" ]]; then
    echo "Creating placeholder bootstrap file at $bootstrapFile"
    cat > "$bootstrapFile" << 'EOF'
<?php

declare(strict_types=1);

/**
 * PHPUnit Bootstrap File
 * 
 * This is a placeholder bootstrap file created by PHP-QA-CI.
 * 
 * PURPOSE:
 * The bootstrap file is executed before PHPUnit runs any tests.
 * It's used to set up the testing environment, including:
 * - Loading the autoloader
 * - Setting environment variables
 * - Initializing framework components
 * - Configuring test databases
 * 
 * SYMFONY PROJECTS:
 * For Symfony projects, you should typically:
 * 1. Load the autoloader
 * 2. Use Dotenv to load .env.test
 * 
 * Example for Symfony:
 * ```php
 * use Symfony\Component\Dotenv\Dotenv;
 * 
 * require dirname(__DIR__).'/vendor/autoload.php';
 * 
 * if (method_exists(Dotenv::class, 'bootEnv')) {
 *     (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
 * }
 * ```
 * 
 * REPLACE THIS FILE with your project-specific bootstrap logic.
 */

// Load composer autoloader
require dirname(__DIR__) . '/vendor/autoload.php';

// Uncomment and add your project-specific bootstrap logic here:
// (static function (): void {
//     // e.g. set environment variables, initialise framework, configure test database
// })();
EOF
    echo "Placeholder bootstrap file created. Please customize it for your project needs."
fi

phpCmd=phpNoXdebug
# phpNoXdebug applies the global phpqaMemoryLimit internally. The coverage path
# bypasses it (it must run the Xdebug-enabled binary directly), so it would
# otherwise fall back to PHP's default memory_limit. Apply the same global limit
# explicitly in that case so a coverage run is not silently capped.
phpUnitMemoryArgs=()
if [[ "1" == "$phpUnitCoverage" ]]
then
    phpCmd="$phpBinPath"
    phpUnitMemoryArgs=(-d memory_limit="${phpqaMemoryLimit:-4G}")
fi
phpunitPath="$binDir"/phpunit
phpunitVersion="$("$phpCmd" -f "$phpunitPath" -- --version | grep -Po '\d+.\d+.\d+')"
phpunitVersionMajor="$(echo "$phpunitVersion" | cut -d . -f1)"
echo "PHPUnit Major Version: $phpunitVersionMajor"
paratestConfig=()
echo "Checking for paratest"
if [[ -f "$binDir"/paratest ]]
then
    echo "Found paratest, using this instead of standard $binDir/phpunit"
    phpunitPath="$binDir"/paratest
    paratestConfig=(--phpunit "$binDir"/phpunit)
fi
phpunitExitCode=99
phpunitLogFilePath="$varDir/phpunit_logs/phpunit.junit.xml"
phpunitLogDir="$varDir/phpunit_logs"

while (( phpunitExitCode > 0 ))
do
    extraConfigs=()
    extraConfigs+=( --strict-global-state )
# Enabling testdox seems to prevent displaying of warnings
#    extraConfigs+=( --testdox )
    extraConfigs+=( --fail-on-risky )
    extraConfigs+=( --fail-on-warning )
    extraConfigs+=( --log-junit "$phpunitLogFilePath" )
    if(( $phpunitVersionMajor >= 10 ))
    then
      extraConfigs+=( --colors=always )
      extraConfigs+=( --display-incomplete )
      extraConfigs+=( --display-skipped )
      extraConfigs+=( --display-deprecations )
      extraConfigs+=( --display-phpunit-deprecations )
      extraConfigs+=( --display-errors )
      extraConfigs+=( --display-notices )
      extraConfigs+=( --display-phpunit-notices )
      extraConfigs+=( --display-warnings )
    fi

    ## MODES
    if [[ "1" == "$phpUnitIterativeMode" ]]
    then
        # Uniterate mode - order by defects, stop on first error, no coverage and enforce time limits
        echo
        echo "Uniterate Mode - Iterative Testing with Fast Failure"
        echo "----------------------------------------------------"
        echo
        # shellcheck disable=SC2054 # single --order-by=VALUE argument; PHPUnit's own
        #   syntax for that value is a comma-separated list, not two array elements.
        extraConfigs+=( --order-by=depends,defects )
        extraConfigs+=( --stop-on-failure --stop-on-error --stop-on-defect --stop-on-warning )
        extraConfigs+=( --no-coverage )
        extraConfigs+=( --enforce-time-limit )
    elif [[ "1" != "$phpUnitCoverage" ]]
    then
        # No Coverage mode - do not generate coverage
        export XDEBUG_MODE=off
        extraConfigs+=( --no-coverage )
        extraConfigs+=( --enforce-time-limit )
    elif [[ "false" != "${CI:-'false'}" ]]
    then
        # When in CI and generating coverage - do not enforce time limits
        # Note: Removed stop-on-failure flags to allow full test runs in CI
        : # No-op to keep the block valid
    else
      # Default, do enforce timelimits
      extraConfigs+=( --enforce-time-limit )
    fi

    set +e
    set -x

    # If specific paths are provided, append them after the config options
    pathArgs=()
    if [[ -n "$specifiedPath" ]]; then
        pathArgs+=("${pathsToCheck[@]}")
        echo "Running PHPUnit on specified paths: ${pathsToCheck[*]}"
    fi

    # Capture both JUnit XML (via --log-junit) and stdout (via tee)
    phpUnitQuickTests="$phpUnitQuickTests" "$phpCmd" "${phpUnitMemoryArgs[@]}" -f "$phpunitPath" \
        -- \
        "${paratestConfig[@]}" \
        -c "$phpUnitConfigPath" \
        "${extraConfigs[@]}" \
        "${pathArgs[@]}" \
        2>&1 | tee "$phpunitLogDir/phpunit.log"

    phpunitExitCode=${PIPESTATUS[0]}
    set +x
    set -e


    if [[ "" != "$(grep '<testsuites/>' $phpunitLogFilePath)" || ! -f  $phpunitLogFilePath ]]
    then
        echo "

        ERROR - no tests have been run!

        Please ensure you have at least one valid test suite configured in your phpunit.xml file

        "
        phpunitExitCode=1
    fi

    # Archive both log files BEFORE tryAgainOrAbort so logs are saved even on failure (in CI mode)
    # But AFTER PHPUnit has finished writing files
    echo ""
    echo "Log Archival"
    echo "============"
    # Archive JUnit XML for parse-junit-logs.py
    archiveToolLog "PHPUnit JUnit XML" "$phpunitLogDir" "phpunit.junit.xml" "$specifiedPath" "${pathsToCheck[@]}"
    echo ""
    # Archive human-readable stdout log
    archiveToolLog "PHPUnit stdout" "$phpunitLogDir" "phpunit.log" "$specifiedPath" "${pathsToCheck[@]}"

    # Extract and display test result summary.
    # Strip ANSI colour codes first because PHPUnit emits the summary line
    # wrapped in escape sequences when --colors=always is used, which would
    # defeat a `^Tests:` anchored grep. Use an explicit `grep -q` guard so a
    # missing summary line is handled cleanly — bin/qa runs with `set -o
    # pipefail` + `set -e`, so an unconditional grep in a pipeline would abort
    # the whole run on no-match.
    if [[ -f "$phpunitLogDir/phpunit.log" ]]; then
        cleanedLog=$(sed -r 's/\x1B\[[0-9;]*[a-zA-Z]//g' "$phpunitLogDir/phpunit.log")
        if grep -qE '^Tests:.*Assertions:' <<< "$cleanedLog"; then
            testSummary=$(grep -E '^Tests:.*Assertions:' <<< "$cleanedLog" | tail -n1)
            echo "Result: $testSummary"
        fi
    fi

    if (( $phpunitExitCode > 0 ))
    then
        if (( $phpunitExitCode > 2 ))
        then
            printf "\n\n\nPHPUnit Crashed\n\nRunning again with Debug mode...\n\n\n"
            qaQuickTests="$phpUnitQuickTests" phpNoXdebug -f "$binDir"/phpunit -- "$testsDir" --debug

        fi
        tryAgainOrAbort "PHPUnit Tests"
    fi
done

set -e
