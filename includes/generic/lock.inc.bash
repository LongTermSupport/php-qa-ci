#!/usr/bin/env bash
###################################################################
#
# QA Locking System - Lock Management Module
#
# Provides file-level locking to prevent concurrent QA tool
# execution. Features:
# - Container-agnostic time-based stale lock detection
# - Heartbeat mechanism for activity tracking
# - Per-tool execution tracking
# - Master log file integration
#
# This module is part of the php-qa-ci locking system.
#
###################################################################

# Global variables
QA_LOCK_DIR=""
QA_LOCK_FILE=""
QA_LOCK_ACQUIRED=0
QA_LOCK_START_TIME=0
QA_HEARTBEAT_PID=""
QA_MASTER_LOG=""

###################################################################
# checkGnuDate
#
# Verifies that GNU date is available (Linux only).
# Fails fast if BSD date is detected.
###################################################################
checkGnuDate() {
    if ! date -d "2025-01-01" +%s >/dev/null 2>&1; then
        echo ""
        echo "ERROR: QA locking system requires GNU date (Linux only)"
        echo "This system appears to have BSD date."
        echo ""
        echo "The locking system is designed for Linux development environments"
        echo "and is not compatible with BSD/macOS date command syntax."
        echo ""
        exit 1
    fi
}

###################################################################
# initLockSystem
#
# Initializes the locking system:
# - Checks for GNU date
# - Creates lock directory
# - Creates .gitignore
# - Initializes timing data
# - Sets up master log file
#
# Arguments:
#   $1 - Project root directory
###################################################################
initLockSystem() {
    local projectRoot="$1"

    # Check for GNU date first
    checkGnuDate

    # Set up lock directory
    QA_LOCK_DIR="$projectRoot/qaConfig/.qa-lock"
    QA_LOCK_FILE="$QA_LOCK_DIR/qa-running.lock"

    # Create lock directory if needed
    mkdir -p "$QA_LOCK_DIR"

    # Create .gitignore if it doesn't exist
    if [[ ! -f "$QA_LOCK_DIR/.gitignore" ]]; then
        cat > "$QA_LOCK_DIR/.gitignore" << 'EOF'
# QA Lock System - Auto-generated, do not edit manually
# Lock files should never be tracked
*.lock
.lock.*

# Timing data SHOULD be tracked (negation)
!timing-data.json
EOF
    fi

    # Initialize timing data file
    TIMING_DATA_FILE="$QA_LOCK_DIR/timing-data.json"
    initTimingData "$TIMING_DATA_FILE"

    # Set up master log file
    setupMasterLog
}

###################################################################
# setupMasterLog
#
# Sets up master log file with automatic rotation (keep last 10).
# Redirects all output through tee for complete audit trail.
###################################################################
setupMasterLog() {
    # Create master log file with timestamp
    QA_MASTER_LOG="$varDir/qa-run.$(date +%Y%m%d-%H%M%S).log"
    mkdir -p "$varDir"

    # Prune old master logs - keep last 10
    mapfile -t oldLogs < <(ls -1t "$varDir"/qa-run.*.log 2>/dev/null || true)
    if [[ ${#oldLogs[@]} -ge 10 ]]; then
        for ((i=10; i<${#oldLogs[@]}; i++)); do
            rm -f "${oldLogs[$i]}" 2>/dev/null || true
            echo "[QA Lock] Pruned old master log: ${oldLogs[$i]}"
        done
    fi

    # Set up master log redirection using exec and tee
    exec > >(tee -a "$QA_MASTER_LOG") 2>&1

    # Export for use in lock file
    export QA_MASTER_LOG
}

###################################################################
# isStaleLock
#
# Detects if a lock file is stale using time-based detection.
# Does NOT rely on PIDs (container-agnostic).
#
# Stale conditions:
# 1. Activity timeout: last_activity + 10 minutes < now
# 2. ETA timeout: eta_completion + 10 minutes < now
#
# Arguments:
#   $1 - Lock file path
#
# Returns:
#   0 if stale, 1 if not stale
###################################################################
isStaleLock() {
    local lockFile="$1"

    # Check if lock file exists
    [[ -f "$lockFile" ]] || return 1  # Not stale, doesn't exist

    # Read lock file
    local lastActivity=$(jq -r '.last_activity' "$lockFile" 2>/dev/null || echo "")
    local etaCompletion=$(jq -r '.eta_completion' "$lockFile" 2>/dev/null || echo "")
    local now=$(date +%s)

    # Check activity timeout (10 minutes since last activity)
    if [[ -n "$lastActivity" && "$lastActivity" != "null" ]]; then
        local activityTimestamp=$(date -d "$lastActivity" +%s 2>/dev/null || echo 0)
        local activityTimeout=600  # 10 minutes in seconds

        if [[ $activityTimestamp -gt 0 ]] && [[ $((now - activityTimeout)) -gt $activityTimestamp ]]; then
            echo "STALE_REASON=activity_timeout" >&2
            return 0  # Stale - no activity for 10+ minutes
        fi
    fi

    # Check ETA timeout (10 minutes past expected completion)
    if [[ -n "$etaCompletion" && "$etaCompletion" != "null" ]]; then
        local etaTimestamp=$(date -d "$etaCompletion" +%s 2>/dev/null || echo 0)
        local etaGracePeriod=600  # 10 minutes grace period

        if [[ $etaTimestamp -gt 0 ]] && [[ $((now - etaGracePeriod)) -gt $etaTimestamp ]]; then
            echo "STALE_REASON=eta_timeout" >&2
            return 0  # Stale - way past expected completion
        fi
    fi

    return 1  # Not stale
}

###################################################################
# displayLockStatus
#
# Displays information about an existing lock file.
#
# Arguments:
#   $1 - Lock file path
###################################################################
displayLockStatus() {
    local lockFile="$1"

    echo ""
    echo "[QA Lock] Cannot start - another QA process is running"

    local command=$(jq -r '.command' "$lockFile" 2>/dev/null || echo "unknown")
    local started=$(jq -r '.timestamp' "$lockFile" 2>/dev/null || echo "unknown")
    local user=$(jq -r '.user' "$lockFile" 2>/dev/null || echo "unknown")
    local hostname=$(jq -r '.hostname' "$lockFile" 2>/dev/null || echo "unknown")
    local etaCompletion=$(jq -r '.eta_completion' "$lockFile" 2>/dev/null || echo "unknown")
    local currentTool=$(jq -r '.current_tool' "$lockFile" 2>/dev/null || echo "unknown")

    echo "[QA Lock] Process: $command"
    echo "[QA Lock] Started: $(formatTimestamp "$started") by $user on $hostname"

    if [[ "$currentTool" != "null" && "$currentTool" != "unknown" ]]; then
        echo "[QA Lock] Currently running: $currentTool"
    fi

    if [[ "$etaCompletion" != "null" && "$etaCompletion" != "unknown" ]]; then
        local etaTime=$(formatTimestamp "$etaCompletion")
        local now=$(date +%s)
        local etaTimestamp=$(date -d "$etaCompletion" +%s 2>/dev/null || echo 0)

        if [[ $etaTimestamp -gt 0 ]]; then
            local remaining=$((etaTimestamp - now))
            if [[ $remaining -gt 0 ]]; then
                echo "[QA Lock] Expected completion: $etaTime ($(formatDuration $remaining) remaining)"
            else
                echo "[QA Lock] Expected completion: $etaTime (overdue)"
            fi

            local staleTime=$((etaTimestamp + 600))  # ETA + 10 min grace
            local staleTimestamp=$(date -d "@$staleTime" +"%H:%M:%S" 2>/dev/null || echo "unknown")
            echo "[QA Lock] If this lock is stale, it will auto-clear after $staleTimestamp"
        fi
    fi

    echo ""
}

###################################################################
# removeStaleLock
#
# Removes a stale lock file and displays reason.
#
# Arguments:
#   $1 - Lock file path
#   $2 - Stale reason (from stderr of isStaleLock)
###################################################################
removeStaleLock() {
    local lockFile="$1"
    local reason="$2"

    echo ""
    echo "[QA Lock] Detected stale lock from previous process"

    local started=$(jq -r '.timestamp' "$lockFile" 2>/dev/null || echo "unknown")
    local user=$(jq -r '.user' "$lockFile" 2>/dev/null || echo "unknown")
    local hostname=$(jq -r '.hostname' "$lockFile" 2>/dev/null || echo "unknown")
    local lastActivity=$(jq -r '.last_activity' "$lockFile" 2>/dev/null || echo "unknown")
    local etaCompletion=$(jq -r '.eta_completion' "$lockFile" 2>/dev/null || echo "unknown")

    echo "[QA Lock] Started: $(formatTimestamp "$started") by $user on $hostname"

    if [[ "$reason" == "activity_timeout" && "$lastActivity" != "null" && "$lastActivity" != "unknown" ]]; then
        local lastTime=$(formatTimestamp "$lastActivity")
        local now=$(date +%s)
        local lastTimestamp=$(date -d "$lastActivity" +%s 2>/dev/null || echo 0)
        if [[ $lastTimestamp -gt 0 ]]; then
            local elapsed=$((now - lastTimestamp))
            echo "[QA Lock] Last activity: $lastTime ($(formatDuration $elapsed) ago)"
        fi
        echo "[QA Lock] Reason: No activity for 10+ minutes (process likely died)"
    elif [[ "$reason" == "eta_timeout" && "$etaCompletion" != "null" && "$etaCompletion" != "unknown" ]]; then
        local etaTime=$(formatTimestamp "$etaCompletion")
        local now=$(date +%s)
        local etaTimestamp=$(date -d "$etaCompletion" +%s 2>/dev/null || echo 0)
        if [[ $etaTimestamp -gt 0 ]]; then
            local overdue=$((now - etaTimestamp))
            echo "[QA Lock] Expected completion: $etaTime ($(formatDuration $overdue) ago)"
        fi
        echo "[QA Lock] Reason: Way past expected completion time"
    fi

    echo "[QA Lock] Removing stale lock and proceeding..."
    rm -f "$lockFile"
    echo ""
}

###################################################################
# acquireLock
#
# Attempts to acquire the QA lock.
# Checks for existing locks and stale lock detection.
#
# Arguments:
#   $1 - Tool name (empty for full pipeline)
#   $2 - Path (empty for full suite)
#
# Returns:
#   0 on success, 1 on failure (lock held)
###################################################################
acquireLock() {
    local tool="$1"
    local path="$2"

    # Check if lock already exists
    if [[ -f "$QA_LOCK_FILE" ]]; then
        # Check if stale
        local staleReason=""
        if staleReason=$(isStaleLock "$QA_LOCK_FILE" 2>&1 >/dev/null); then
            # Extract reason from stderr
            staleReason="${staleReason#STALE_REASON=}"
            removeStaleLock "$QA_LOCK_FILE" "$staleReason"
        else
            # Lock is valid, display status and fail
            displayLockStatus "$QA_LOCK_FILE"
            return 1
        fi
    fi

    # Calculate ETA for this command
    QA_ETA_SECONDS=$(calculateEta "$tool" "$path")

    # Record start time
    QA_LOCK_START_TIME=$(date +%s)

    # Create lock file
    local now=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    local etaCompletionTimestamp=$((QA_LOCK_START_TIME + QA_ETA_SECONDS))
    local etaCompletion=$(date -u -d "@$etaCompletionTimestamp" +"%Y-%m-%dT%H:%M:%SZ")

    # Determine path type
    local pathType="full-suite"
    if [[ -n "$path" ]]; then
        pathType=$(getPathType "$path")
    fi

    # Get command from process command line
    local command="$(ps -p $$ -o args= 2>/dev/null || echo './bin/qa')"

    # Create lock file with all metadata
    local tmpFile="${QA_LOCK_FILE}.tmp.$$"
    cat > "$tmpFile" << EOF
{
  "pid": $$,
  "hostname": "$(hostname)",
  "timestamp": "$now",
  "last_activity": "$now",
  "command": "$command",
  "tool": "$tool",
  "path": "$path",
  "pathType": "$pathType",
  "eta_seconds": $QA_ETA_SECONDS,
  "eta_completion": "$etaCompletion",
  "user": "$(whoami)",
  "master_log_file": "$QA_MASTER_LOG",
  "current_tool": "",
  "tools": []
}
EOF

    # Atomic move
    mv "$tmpFile" "$QA_LOCK_FILE"

    # Mark as acquired
    QA_LOCK_ACQUIRED=1

    # Start heartbeat
    startHeartbeat "$QA_LOCK_FILE"

    # Display success message
    echo ""
    echo "[QA Lock] Acquired lock at $(formatTimestamp "$now")"
    echo "[QA Lock] Estimated completion: $(formatTimestamp "$etaCompletion") ($(formatDuration $QA_ETA_SECONDS))"
    echo ""

    return 0
}

###################################################################
# startHeartbeat
#
# Starts background process to update last_activity every 60 seconds.
#
# Arguments:
#   $1 - Lock file path
###################################################################
startHeartbeat() {
    local lockFile="$1"

    # Background process to update last_activity
    (
        while [[ -f "$lockFile" ]]; do
            sleep 60
            if [[ -f "$lockFile" ]]; then
                local now=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
                local tmpFile="${lockFile}.tmp.$$"
                jq --arg now "$now" '.last_activity = $now' "$lockFile" > "$tmpFile" 2>/dev/null
                if [[ -f "$tmpFile" ]]; then
                    mv "$tmpFile" "$lockFile" 2>/dev/null || true
                fi
            fi
        done
    ) &

    # Store background PID for cleanup
    QA_HEARTBEAT_PID=$!
}

###################################################################
# stopHeartbeat
#
# Stops the background heartbeat process.
###################################################################
stopHeartbeat() {
    if [[ -n "$QA_HEARTBEAT_PID" ]] && kill -0 "$QA_HEARTBEAT_PID" 2>/dev/null; then
        kill "$QA_HEARTBEAT_PID" 2>/dev/null || true
        wait "$QA_HEARTBEAT_PID" 2>/dev/null || true
    fi
}

###################################################################
# toolStart
#
# Records tool start in lock file.
#
# Arguments:
#   $1 - Tool name
###################################################################
toolStart() {
    local toolName="$1"

    [[ $QA_LOCK_ACQUIRED -eq 0 ]] && return 0

    local now=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    local tmpFile="${QA_LOCK_FILE}.tmp.$$"

    jq --arg tool "$toolName" \
       --arg started "$now" \
       '
       .current_tool = $tool |
       .tools += [{
           "name": $tool,
           "started": $started,
           "status": "running"
       }]
       ' "$QA_LOCK_FILE" > "$tmpFile" 2>/dev/null

    if [[ -f "$tmpFile" ]]; then
        mv "$tmpFile" "$QA_LOCK_FILE" 2>/dev/null || true
    fi
}

###################################################################
# toolComplete
#
# Records tool completion in lock file.
#
# Arguments:
#   $1 - Tool name
###################################################################
toolComplete() {
    local toolName="$1"

    [[ $QA_LOCK_ACQUIRED -eq 0 ]] && return 0

    local now=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    local tmpFile="${QA_LOCK_FILE}.tmp.$$"

    jq --arg tool "$toolName" \
       --arg completed "$now" \
       '
       .current_tool = "" |
       .tools = [
           .tools[] |
           if .name == $tool and .status == "running" then
               . + {
                   "completed": $completed,
                   "duration_seconds": ((now | strptime("%Y-%m-%dT%H:%M:%SZ") | mktime) - (.started | strptime("%Y-%m-%dT%H:%M:%SZ") | mktime)),
                   "status": "completed"
               }
           else
               .
           end
       ]
       ' "$QA_LOCK_FILE" > "$tmpFile" 2>/dev/null

    if [[ -f "$tmpFile" ]]; then
        mv "$tmpFile" "$QA_LOCK_FILE" 2>/dev/null || true
    fi
}

###################################################################
# toolFailed
#
# Records tool failure in lock file.
#
# Arguments:
#   $1 - Tool name
###################################################################
toolFailed() {
    local toolName="$1"

    [[ $QA_LOCK_ACQUIRED -eq 0 ]] && return 0

    local now=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    local tmpFile="${QA_LOCK_FILE}.tmp.$$"

    jq --arg tool "$toolName" \
       --arg failed "$now" \
       '
       .current_tool = "" |
       .tools = [
           .tools[] |
           if .name == $tool and .status == "running" then
               . + {
                   "completed": $failed,
                   "duration_seconds": ((now | strptime("%Y-%m-%dT%H:%M:%SZ") | mktime) - (.started | strptime("%Y-%m-%dT%H:%M:%SZ") | mktime)),
                   "status": "failed"
               }
           else
               .
           end
       ]
       ' "$QA_LOCK_FILE" > "$tmpFile" 2>/dev/null

    if [[ -f "$tmpFile" ]]; then
        mv "$tmpFile" "$QA_LOCK_FILE" 2>/dev/null || true
    fi
}

###################################################################
# releaseLock
#
# Releases the QA lock and records timing data.
#
# Arguments:
#   $1 - Exit code (0 for success)
###################################################################
releaseLock() {
    local exitCode="${1:-0}"

    # Only release if we acquired it
    [[ $QA_LOCK_ACQUIRED -eq 0 ]] && return 0

    # Stop heartbeat first
    stopHeartbeat

    # Calculate duration
    local endTime=$(date +%s)
    local duration=$((endTime - QA_LOCK_START_TIME))

    # Record timing data if successful
    if [[ $exitCode -eq 0 ]]; then
        # Extract tool and path from lock file
        local tool=$(jq -r '.tool' "$QA_LOCK_FILE" 2>/dev/null || echo "")
        local path=$(jq -r '.path' "$QA_LOCK_FILE" 2>/dev/null || echo "")

        if [[ -n "$tool" ]]; then
            recordCommandTiming "$tool" "$path" "$duration"
        fi
    fi

    # Remove lock file
    rm -f "$QA_LOCK_FILE" 2>/dev/null || true

    # Display completion message
    echo ""
    echo "[QA Lock] Released lock at $(date +"%H:%M:%S")"
    echo "[QA Lock] Execution took $(formatDuration $duration)"
    if [[ $exitCode -eq 0 ]]; then
        echo "[QA Lock] Updated timing data for future estimates"
    fi
    echo ""

    # Mark as released
    QA_LOCK_ACQUIRED=0
}

###################################################################
# setupLockCleanup
#
# Sets up trap to ensure lock is released on exit.
###################################################################
setupLockCleanup() {
    trap 'stopHeartbeat; releaseLock 1' EXIT ERR INT TERM
}
