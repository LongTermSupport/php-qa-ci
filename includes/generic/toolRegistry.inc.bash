#!/usr/bin/env bash
###############################################################################
# Tool registry — SINGLE SOURCE OF TRUTH for the QA tool set.
#
# The registry is declarative and single-sourced; everything else derives
# from it:
#
#   - options.inc.bash DERIVES the -t alias resolution, the path-support gate
#     arrays, and the usage text from here (qaResolveSingleTool /
#     qaBuildPathSupportArrays / the usage loop).
#   - the phase runners (all*Tools.inc.bash) DERIVE their ordered tool sequence
#     from here (qaRunPhase / qaToolsForPhase).
#
# This file is PURE DATA + PURE FUNCTIONS with no dependency on any pipeline
# variable, so it is safe to source standalone (the characterisation tests do).
#
# It is sourced EARLY, by options.inc.bash, before functions.inc.bash — so it
# must not call anything defined there. qaRunPhase calls runToolGuarded, but
# only at phase-execution time (long after functions.inc.bash is sourced), so
# that forward reference is fine.
#
# CONTRACT PRESERVATION: the accepted -t tokens, their canonical targets, the
# path-support classification of every token, and the phase ordering are frozen
# by tests/Small/Pipeline/ToolRegistryCharacterisationTest.php. Changing any of
# them is a deliberate behavioural change, not a refactor.
#
# NOTE ON IFS: bin/qa runs with IFS=$'\n\t' (space is NOT a delimiter), so the
# space-separated alias lists below would not word-split under the ambient IFS.
# Every function that splits an alias list sets `local IFS=$' \t\n'` first.
###############################################################################

# Ordered canonical tool names. Order is authoritative: it drives the usage
# listing and, filtered by phase, the phase execution order.
QA_TOOL_NAMES=(
  allCodingStandardsTools
  allLintingTools
  allStaticAnalysisTools
  allTestingTools
  rector
  phpCsFixer
  psr4Validate
  composerChecks
  packageType
  configTemplateIgnoreList
  infectionConfigSourceDirs
  phpunitConfigVersion
  githubActionsPhpVersion
  phpStrictTypes
  phpLint
  composerRequireChecker
  markdownLinks
  branchNamePolicy
  phpstanIgnoreJustification
  phpstan
  phpArkitect
  sensitiveParameterUsage
  phpunit
  infection
  phploc
  uniterate
)

# Accepted `-t <token>` inputs per tool (space-separated). The canonical name is
# accepted ONLY when it also appears here (a canonical like psr4Validate is
# intentionally NOT a valid -t input).
declare -A QA_TOOL_ALIASES=(
  [allCodingStandardsTools]="allCS"
  [allLintingTools]="allLints"
  [allStaticAnalysisTools]="allStatic"
  [allTestingTools]="allTests"
  [rector]="r rector"
  [phpCsFixer]="f fixer csfixer"
  [psr4Validate]="psr psr4"
  [composerChecks]="com composer"
  [packageType]="pt packagetype packageType"
  [configTemplateIgnoreList]="cti configTemplateIgnoreList"
  [infectionConfigSourceDirs]="icsd infectionConfigSourceDirs"
  [phpunitConfigVersion]="pcv phpunitConfigVersion"
  [githubActionsPhpVersion]="gapv githubActionsPhpVersion"
  [phpStrictTypes]="st stricttypes"
  [phpLint]="lint phplint"
  [composerRequireChecker]="cr"
  [markdownLinks]="ml markdown"
  [branchNamePolicy]="bnp branchNamePolicy"
  [phpstanIgnoreJustification]="pij phpstanIgnoreJustification"
  [phpstan]="stan phpstan"
  [phpArkitect]="arch arkitect phparkitect"
  [sensitiveParameterUsage]="spu sensitiveparameter sensitiveParameterUsage"
  [phpunit]="unit phpunit"
  [infection]="infect infection"
  [phploc]="l loc"
  [uniterate]="uniterate"
)

# The value `singleToolToRun` is set to when a token for this tool is selected.
# Defaults to the tool's own name; only pseudo-tools (uniterate) differ.
declare -A QA_TOOL_TARGET=(
  [uniterate]="phpunit"
)

# Extra assignment applied (in the current shell, NOT via eval) when a token for
# this tool is selected. Format: "VAR=VALUE".
declare -A QA_TOOL_ONSELECT=(
  [uniterate]="phpUnitIterativeMode=1"
)

# Path-support classification, applied to the tool's name AND all its aliases.
# Feeds tool_supports_paths in options.inc.bash.
declare -A QA_TOOL_PATHS=(
  [allCodingStandardsTools]=no
  [allLintingTools]=no
  [allStaticAnalysisTools]=no
  [allTestingTools]=no
  [rector]=yes
  [phpCsFixer]=yes
  [psr4Validate]=no
  [composerChecks]=no
  [packageType]=no
  [configTemplateIgnoreList]=no
  [infectionConfigSourceDirs]=no
  [phpunitConfigVersion]=no
  [githubActionsPhpVersion]=no
  [phpStrictTypes]=yes
  [phpLint]=yes
  [composerRequireChecker]=no
  [markdownLinks]=no
  [branchNamePolicy]=no
  [phpstanIgnoreJustification]=no
  [phpstan]=yes
  [phpArkitect]=no
  [sensitiveParameterUsage]=no
  [phpunit]=yes
  [infection]=no
  [phploc]=yes
  [uniterate]=no
)

# Phase membership. Only phased leaf tools appear; meta/pseudo tools have none.
declare -A QA_TOOL_PHASE=(
  [rector]=codingStandards
  [phpCsFixer]=codingStandards
  [psr4Validate]=linting
  [composerChecks]=linting
  [packageType]=linting
  [configTemplateIgnoreList]=linting
  [infectionConfigSourceDirs]=linting
  [phpunitConfigVersion]=linting
  [githubActionsPhpVersion]=linting
  [phpStrictTypes]=linting
  [phpLint]=linting
  [composerRequireChecker]=linting
  [markdownLinks]=linting
  [branchNamePolicy]=staticAnalysis
  [phpstanIgnoreJustification]=staticAnalysis
  [phpstan]=staticAnalysis
  [phpArkitect]=staticAnalysis
  [sensitiveParameterUsage]=staticAnalysis
  [phpunit]=testing
  [infection]=testing
)

# Optional gate controlling whether a phased tool runs, given runtime flags:
#   notQuick   run unless phpqaQuickTests=1
#   infection  run unless phpqaQuickTests=1, AND only when useInfection=1
declare -A QA_TOOL_GATE=(
  [phpstan]=notQuick
  [phpunit]=notQuick
  [infection]=infection
)

# Banner label printed by qaRunPhase before a tool runs.
declare -A QA_TOOL_BANNER=(
  [rector]="Running Rector"
  [phpCsFixer]="Running PHP-CS-Fixer"
  [psr4Validate]="Validating PSR-4 Roots"
  [composerChecks]="Checking for Composer Issues"
  [packageType]="Checking Package Type Is Declared"
  [configTemplateIgnoreList]="Auditing Config Template Ignore-List Coverage"
  [infectionConfigSourceDirs]="Checking Infection Config Source Directories Exist"
  [phpunitConfigVersion]="Checking phpunit.xml Version Pins Match Installed PHPUnit"
  [githubActionsPhpVersion]="Checking GitHub Actions Workflows Can Select The Required PHP"
  [phpStrictTypes]="Setting Strict Types If It's Missing"
  [phpLint]="Running PHP Lint"
  [composerRequireChecker]="Running Composer Require Checker"
  [markdownLinks]="Running Markdown Links Checker"
  [branchNamePolicy]="Checking Branch Name Policy"
  [phpstanIgnoreJustification]="Checking PHPStan ignoreErrors Justifications"
  [phpstan]="Running PHPStan"
  [phpArkitect]="Running PHPArkitect (architecture rules)"
  [sensitiveParameterUsage]="Checking SensitiveParameter Usage"
  [phpunit]="Running PHPUnit Tests"
  [infection]="Running Infection (mutation testing)"
)

# Usage listing: "DISPLAY::DESCRIPTION". DISPLAY groups the accepted tokens with
# "|"; the "::" separator avoids ambiguity with the "|" inside DISPLAY.
# shellcheck disable=SC2034 # consumed by includes/options.inc.bash's usage() function
declare -A QA_TOOL_USAGE=(
  [allCodingStandardsTools]="allCS::all coding standards tools"
  [allLintingTools]="allLints::all linting tools"
  [allStaticAnalysisTools]="allStatic::all static analysis tools"
  [allTestingTools]="allTests::all testing tools"
  [rector]="r|rector::Rector"
  [phpCsFixer]="f|fixer|csfixer::PHP-CS-Fixer"
  [psr4Validate]="psr|psr4::psr4 validation"
  [composerChecks]="com|composer::composer validation"
  [packageType]="pt|packageType::assert composer.json declares an explicit package type (library/project/...)"
  [configTemplateIgnoreList]="cti|configTemplateIgnoreList::audit configDefaults/generic templates against psr4-validate-ignore-list.txt"
  [infectionConfigSourceDirs]="icsd|infectionConfigSourceDirs::assert infection.json's source.directories resolve to real directories"
  [phpunitConfigVersion]="pcv|phpunitConfigVersion::assert phpunit.xml's schema/SYMFONY_PHPUNIT_VERSION pins match the installed PHPUnit major"
  [githubActionsPhpVersion]="gapv|githubActionsPhpVersion::assert every PHP-version-detecting GitHub Actions workflow can select the PHP composer.json requires"
  [phpStrictTypes]="st|stricttypes::strict types validation"
  [phpLint]="lint|phplint::phplint"
  [composerRequireChecker]="cr::composer require checker"
  [markdownLinks]="ml|markdown::markdown validation"
  [branchNamePolicy]="bnp|branchNamePolicy::Branch naming policy (PR convention)"
  [phpstanIgnoreJustification]="pij|phpstanIgnoreJustification::assert every ignoreErrors entry in qaConfig/phpstan.neon carries a usable justification"
  [phpstan]="stan|phpstan::phpstan"
  [phpArkitect]="arch|arkitect|phparkitect::PHPArkitect architecture rules (on by default; useArkitect=0 to disable)"
  [sensitiveParameterUsage]="spu|sensitiveParameterUsage::assert #[\\SensitiveParameter] is used somewhere in src/"
  [phpunit]="unit|phpunit::phpunit"
  [infection]="infect|infection::infection"
  [phploc]="l|loc::lines of code and other stats"
  [uniterate]="uniterate::phpunit iterative mode - prioritise broken tests and fail on error"
)

###############################################################################
# Resolve the global singleToolToRun (a raw -t token) to its canonical target,
# applying any ONSELECT assignment in the CURRENT shell. Returns 1 (leaving
# singleToolToRun unchanged) when the token matches no registered tool, so the
# caller can print its own error + usage.
###############################################################################
function qaResolveSingleTool() {
  local IFS=$' \t\n'
  local token="$singleToolToRun" name alias onSelect
  for name in "${QA_TOOL_NAMES[@]}"; do
    for alias in ${QA_TOOL_ALIASES[$name]}; do
      if [[ "$token" == "$alias" ]]; then
        onSelect="${QA_TOOL_ONSELECT[$name]:-}"
        if [[ -n "$onSelect" ]]; then
          # Assign "VAR=VALUE" without eval (injection-safe: source is this file).
          printf -v "${onSelect%%=*}" '%s' "${onSelect#*=}"
        fi
        singleToolToRun="${QA_TOOL_TARGET[$name]:-$name}"
        return 0
      fi
    done
  done
  return 1
}

###############################################################################
# (Re)build the global PATH_SUPPORTING_TOOLS / NON_PATH_SUPPORTING_TOOLS token
# arrays from the registry, so tool_supports_paths and its error listing keep
# working unchanged. Each tool contributes its name plus every alias.
###############################################################################
function qaBuildPathSupportArrays() {
  local IFS=$' \t\n'
  local name token tokens
  PATH_SUPPORTING_TOOLS=()
  NON_PATH_SUPPORTING_TOOLS=()
  for name in "${QA_TOOL_NAMES[@]}"; do
    tokens="${QA_TOOL_ALIASES[$name]}"
    [[ " $tokens " == *" $name "* ]] || tokens="$name $tokens"
    if [[ "yes" == "${QA_TOOL_PATHS[$name]}" ]]; then
      for token in $tokens; do PATH_SUPPORTING_TOOLS+=("$token"); done
    else
      for token in $tokens; do NON_PATH_SUPPORTING_TOOLS+=("$token"); done
    fi
  done
}

###############################################################################
# Echo the canonical tools belonging to a phase, in registry order, one per line.
###############################################################################
function qaToolsForPhase() {
  local phase="$1" name
  for name in "${QA_TOOL_NAMES[@]}"; do
    if [[ "${QA_TOOL_PHASE[$name]:-}" == "$phase" ]]; then
      echo "$name"
    fi
  done
}

###############################################################################
# Decide whether a phased tool should run given the current runtime flags.
###############################################################################
function qaToolGateAllows() {
  local tool="$1"
  local gate="${QA_TOOL_GATE[$tool]:-}"
  case "$gate" in
    "")        return 0 ;;
    notQuick)  [[ "${phpqaQuickTests:-0}" != "1" ]] ;;
    infection) [[ "${phpqaQuickTests:-0}" != "1" && "${useInfection:-0}" == "1" ]] ;;
    *)         return 0 ;;
  esac
}

###############################################################################
# Run every tool in a phase, in registry order, via runToolGuarded. The four
# all*Tools.inc.bash phase fragments are each a single call to this.
###############################################################################
function qaRunPhase() {
  local phase="$1" tool banner
  for tool in $(qaToolsForPhase "$phase"); do
    if qaToolGateAllows "$tool"; then
      banner="${QA_TOOL_BANNER[$tool]:-Running $tool}"
      echo "

$banner
------------------------------------------------------------
"
      runToolGuarded "$tool"
    else
      echo "
Skipping $tool (gate=${QA_TOOL_GATE[$tool]:-none}, phpqaQuickTests=${phpqaQuickTests:-0}, useInfection=${useInfection:-0})
"
    fi
  done
}

###############################################################################
# Introspection dumps (stable, tab-separated) — consumed by the characterisation
# test and useful for manual inspection. NOT used by the pipeline itself.
###############################################################################
function qaRegistryDumpAliasMap() {
  local IFS=$' \t\n'
  local name alias
  for name in "${QA_TOOL_NAMES[@]}"; do
    for alias in ${QA_TOOL_ALIASES[$name]}; do
      printf '%s\t%s\n' "$alias" "${QA_TOOL_TARGET[$name]:-$name}"
    done
  done
}

function qaRegistryDumpPathSupport() {
  local IFS=$' \t\n'
  local name token tokens
  for name in "${QA_TOOL_NAMES[@]}"; do
    tokens="${QA_TOOL_ALIASES[$name]}"
    [[ " $tokens " == *" $name "* ]] || tokens="$name $tokens"
    for token in $tokens; do
      printf '%s\t%s\n' "$token" "${QA_TOOL_PATHS[$name]}"
    done
  done
}

function qaRegistryDumpPhaseOrder() {
  local phase name
  for phase in codingStandards linting staticAnalysis testing; do
    while IFS= read -r name; do
      [[ -n "$name" ]] && printf '%s\t%s\n' "$phase" "$name"
    done < <(qaToolsForPhase "$phase")
  done
}
