#!/usr/bin/env bash
readonly DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )";
cd $DIR;
set -e
set -u
set -o pipefail
standardIFS="$IFS"
IFS=$'\n\t'

singleToolToRun=
specifiedPath=

function usage {
    echo "Usage:"
    echo "$binDir/qa [-t tool to run ] [ -p path to scan ]"
    echo ""
    echo "Defaults to using all tools and scanning whole project based on platform"
    echo ""
    echo " - use -h to see this help"
    echo ""
    echo " - use -p to specify a specific path to scan"
    echo ""
    echo " - use -t to specify a single tool:"
    echo "     allLints                   all linting tools"
    echo "     allStatic                  all static analysis tools"
    echo "     allTests                   all testing tools"
    echo "     allCS                      all coding standards tools"
    echo "     psr|psr4                   psr4 validation"
    echo "     com|composer               composer validation"
    echo "     st|stricttypes             strict types validation"
    echo "     lint|phplint               phplint"
    echo "     stan|phpstan               phpstan"
    echo "     ann|phpunitAnnotations     phpunitAnnotations"
    echo "     unit|phpunit               phpunit"
    echo "     uniterate                  phpunit iterative mode - prioritise broken tests and fail on error"
    echo "     infect|infection           infection"
    echo "     cr                         composer require checker"
    echo "     md|messdetector            php mess detector"
    echo "     ml|markdown                markdown validation"
    echo "     bf|phpbf                   php beautifier and fixer"
    echo "     cs|phpcs                   php code sniffer"
    echo "     l|loc                      lines of code and other stats"
    echo "     f|fixer|csfixer            PHP-CS-Fixer"
    echo "     r|rector                   Rector"
    exit 1
}

# Parse options first
while getopts ":t:p:h" opt; do
    case "$opt" in
        t) singleToolToRun=$OPTARG ;;
        p) specifiedPath=$OPTARG ;;
        h) usage ;;
        \?)
            printf "\nERROR:\nInvalid option: -$OPTARG\n\n" >&2
            usage
        ;;
        :)
            printf "\nERROR\nOption -$OPTARG requires an argument\n\n" >&2
            usage
        ;;
    esac
done

# Shift processed options
shift $((OPTIND-1))

# Define tools that support path-specific execution
# These tools use ${pathsToCheck[@]} or similar path variables in their implementation
# VERIFIED by reading tool implementation files
PATH_SUPPORTING_TOOLS=(
    "phpstan" "stan"                    # ✓ Uses ${pathsToCheck[@]}
    "phpCsFixer" "fixer" "f" "csfixer"  # ✓ Uses ${pathsToCheck[@]}  
    "rector" "r"                        # ✓ Uses ${pathsToCheck[@]}
    "phpLint" "lint" "phplint"          # ✓ Uses ${pathsToCheck[@]}
    "psr4Validate" "psr" "psr4"         # ✓ Uses ${pathsToCheck[@]}
    "phpStrictTypes" "stricttypes" "st" # ✓ Uses ${pathsToCheck[@]}
    "phploc" "loc" "l"                  # ✓ Uses ${pathsToCheck[@]}
)

# Define tools that do NOT support path-specific execution
# These tools operate on the entire project regardless of path specification
# VERIFIED by reading tool implementation files
NON_PATH_SUPPORTING_TOOLS=(
    "composerChecks" "composer" "com"            # ❌ Project-wide operations only
    "infection" "infect"                         # ❌ Ignores pathsToCheck completely
    "composerRequireChecker" "cr"                # ❌ Analyzes entire project
    "markdownLinks" "markdown" "ml"              # ❌ Hardcoded to specific files
    "messDetector" "md"                          # ❌ Need to verify implementation
    "beautifierFixer" "bf" "phpbf"               # ❌ Need to verify implementation  
    "codeSniffer" "cs" "phpcs"                   # ❌ Need to verify implementation
    "phpunitAnnotations" "ann"                   # ❌ Need to verify implementation
    "phpunit" "unit" "uniterate"                 # ❌ Uses config file, not pathsToCheck
    "allLintingTools" "allLints"                 # ❌ Aggregate - runs multiple tools
    "allStaticAnalysisTools" "allStatic"         # ❌ Aggregate - runs multiple tools
    "allTestingTools" "allTests"                 # ❌ Aggregate - runs multiple tools
    "allCodingStandardsTools" "allCS"            # ❌ Aggregate - runs multiple tools
)

# Function to check if a tool supports path specification
tool_supports_paths() {
    local tool="$1"
    for supported_tool in "${PATH_SUPPORTING_TOOLS[@]}"; do
        if [[ "$tool" == "$supported_tool" ]]; then
            return 0
        fi
    done
    return 1
}

# Smart path handling: if we have remaining arguments and no -p specified
if [[ -z "$specifiedPath" && $# -gt 0 ]]; then
    # Check for unsupported flags
    for arg in "$@"; do
        if [[ "$arg" == --* ]] || [[ "$arg" == -* ]]; then
            printf "\nERROR:\nUnsupported argument: $arg\n\n" >&2
            printf "The QA pipeline only supports path specification.\n" >&2
            printf "Use: $binDir/qa -t toolname -p path/to/check\n" >&2
            printf "Or:  $binDir/qa -t toolname path/to/check (automatic -p)\n\n" >&2
            exit 1
        fi
    done
    
    # Assume remaining arguments are paths
    if [[ $# -eq 1 ]]; then
        specifiedPath="$1"
        printf "\nAuto-detected path: $specifiedPath\n"
    elif [[ $# -gt 1 ]]; then
        printf "\nERROR:\nMultiple paths not supported: $*\n\n" >&2
        printf "Specify a single path: $binDir/qa -t toolname path/to/check\n\n" >&2
        exit 1
    fi
fi

# Check if a tool that doesn't support paths is being run with a path
if [[ -n "$specifiedPath" && -n "$singleToolToRun" ]]; then
    if ! tool_supports_paths "$singleToolToRun"; then
        printf "\nERROR:\nTool '$singleToolToRun' does not support path-specific execution.\n\n" >&2
        printf "This tool operates on the entire project regardless of path specification.\n" >&2
        printf "If you expected a quick test run, this would trigger a full project scan.\n\n" >&2
        printf "Tools that support path specification:\n" >&2
        printf "  %s\n" "${PATH_SUPPORTING_TOOLS[@]}" >&2
        printf "\nTo run this tool on the entire project: $binDir/qa -t $singleToolToRun\n\n" >&2
        exit 1
    fi
fi

if [[ "" != "$singleToolToRun" ]]
then
    case "$singleToolToRun" in
        allLints                    ) singleToolToRun="allLintingTools";;
        allStatic                   ) singleToolToRun="allStaticAnalysisTools";;
        allTests                    ) singleToolToRun="allTestingTools";;
        allCS                       ) singleToolToRun="allCodingStandardsTools";;
        psr | psr4                  ) singleToolToRun="psr4Validate";;
        com | composer              ) singleToolToRun="composerChecks";;
        st | stricttypes            ) singleToolToRun="phpStrictTypes";;
        lint | phplint              ) singleToolToRun="phpLint";;
        stan | phpstan              ) singleToolToRun="phpstan";;
        ann | phpunitAnnotations    ) singleToolToRun="phpunitAnnotations";;
        unit | phpunit              ) singleToolToRun="phpunit";;
        uniterate                   ) singleToolToRun="phpunit"; phpUnitIterativeMode=1;;
        infect | infection          ) singleToolToRun="infection";;
        cr                          ) singleToolToRun="composerRequireChecker";;
        md | messdetector           ) singleToolToRun="messDetector";;
        ml | markdown               ) singleToolToRun="markdownLinks";;
        bf | phpbf                  ) singleToolToRun="beautifierFixer";;
        cs | phpcs                  ) singleToolToRun="codeSniffer";;
        l | loc                     ) singleToolToRun="phploc";;
        f | fixer | csfixer         ) singleToolToRun="phpCsFixer";;
        r | rector                  ) singleToolToRun="rector";;
        * )
            printf "\nERROR:\nInvalid tool: $singleToolToRun\n\n" >&2
            usage
        ;;
    esac
    echo "Running Single Tool: $singleToolToRun"
fi

if [[ "" != "$specifiedPath" ]]
then
    echo "Scanning Specified Path: $specifiedPath"
fi
