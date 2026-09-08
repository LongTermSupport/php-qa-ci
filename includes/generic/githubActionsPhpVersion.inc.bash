###############################################################################
# githubActionsPhpVersion -- always-on check, linting phase.
#
# Every GitHub Actions workflow under .github/workflows/ and every shipped
# template under templates/github-actions/ that derives a runner PHP from
# composer.json's constraint must be able to select, and default to, the PHP
# version composer.json requires. The detection is a hand-written version
# list, so a PHP bump that misses one workflow quietly runs an older PHP.
# Where both the shipped consumer template and this repository's own qa.yml
# exist they must be identical. The PHP entry point is handed $projectRoot.
# Identifier: phpqaci.githubActionsPhpVersion (vendor/bin/rule-doc resolves it).
# shellcheck disable=SC2154 # binDir/projectRoot are set by bin/qa (setConfig) before this fragment is sourced
qaSimpleTool "GitHub Actions PHP Version Check" phpNoXdebug -f "$binDir"/github-actions-php-version-check -- "$projectRoot"
