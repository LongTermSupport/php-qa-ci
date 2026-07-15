#!/usr/bin/env bash
###################################################################
#
# QA Locking System - Timing Module
#
# Tracks command execution times and calculates ETAs based on
# historical data.
#
# This module is part of the php-qa-ci locking system.
#
###################################################################

# Global variables
TIMING_DATA_FILE=""
# shellcheck disable=SC2034 # assigned here, read/consumed by includes/generic/lock.inc.bash
QA_ETA_SECONDS=""

###################################################################
# normalizePathForKey
#
# Normalizes a path for use in command keys to ensure consistency
# across different invocations.
#
# Handles:
# - Leading ./ removal
# - Trailing slash removal
# - Multiple slash collapse
#
# Arguments:
#   $1 - Path to normalize
#
# Returns:
#   Normalized path string
###################################################################
normalizePathForKey() {
    local path="$1"

    # Remove leading ./
    path="${path#./}"

    # Remove trailing slashes
    path="${path%/}"

    # Collapse multiple slashes
    path="$(echo "$path" | sed 's|//*|/|g')"

    echo "$path"
}

###################################################################
# getPathType
#
# Determines if a path is a file, folder, or full-suite run.
#
# Arguments:
#   $1 - Path to check (empty for full-suite)
#
# Returns:
#   "file", "folder", or "full-suite"
###################################################################
getPathType() {
    local path="$1"

    if [[ -z "$path" ]]; then
        echo "full-suite"
        return 0
    fi

    # Check if path ends with file extension
    if [[ "$path" =~ \.[a-zA-Z0-9]+$ ]]; then
        echo "file"
        return 0
    fi

    echo "folder"
}

###################################################################
# getCommandKey
#
# Generates a unique command key for timing data lookup.
#
# Format: {tool}:{pathType}:{normalizedPath}
#
# Arguments:
#   $1 - Tool name (e.g., "phpstan", "unit")
#   $2 - Path (empty for full-suite)
#
# Returns:
#   Command key string
###################################################################
getCommandKey() {
    local tool="$1"
    local path="$2"

    local pathType
    pathType=$(getPathType "$path")

    if [[ "$pathType" == "full-suite" ]]; then
        echo "${tool}:full-suite"
        return 0
    fi

    # Normalize path for consistency
    local normalizedPath
    normalizedPath=$(normalizePathForKey "$path")

    if [[ "$pathType" == "folder" ]]; then
        echo "${tool}:folder:${normalizedPath}"
    else
        # File paths don't get tracked, but return key anyway
        echo "${tool}:file:${normalizedPath}"
    fi
}

###################################################################
# initTimingData
#
# Initializes the timing data file if it doesn't exist.
#
# Arguments:
#   $1 - Path to timing data file
###################################################################
initTimingData() {
    TIMING_DATA_FILE="$1"

    if [[ ! -f "$TIMING_DATA_FILE" ]]; then
        local now
        now=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
        cat > "$TIMING_DATA_FILE" << EOF
{
  "metadata": {
    "version": "1.0",
    "last_updated": "$now"
  },
  "commands": {}
}
EOF
    fi
}

###################################################################
# calculateEta
#
# Calculates estimated duration for a command based on historical
# timing data.
#
# Arguments:
#   $1 - Tool name
#   $2 - Path (empty for full-suite)
#
# Returns:
#   Estimated duration in seconds
###################################################################
calculateEta() {
    local tool="$1"
    local path="$2"

    local pathType
    pathType=$(getPathType "$path")

    # File paths always get 30-second hardcoded ETA
    if [[ "$pathType" == "file" ]]; then
        echo 30
        return 0
    fi

    # Normalize path if it's a folder
    if [[ -n "$path" && "$pathType" == "folder" ]]; then
        path="$(normalizePathForKey "$path")"
    fi

    # Build command key
    local commandKey
    commandKey=$(getCommandKey "$tool" "$path")

    # Look up average duration in timing data
    local avgSeconds
    avgSeconds=$(jq -r ".commands[\"${commandKey}\"].average_seconds // empty" "$TIMING_DATA_FILE" 2>/dev/null)

    if [[ -n "$avgSeconds" && "$avgSeconds" != "null" && "$avgSeconds" != "empty" ]]; then
        # Add 20% buffer to average for safety
        local buffered
        buffered=$(echo "$avgSeconds * 1.2" | bc 2>/dev/null || echo "$avgSeconds")
        # Round down to integer
        echo "${buffered%.*}"
    else
        # No historical data - default to 15 minutes (900 seconds)
        echo 900
    fi
}

###################################################################
# recordCommandTiming
#
# Records successful execution time for a command.
#
# Arguments:
#   $1 - Tool name
#   $2 - Path (empty for full-suite)
#   $3 - Duration in seconds
###################################################################
recordCommandTiming() {
    local tool="$1"
    local path="$2"
    local durationSeconds="$3"

    local pathType
    pathType=$(getPathType "$path")

    # Don't record timing data for file-specific runs
    if [[ "$pathType" == "file" ]]; then
        return 0
    fi

    # Normalize path if it's a folder
    if [[ -n "$path" && "$pathType" == "folder" ]]; then
        path="$(normalizePathForKey "$path")"
    fi

    local commandKey
    commandKey=$(getCommandKey "$tool" "$path")
    local now
    now=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

    # Use temp file for atomic update
    local tmpFile="${TIMING_DATA_FILE}.tmp.$$"

    # Read current data, add new execution, recalculate average
    jq --arg key "$commandKey" \
       --arg timestamp "$now" \
       --arg duration "$durationSeconds" \
       --arg metaNow "$now" \
       '
       .metadata.last_updated = $metaNow |
       if .commands[$key] then
           .commands[$key].executions += [{"timestamp": $timestamp, "duration_seconds": ($duration | tonumber)}] |
           .commands[$key].executions = (.commands[$key].executions | sort_by(.timestamp) | reverse | .[0:10]) |
           .commands[$key].sample_count = (.commands[$key].executions | length) |
           .commands[$key].average_seconds = (
               .commands[$key].executions |
               map(.duration_seconds) |
               add / length
           )
       else
           .commands[$key] = {
               "executions": [{"timestamp": $timestamp, "duration_seconds": ($duration | tonumber)}],
               "average_seconds": ($duration | tonumber),
               "sample_count": 1
           }
       end
       ' "$TIMING_DATA_FILE" > "$tmpFile" 2>/dev/null

    # Atomic replace
    if [[ -f "$tmpFile" ]]; then
        mv "$tmpFile" "$TIMING_DATA_FILE"
    fi
}

###################################################################
# formatDuration
#
# Formats seconds into human-readable duration.
#
# Arguments:
#   $1 - Duration in seconds
#
# Returns:
#   Formatted duration string (e.g., "2 minutes 30 seconds")
###################################################################
formatDuration() {
    local seconds="$1"

    if [[ $seconds -lt 60 ]]; then
        echo "${seconds} seconds"
        return 0
    fi

    local minutes=$((seconds / 60))
    local remainingSeconds=$((seconds % 60))

    if [[ $minutes -lt 60 ]]; then
        if [[ $remainingSeconds -eq 0 ]]; then
            echo "${minutes} minutes"
        else
            echo "${minutes} minutes ${remainingSeconds} seconds"
        fi
        return 0
    fi

    local hours=$((minutes / 60))
    local remainingMinutes=$((minutes % 60))

    if [[ $remainingMinutes -eq 0 ]]; then
        echo "${hours} hours"
    else
        echo "${hours} hours ${remainingMinutes} minutes"
    fi
}

###################################################################
# formatTimestamp
#
# Formats ISO 8601 timestamp for display.
#
# Arguments:
#   $1 - ISO 8601 timestamp
#
# Returns:
#   Formatted timestamp string (e.g., "14:30:00")
###################################################################
formatTimestamp() {
    local timestamp="$1"

    # Convert to local time and format as HH:MM:SS
    date -d "$timestamp" +"%H:%M:%S" 2>/dev/null || echo "$timestamp"
}
