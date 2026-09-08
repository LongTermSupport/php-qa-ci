###############################################################################
# versionPins -- always-on check, linting phase.
#
# Every version pin in the QA configuration must match the toolchain in use:
# phpunit.xml's schema URL and SYMFONY_PHPUNIT_VERSION against the installed
# PHPUnit major; composer-require-checker's thecodingmachine/safe scan-files
# against the generated files safe loads on the running PHP; and the PHP
# version detection in GitHub Actions workflows against the PHP composer.json
# requires. A stale pin never fails a test run, so nothing else catches it
# when the PHP or PHPUnit requirement moves on. The PHP entry point is handed
# the same resolved config paths the phpunit and cr lanes run with.
# Identifier: phpqaci.versionPins (vendor/bin/rule-doc resolves it).
# shellcheck disable=SC2154 # binDir/projectRoot/phpUnitConfigPath/composerRequireCheckerConfig are set by bin/qa (setConfig) before this fragment is sourced
qaSimpleTool "Version Pins Check" phpNoXdebug -f "$binDir"/version-pins-check -- \
    "$projectRoot" "$phpUnitConfigPath" "$composerRequireCheckerConfig"
