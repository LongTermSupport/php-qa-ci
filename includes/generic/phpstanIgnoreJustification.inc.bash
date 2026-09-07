###############################################################################
# phpstanIgnoreJustification — always-on check over the project record.
#
# PHPStan's ignoreErrors in qaConfig/phpstan.neon is where a project keeps the
# exceptions it has decided to live with, and PHPStan gives an entry no field
# for a reason. This lane requires one: a comment directly above each entry
# that names the hazard accepted and why it is acceptable at that path, and
# rejects a phrase that would fit any entry unchanged. The PHP entrypoint is
# bin/phpstan-ignore-justification (IgnoreErrorsJustificationCheck::main()).
###############################################################################

# shellcheck disable=SC2154 # binDir is set by bin/qa (setConfig) before this fragment is sourced
qaSimpleTool "PHPStan ignoreErrors Justification Check" phpNoXdebug -f "$binDir"/phpstan-ignore-justification
