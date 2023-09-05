# Note the phpUnitQuickTests=$phpUnitQuickTests
# this sets a config variable which you can then use
# to allow tests to run less thoroughly but more quickly
# @see https://github.com/edmondscommerce/phpqa#quick-tests

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
      extraConfigs+=( --testdox )
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
        # No Coverage mode - do not generate coverage, do enforce time limits
        extraConfigs+=( --no-coverage )
        extraConfigs+=( --enforce-time-limit )
    elif [[ "false" != "${CI:-'false'}" ]]
    then
        # When in CI and generating coverage - stop on first error, do not enforce time limits
        extraConfigs+=( --stop-on-failure --stop-on-error --stop-on-defect --stop-on-warning )
    fi

    set +e
    set -x

    phpUnitQuickTests="$phpUnitQuickTests" $phpCmd -f $phpunitPath \
        -- \
        ${paratestConfig[@]} \
        -c ${phpUnitConfigPath} \
        ${extraConfigs[@]} \
        --fail-on-risky \
        --fail-on-warning \
        --log-junit "$phpunitLogFilePath"

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
