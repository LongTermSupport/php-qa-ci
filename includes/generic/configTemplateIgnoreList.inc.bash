###############################################################################
# configTemplateIgnoreList -- always-on self-check, linting phase.
#
# Audits php-qa-ci's OWN shipped configDefaults/generic/: every namespace-less
# config template a consumer is documented to copy into qaConfig/ must be
# matched by a pattern in psr4-validate-ignore-list.txt, or the documented
# override fails psr4Validate. The PHP entry point resolves the directory
# relative to its own class, so the check is meaningful whether php-qa-ci is
# the root project or installed under a consumer's vendor/.
# Identifier: phpqaci.configTemplateIgnoreList (vendor/bin/rule-doc resolves it).
# shellcheck disable=SC2154 # binDir is set by bin/qa (setConfig) before this fragment is sourced
qaSimpleTool "Config Template Ignore-List Audit" phpNoXdebug -f "$binDir"/config-template-ignorelist-check
