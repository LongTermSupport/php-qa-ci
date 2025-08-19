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

// Placeholder bootstrap function - replace with your project's initialization
(function() {
    // This is a no-op placeholder function
    // Add your project-specific bootstrap logic here
})();
EOF
    echo "Placeholder bootstrap file created. Please customize it for your project needs."
fi

phpCmd=phpNoXdebug
if [[ "1" == "$phpUnitCoverage" ]]
then
    phpCmd="$phpBinPath"
fi
phpunitPath="$binDir"/phpunit
phpunitVersion="$("$phpCmd" -f "$phpunitPath" -- --version | grep -Po '\d+.\d+.\d+')"
phpunitVersionMajor="$(echo "$phpunitVersion" | cut -d . -f1)"
echo "PHPUnit Major Version: $phpunitVersionMajor"
paratestConfig=
echo "Checking for paratest"
if [[ -f "$binDir"/paratest ]]
then
    echo "Found paratest, using this instead of standard $binDir/phpunit"
    phpunitPath="$binDir"/paratest
    paratestConfig=(--phpunit "$binDir"/phpunit)
fi
phpunitFailedOnlyFiltered=0
phpunitExitCode=99
phpunitLogFilePath="$varDir/phpunit_logs/phpunit.junit.xml"
while (( phpunitExitCode > 0 ))
do
    extraConfigs=(" ")
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
      extraConfigs+=( --display-errors )
      extraConfigs+=( --display-notices )
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
        # When in CI and generating coverage - stop on first error, do not enforce time limits
        extraConfigs+=( --stop-on-failure --stop-on-error --stop-on-defect --stop-on-warning )
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

    phpUnitQuickTests="$phpUnitQuickTests" $phpCmd -f $phpunitPath \
        -- \
        ${paratestConfig[@]} \
        -c ${phpUnitConfigPath} \
        ${extraConfigs[@]} \
        ${pathArgs[@]}

    phpunitExitCode=$?
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
