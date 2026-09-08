###############################################################################
# phpunitConfigVersion -- always-on check, linting phase.
#
# The resolved phpunit.xml's version pins — the schema URL in
# xsi:noNamespaceSchemaLocation and any SYMFONY_PHPUNIT_VERSION pin — must
# match the MAJOR version of the PHPUnit that is installed. A stale pin never
# fails a test run, so nothing else catches it when the PHPUnit requirement
# moves on. The PHP entry point is handed $phpUnitConfigPath, the same resolved
# path the phpunit step runs with.
# Identifier: phpqaci.phpunitConfigVersion (vendor/bin/rule-doc resolves it).
# shellcheck disable=SC2154 # binDir/phpUnitConfigPath are set by bin/qa (setConfig) before this fragment is sourced
qaSimpleTool "PHPUnit Config Version Check" phpNoXdebug -f "$binDir"/phpunit-config-version-check -- "$phpUnitConfigPath"
