#!/usr/bin/env bash
readonly DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )";
cd $DIR;
set -e
set -u
set -o pipefail
standardIFS="$IFS"
IFS=$'\n\t'

# Tool registry — SINGLE SOURCE OF TRUTH (M-011). The -t alias resolution, the
# path-support gate arrays and the usage tool list are all DERIVED from it below.
# $DIR is this file's own (absolute) directory, resolved at the top of this
# script, so the registry resolves regardless of the caller's cwd.
source "$DIR/generic/toolRegistry.inc.bash"

singleToolToRun=
specifiedPath=
useJsonOutput=0

# Pre-process long options (getopts only handles short options)
processedArgs=()
for arg in "$@"; do
    case "$arg" in
        --json) useJsonOutput=1 ;;
        *) processedArgs+=("$arg") ;;
    esac
done
set -- "${processedArgs[@]}"

function usage {
    echo "Usage:"
    echo "$binDir/qa [-t tool to run ] [ -p path to scan ] [ --json ]"
    echo ""
    echo "Defaults to using all tools and scanning whole project based on platform"
    echo ""
    echo " - use -h to see this help"
    echo ""
    echo " - use -p to specify a specific path to scan"
    echo ""
    echo " - use --json to get structured JSON output (supported tools only)"
    echo ""
    echo " - use -t to specify a single tool:"
    # Tool list DERIVED from the registry (QA_TOOL_USAGE), so it can never drift
    # from the actual -t aliases the way the old hand-maintained list did.
    local _uName _uUsage _uDisplay _uDesc
    for _uName in "${QA_TOOL_NAMES[@]}"; do
        _uUsage="${QA_TOOL_USAGE[$_uName]:-}"
        [[ -z "$_uUsage" ]] && continue
        _uDisplay="${_uUsage%%::*}"
        _uDesc="${_uUsage#*::}"
        printf "     %-26s %s\n" "$_uDisplay" "$_uDesc"
    done
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

# Path-support gate tokens, DERIVED from the registry (QA_TOOL_PATHS). This
# replaces the two hand-maintained arrays that had already drifted from the
# tool implementations (the "VERIFIED by reading tool files" claim was false —
# psr4Validate was listed path-supporting while the fragment was empty). The
# classification now lives in ONE place; qaBuildPathSupportArrays fills these.
PATH_SUPPORTING_TOOLS=()
NON_PATH_SUPPORTING_TOOLS=()
qaBuildPathSupportArrays

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
    # Resolve the -t token to its canonical tool via the registry (SSoT). This
    # replaces the hand-maintained alias `case`; qaResolveSingleTool also applies
    # any per-tool ONSELECT side effect (e.g. uniterate sets phpUnitIterativeMode=1).
    if ! qaResolveSingleTool; then
        printf "\nERROR:\nInvalid tool: $singleToolToRun\n\n" >&2
        usage
    fi
    echo "Running Single Tool: $singleToolToRun"
fi

# Validate JSON output compatibility
if [[ "1" == "$useJsonOutput" ]]; then
    if [[ -z "$singleToolToRun" ]]; then
        printf "\nERROR: --json requires a single tool (-t)\n" >&2
        printf "  Example: vendor/bin/qa -t phpstan --json\n\n" >&2
        exit 1
    fi

    case "$singleToolToRun" in
        phpstan) ;; # Native --error-format=json
        *)
            printf "\nERROR: --json is not yet supported for '%s'\n\n" "$singleToolToRun" >&2
            printf "Tools with --json support:\n" >&2
            printf "  phpstan    (native --error-format=json)\n\n" >&2
            printf "Run without --json, or use a supported tool.\n\n" >&2
            exit 1
            ;;
    esac
fi

if [[ "" != "$specifiedPath" ]]
then
    echo "Scanning Specified Path: $specifiedPath"
fi
