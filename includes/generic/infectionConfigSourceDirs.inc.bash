###############################################################################
# infectionConfigSourceDirs -- always-on check, linting phase.
#
# infection.json's declared `source.directories` entries must resolve,
# relative to infection.json's own directory (not the project root or CWD),
# to real directories on disk — matching how Infection itself resolves them.
# The PHP entry point is handed $infectionConfig, the same resolved path
# setConfig.inc.bash computes, so it validates exactly the file Infection
# will read.
# Identifier: phpqaci.infectionConfigSourceDirectoriesMustExist (vendor/bin/rule-doc resolves it).
# shellcheck disable=SC2154 # binDir/infectionConfig are set by bin/qa (setConfig) before this fragment is sourced
qaSimpleTool "Infection Config Source Directories Check" phpNoXdebug -f "$binDir"/infection-config-source-dirs-check -- "$infectionConfig"
