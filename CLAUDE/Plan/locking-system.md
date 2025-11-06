# QA Tool File-Level Locking System

## Progress

[ ] Create lock management module (`includes/generic/lock.inc.bash`)
[ ] Create command timing tracker module (`includes/generic/timing.inc.bash`)
[ ] Create lock file schema (JSON format)
[ ] Create timing data schema (JSON format)
[ ] Implement lock acquisition logic
[ ] Implement lock release logic
[ ] Implement stale lock detection (time-based, container-agnostic)
[ ] Implement ETA calculation
[ ] Implement command timing recording
[ ] Implement heartbeat mechanism
[ ] Integrate into `bin/qa` main script
[ ] Test lock acquisition/release
[ ] Test stale lock detection
[ ] Test concurrent execution prevention
[ ] Test timing data collection
[ ] Test ETA display
[ ] Test cross-container behavior
[ ] Update README.md with lock system documentation
[ ] Add troubleshooting guide

## Summary

Implement a file-level locking system for php-qa-ci to prevent concurrent QA tool execution across any project using the library. The system will:
- Use lock files in `$projectRoot/qaConfig/.qa-lock/` directory (shared across containers)
- Track command execution times in `timing-data.json` for accurate ETAs
- Calculate ETAs based on historical data
- Detect and handle stale locks using container-agnostic time-based detection
- Display helpful messages when lock is held
- Work across desktop/container/LXC environments

## Technical Design

### Lock File Location

**Primary Lock File**: `$projectRoot/qaConfig/.qa-lock/qa-running.lock`

**Why qaConfig?**
- Can be shared between containers (desktop/yolo/lxc)
- Not in var/ (which might be environment-specific)
- Dedicated directory for lock-related files keeps things organized
- Works for any project using php-qa-ci

**Directory Structure**:
```
$projectRoot/
└── qaConfig/
    └── .qa-lock/
        ├── .gitignore             # Auto-generated
        ├── timing-data.json       # Tracked in git
        └── qa-running.lock        # Not tracked (active lock)
```

### Lock File Schema (JSON)

```json
{
  "pid": 12345,
  "hostname": "desktop-dev",
  "timestamp": "2025-11-06T14:30:00Z",
  "last_activity": "2025-11-06T14:31:00Z",
  "command": "./bin/qa -t unit -p tests/Db",
  "tool": "unit",
  "path": "tests/Db",
  "pathType": "folder",
  "eta_seconds": 120,
  "eta_completion": "2025-11-06T14:32:00Z",
  "user": "developer",
  "master_log_file": "var/qa/qa-run.20251106-143000.log",
  "current_tool": "phpstan",
  "tools": [
    {
      "name": "rector",
      "started": "2025-11-06T14:30:05Z",
      "completed": "2025-11-06T14:30:45Z",
      "duration_seconds": 40,
      "status": "completed"
    },
    {
      "name": "phpCsFixer",
      "started": "2025-11-06T14:30:46Z",
      "completed": "2025-11-06T14:31:10Z",
      "duration_seconds": 24,
      "status": "completed"
    },
    {
      "name": "phpstan",
      "started": "2025-11-06T14:31:15Z",
      "status": "running"
    }
  ]
}
```

**Fields**:
- `pid`: Process ID (informational only, not used for stale detection across containers)
- `hostname`: Distinguish between containers/machines (informational)
- `timestamp`: Lock acquisition time (ISO 8601)
- `last_activity`: Last time process updated this file (ISO 8601) - **critical for stale detection**
- `command`: Full command being executed
- `tool`: Specific tool if -t used (empty for full pipeline)
- `path`: Specific path if -p used (empty for full suite)
- `pathType`: "file", "folder", or "full-suite"
- `eta_seconds`: Estimated duration in seconds
- `eta_completion`: Calculated completion time (ISO 8601)
- `user`: User executing command
- `master_log_file`: Path to master log file capturing entire QA run
- `current_tool`: Name of tool currently executing (updated as pipeline progresses)
- `tools`: Array of tool execution records

**Tool Record Fields**:
- `name`: Tool name (e.g., "rector", "phpstan", "phpunit")
- `started`: When tool started (ISO 8601)
- `completed`: When tool finished (ISO 8601, omitted if still running)
- `duration_seconds`: How long tool took (omitted if still running)
- `status`: "running", "completed", or "failed"

### Master Log File

**Purpose**: Capture complete output of entire QA run including all tools

**Location**: `$varDir/qa-run.YYYYMMDD-HHMMSS.log`
- Where `$varDir` = `$projectRoot/var/qa/` (already set by QA pipeline)
- Results in: `./var/qa/qa-run.YYYYMMDD-HHMMSS.log`

**Why Master Logging**:
- Provides complete audit trail of QA execution
- Useful for debugging when tools fail
- Referenced in lock file for easy access
- Complements individual tool logs

**Implementation Using `exec` and Process Substitution**:

```bash
# In bin/qa, after variable initialization but before tool execution
# Note: $varDir is already set to $projectRoot/var/qa/ by the pipeline

# Create master log file
QA_MASTER_LOG="$varDir/qa-run.$(date +%Y%m%d-%H%M%S).log"
mkdir -p "$varDir"

# Redirect ALL subsequent stdout/stderr through tee
# This captures everything while still displaying to console
exec > >(tee -a "$QA_MASTER_LOG") 2>&1

# Export for use in lock file
export QA_MASTER_LOG
```

**How It Works**:
1. `exec >` redirects file descriptor 1 (stdout) for entire process
2. `>(tee -a "$QA_MASTER_LOG")` is process substitution creating a pipe to `tee`
3. `tee -a` appends to log file AND outputs to stdout (so user still sees everything)
4. `2>&1` redirects stderr to stdout (so errors are captured too)
5. All subsequent output from ANY command goes through this redirection
6. Individual tools can still use their own `tee` commands (they just add another layer)

**Benefits**:
- ✅ Zero code changes needed in individual tool scripts
- ✅ Captures EVERYTHING (tool output, debug messages, errors)
- ✅ User still sees all output in real-time
- ✅ Works with existing tool-specific log files
- ✅ Master log is timestamped (won't overwrite previous runs)

**Example Flow**:
```
bin/qa runs:
  ├─ exec > >(tee qa-run.log) 2>&1  # Master logging starts
  ├─ Rector runs
  │   └─ Output goes to: console + qa-run.log
  ├─ PHP CS Fixer runs
  │   └─ Output goes to: console + qa-run.log
  ├─ PHPStan runs
  │   ├─ Uses: tee phpstan.log  # Tool-specific logging
  │   └─ Output goes to: console + qa-run.log + phpstan.log
  └─ PHPUnit runs
      ├─ Uses: tee phpunit.log
      └─ Output goes to: console + qa-run.log + phpunit.log
```

**Archive Policy**:
- Keep last 10 master logs only (consistent with tool logs)
- Automatically prune old logs when creating new master log
- Uses same rotation logic as `archiveToolLog` function
- NOT tracked in git (in `var/` directory)

**Automatic Rotation Implementation**:
```bash
# In initLockSystem() or when creating master log

# Create master log file
QA_MASTER_LOG="$varDir/qa-run.$(date +%Y%m%d-%H%M%S).log"
mkdir -p "$varDir"

# Prune old master logs - keep last 10
mapfile -t oldLogs < <(ls -1t "$varDir"/qa-run.*.log 2>/dev/null || true)
if [[ ${#oldLogs[@]} -ge 10 ]]; then
    # Remove logs beyond the 10 most recent (indices 10+)
    for ((i=10; i<${#oldLogs[@]}; i++)); do
        rm -f "$varDir/${oldLogs[$i]}"
        echo "Pruned old master log: ${oldLogs[$i]}"
    done
fi

# Set up master log redirection
exec > >(tee -a "$QA_MASTER_LOG") 2>&1
export QA_MASTER_LOG
```

This ensures disk usage stays bounded and mirrors the behavior of individual tool log rotation.

### Timing Data Schema (JSON)

```json
{
  "metadata": {
    "version": "1.0",
    "last_updated": "2025-11-06T14:30:00Z"
  },
  "commands": {
    "unit:folder:tests/Db": {
      "executions": [
        {"timestamp": "2025-11-06T14:00:00Z", "duration_seconds": 115},
        {"timestamp": "2025-11-06T13:00:00Z", "duration_seconds": 120},
        {"timestamp": "2025-11-06T12:00:00Z", "duration_seconds": 118}
      ],
      "average_seconds": 117.67,
      "sample_count": 3
    },
    "phpstan:full-suite": {
      "executions": [
        {"timestamp": "2025-11-06T14:00:00Z", "duration_seconds": 45},
        {"timestamp": "2025-11-06T13:00:00Z", "duration_seconds": 48}
      ],
      "average_seconds": 46.5,
      "sample_count": 2
    },
    "allStaticAnalysisTools:full-suite": {
      "executions": [
        {"timestamp": "2025-11-06T14:00:00Z", "duration_seconds": 180}
      ],
      "average_seconds": 180,
      "sample_count": 1
    }
  }
}
```

**Command Key Format**: `{tool}:{pathType}:{normalizedPath}`
- `tool`: Tool name (unit, phpstan, fixer) or pipeline phase (allStaticAnalysisTools)
- `pathType`: "full-suite", "folder", or "file"
- `normalizedPath`: Normalized path (empty for full-suite, folder path for folders)

**Data Retention**:
- Keep last 10 executions per command key
- Recalculate average after each new execution
- Prune old data when saving

### ETA Calculation Logic

```bash
calculateEta() {
    local tool="$1"
    local path="$2"

    # Determine path type
    local pathType="full-suite"
    if [[ -n "$path" ]]; then
        if [[ "$path" =~ \.[a-zA-Z0-9]+$ ]]; then
            # Path ends with extension - it's a file
            pathType="file"
            echo 30  # Hard-coded 30 seconds for files
            return 0
        else
            pathType="folder"
        fi
    fi

    # Build command key
    local commandKey="${tool}:${pathType}"
    if [[ "$pathType" == "folder" ]]; then
        commandKey="${commandKey}:${path}"
    fi

    # Look up in timing data
    local avgSeconds=$(jq -r ".commands[\"${commandKey}\"].average_seconds // empty" "$TIMING_DATA_FILE")

    if [[ -n "$avgSeconds" && "$avgSeconds" != "null" ]]; then
        # Add 20% buffer to average for safety
        echo "$(echo "$avgSeconds * 1.2" | bc | cut -d. -f1)"
    else
        # No historical data - default to 15 minutes (900 seconds)
        echo 900
    fi
}
```

### Stale Lock Detection Logic

**Container-Agnostic Stale Lock Detection**

Since PIDs are not unique across container boundaries (desktop/yolo/lxc have separate PID namespaces), we use time-based detection:

**Stale Lock Conditions** (ANY of these triggers stale detection):

1. **Activity Timeout**: `last_activity` + 10 minutes < current time
   - Process should update `last_activity` every minute via heartbeat
   - If 10 minutes pass with no update, process is dead/stuck

2. **ETA Timeout**: `eta_completion` + grace period < current time
   - Grace period: 10 minutes (allows for variance in execution time)
   - If way past expected completion, something went wrong

**Implementation**:
```bash
isStaleLock() {
    local lockFile="$1"

    # Check if lock file exists
    [[ -f "$lockFile" ]] || return 1  # Not stale, doesn't exist

    # Read lock file
    local lastActivity=$(jq -r '.last_activity' "$lockFile")
    local etaCompletion=$(jq -r '.eta_completion' "$lockFile")
    local now=$(date +%s)

    # Check activity timeout (10 minutes since last activity)
    local activityTimestamp=$(date -d "$lastActivity" +%s 2>/dev/null || echo 0)
    local activityTimeout=600  # 10 minutes in seconds
    if [[ $activityTimestamp -gt 0 ]] && [[ $((now - activityTimeout)) -gt $activityTimestamp ]]; then
        # Log reason for debugging
        echo "STALE_REASON=activity_timeout" >&2
        return 0  # Stale - no activity for 10+ minutes
    fi

    # Check ETA timeout (10 minutes past expected completion)
    local etaTimestamp=$(date -d "$etaCompletion" +%s 2>/dev/null || echo 0)
    local etaGracePeriod=600  # 10 minutes grace period
    if [[ $etaTimestamp -gt 0 ]] && [[ $((now - etaGracePeriod)) -gt $etaTimestamp ]]; then
        echo "STALE_REASON=eta_timeout" >&2
        return 0  # Stale - way past expected completion
    fi

    return 1  # Not stale
}
```

**Heartbeat Mechanism**

To keep `last_activity` updated, run a background heartbeat process:

```bash
startHeartbeat() {
    local lockFile="$1"

    # Background process to update last_activity every 60 seconds
    (
        while [[ -f "$lockFile" ]]; do
            sleep 60
            if [[ -f "$lockFile" ]]; then
                # Update last_activity timestamp
                local now=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
                local tmpFile="${lockFile}.tmp.$$"
                jq --arg now "$now" '.last_activity = $now' "$lockFile" > "$tmpFile" 2>/dev/null
                mv "$tmpFile" "$lockFile" 2>/dev/null
            fi
        done
    ) &

    # Store background PID for cleanup
    QA_HEARTBEAT_PID=$!
}

stopHeartbeat() {
    if [[ -n "$QA_HEARTBEAT_PID" ]] && kill -0 "$QA_HEARTBEAT_PID" 2>/dev/null; then
        kill "$QA_HEARTBEAT_PID" 2>/dev/null || true
        wait "$QA_HEARTBEAT_PID" 2>/dev/null || true
    fi
}
```

### Lock Acquisition Flow

```
1. Check if lock file exists
   ↓
2. If exists, check if stale (activity timeout OR eta timeout)
   ↓
3. If stale, display reason and remove old lock
   ↓
4. If not stale, display lock status and exit
   ↓
5. Create lock directory if needed
   ↓
6. Calculate ETA for current command
   ↓
7. Write lock file with all metadata (including last_activity)
   ↓
8. Start heartbeat background process
   ↓
9. Set up trap for cleanup on exit (stop heartbeat + remove lock)
   ↓
10. Proceed with QA execution
```

### Lock Release Flow

```
1. Stop heartbeat background process
   ↓
2. Record end time
   ↓
3. Calculate actual duration
   ↓
4. If execution successful (exit code 0):
   ↓
5. Update timing data file
   ↓
6. Remove lock file
   ↓
7. Display completion message
```

### User-Facing Messages

**When Lock Acquired Successfully**:
```
[QA Lock] Acquired lock at 14:30:00
[QA Lock] Estimated completion: 14:32:00 (2 minutes)
```

**When Lock Already Held**:
```
[QA Lock] Cannot start - another QA process is running
[QA Lock] Process: ./bin/qa -t unit -p tests/Module
[QA Lock] Started: 14:30:00 by developer on desktop-dev
[QA Lock] Expected completion: 14:32:00 (1 minute remaining)
[QA Lock] If this lock is stale, it will auto-clear after 14:37:00
```

**When Stale Lock Detected**:
```
[QA Lock] Detected stale lock from previous process
[QA Lock] Started: 14:20:00 by developer on desktop-dev
[QA Lock] Last activity: 14:25:00 (15 minutes ago)
[QA Lock] Reason: No activity for 10+ minutes (process likely died)
[QA Lock] Removing stale lock and proceeding...
```

OR (if ETA-based detection):
```
[QA Lock] Detected stale lock from previous process
[QA Lock] Started: 14:20:00 by developer on yolo-container
[QA Lock] Expected completion: 14:32:00 (18 minutes ago)
[QA Lock] Reason: Way past expected completion time
[QA Lock] Removing stale lock and proceeding...
```

**On Successful Completion**:
```
[QA Lock] Released lock at 14:32:15
[QA Lock] Execution took 2 minutes 15 seconds
[QA Lock] Updated timing data for future estimates
```

## Git Tracking Rules

**Directory-Level .gitignore** (`$projectRoot/qaConfig/.qa-lock/.gitignore`):

Created automatically by `initLockSystem()` function:

```gitignore
# QA Lock System - Auto-generated, do not edit manually
# Lock files should never be tracked
*.lock
.lock.*

# Timing data SHOULD be tracked (negation)
!timing-data.json
```

**Why this approach is better**:
- Self-contained within lock directory
- No modification to project .gitignore needed
- Clear ownership (lock system manages its own git rules)
- Automatically created when lock directory is initialized

**MUST be tracked** (committed to project git):
```
qaConfig/.qa-lock/.gitignore       # Auto-generated, tracks lock system git rules
qaConfig/.qa-lock/timing-data.json # Historical timing data
```

**MUST NOT be tracked** (handled by .gitignore above):
```
qaConfig/.qa-lock/*.lock           # Active lock files
```

## Implementation Files

### File 1: `includes/generic/lock.inc.bash`

**Purpose**: Core locking functionality

**Functions**:
- `initLockSystem()` - Initialize lock directory, .gitignore, and files
  - Creates `$projectRoot/qaConfig/.qa-lock/` directory if needed
  - Creates `.gitignore` inside directory (if not exists)
  - Creates bootstrap `timing-data.json` (if not exists)
  - Sets up master log file redirection
- `acquireLock()` - Acquire lock before QA execution
- `releaseLock()` - Release lock after QA execution
- `checkExistingLock()` - Check if lock exists and valid
- `isStaleLock()` - Detect stale locks (time-based, container-agnostic)
- `removeStaleLock()` - Clean up stale locks
- `displayLockStatus()` - Show lock information to user
- `setupLockCleanup()` - Set up trap for cleanup
- `startHeartbeat()` - Start background process to update last_activity
- `stopHeartbeat()` - Stop background heartbeat process
- `toolStart()` - Record tool start in lock file
  - Adds tool entry to `tools` array with status "running"
  - Updates `current_tool` field
  - Records start timestamp
- `toolComplete()` - Record tool completion in lock file
  - Updates tool record with completion time and duration
  - Sets status to "completed"
  - Clears `current_tool` if this was the current tool
- `toolFailed()` - Record tool failure in lock file
  - Updates tool record with failure time
  - Sets status to "failed"
  - Keeps tool record for debugging

**Global Variables**:
- `QA_LOCK_DIR` - Lock directory path
- `QA_LOCK_FILE` - Lock file path
- `QA_LOCK_ACQUIRED` - Flag indicating if we hold lock
- `QA_LOCK_START_TIME` - Lock acquisition timestamp
- `QA_HEARTBEAT_PID` - Background heartbeat process PID

### File 2: `includes/generic/timing.inc.bash`

**Purpose**: Command timing tracking and ETA calculation

**Functions**:
- `initTimingData()` - Initialize timing data file (create if doesn't exist)
- `calculateEta()` - Calculate ETA for command
- `recordCommandTiming()` - Record successful execution time
- `getCommandKey()` - Generate command key for lookup
- `getPathType()` - Determine if path is file/folder/full-suite
- `formatDuration()` - Format seconds to human-readable
- `formatTimestamp()` - Format timestamp for display

**Global Variables**:
- `TIMING_DATA_FILE` - Timing data JSON file path
- `QA_COMMAND_KEY` - Current command key
- `QA_ETA_SECONDS` - Calculated ETA

**Bootstrap Behavior**:
- If `timing-data.json` doesn't exist, create with empty structure:
```json
{
  "metadata": {
    "version": "1.0",
    "last_updated": "2025-11-06T14:30:00Z"
  },
  "commands": {}
}
```
- First run of any command uses 15-minute default ETA
- Subsequent runs use accumulated timing data

### Integration Points in `bin/qa`

**After line 150 (after prepareDirectories)**:
```bash
# Source lock system
source "${qaDir}/includes/generic/lock.inc.bash"
source "${qaDir}/includes/generic/timing.inc.bash"

# Initialize and acquire lock
initLockSystem "$projectRoot"
acquireLock "$singleToolToRun" "$specifiedPath" || exit 1
```

**After line 207 (after all tools complete)**:
```bash
# Record timing and release lock
releaseLock "$?"  # Pass exit code
```

**Trap setup (early in script, after initial variables)**:
```bash
# Set up cleanup trap
trap 'stopHeartbeat; releaseLock 1' EXIT ERR INT TERM
```

**Modify `runTool` function in `includes/functions.inc.bash`**:

Wrap the existing `runTool` function to track per-tool execution:

```bash
# Original runTool function (keep existing logic)
_originalRunTool() {
    local toolName="$1"
    # ... existing tool execution logic ...
}

# Wrapper that adds tool tracking
runTool() {
    local toolName="$1"

    # Record tool start in lock file
    if [[ -n "${QA_LOCK_ACQUIRED:-}" ]]; then
        toolStart "$toolName"
    fi

    # Run the actual tool
    local exitCode=0
    _originalRunTool "$toolName" || exitCode=$?

    # Record tool completion/failure in lock file
    if [[ -n "${QA_LOCK_ACQUIRED:-}" ]]; then
        if [[ $exitCode -eq 0 ]]; then
            toolComplete "$toolName"
        else
            toolFailed "$toolName"
        fi
    fi

    return $exitCode
}
```

**Alternative: Hook Points for Tool Tracking** (if wrapping runTool is too invasive):

Add explicit calls at each tool execution point in `bin/qa`:

```bash
# Example for PHPStan
toolStart "phpstan"
runTool phpstan
toolComplete "phpstan"

# Example for PHPUnit
toolStart "phpunit"
runTool phpunit
toolComplete "phpunit"
```

This approach is more explicit but requires changes at each tool call site.

## Path-Specific Behavior

### File Path Detection

**Rule**: If `-p` argument ends with a file extension, treat as file

**Examples**:
- `-p tests/Unit/SomeTest.php` → `pathType="file"`, `ETA=30s`
- `-p src/Service/SomeService.php` → `pathType="file"`, `ETA=30s`

**Implementation**:
```bash
if [[ "$path" =~ \.[a-zA-Z0-9]+$ ]]; then
    pathType="file"
fi
```

### Folder Path Tracking

**Rule**: If `-p` argument is a directory, track timing data

**Examples**:
- `-p tests/Unit` → `pathType="folder"`, track timing, key: `unit:folder:tests/Unit`
- `-p src/Service` → `pathType="folder"`, track timing, key: `phpstan:folder:src/Service`

**Path Normalization**:
- Remove trailing slashes
- Remove leading `./`
- Store relative to project root

### Full Suite Tracking

**Rule**: No `-p` argument means full suite execution

**Examples**:
- `./bin/qa -t unit` → `pathType="full-suite"`, key: `unit:full-suite`
- `./bin/qa` → `pathType="full-suite"`, key: `allTestingTools:full-suite`

## Error Handling

### Lock Acquisition Failures

**Scenario 1: Lock file already exists (not stale)**
- Display lock status message
- Show expected completion time
- Exit with code 1

**Scenario 2: Cannot create lock directory**
- Display error message
- Exit with code 1

**Scenario 3: Cannot write lock file**
- Display error message
- Clean up partial lock directory
- Exit with code 1

### Lock Release Failures

**Scenario 1: Lock file doesn't exist**
- Log warning (shouldn't happen)
- Continue normally

**Scenario 2: Cannot write timing data**
- Log warning
- Remove lock file anyway
- Continue normally

**Scenario 3: Cannot remove lock file**
- Log error
- Manual cleanup required
- Exit with warning message

## Safety Features

### Container-Agnostic Stale Detection

**Time-Based Detection Only**:
- No reliance on PIDs (which are container-specific)
- Works across desktop/yolo/lxc container boundaries
- Two independent checks (activity timeout AND eta timeout)

**Activity Timeout**:
- Background heartbeat updates `last_activity` every 60 seconds
- 10-minute timeout after last activity
- Detects dead/stuck processes reliably

**ETA Timeout**:
- 10-minute grace period after expected completion
- Handles variance in execution time
- Prevents premature lock removal

### Heartbeat Mechanism

**Background Process**:
- Updates `last_activity` every minute
- Runs as child process of main QA script
- Automatically cleaned up when parent exits
- Terminates when lock file removed

**Reliability**:
- If main process dies, heartbeat stops updating
- After 10 minutes, lock becomes stale
- Works regardless of how process died (kill -9, crash, etc.)

### Atomic Operations

**Lock File Creation**:
- Use `mv` for atomic lock file creation
- Create temp file first, then move into place
- Prevents partial lock files
- Race condition safe

**Lock File Updates** (heartbeat):
- Create temp file with updated timestamp
- Atomically replace original with `mv`
- Prevents corruption during updates

### Signal Handling

```bash
trap 'stopHeartbeat; releaseLock 1; exit 1' EXIT ERR INT TERM
```

Ensures lock cleanup on:
- Normal exit
- Error exit
- Ctrl+C (SIGINT)
- Kill signal (SIGTERM)
- Script errors (ERR trap)
- Heartbeat stopped before lock removed

## Testing Strategy

### Manual Testing

1. **Basic lock acquisition/release**:
   ```bash
   ./bin/qa -t unit
   # Verify lock created, released on completion
   ```

2. **Concurrent execution prevention**:
   ```bash
   ./bin/qa -t unit &
   sleep 1
   ./bin/qa -t phpstan
   # Should see lock held message
   ```

3. **Stale lock detection**:
   ```bash
   # Manually create old lock file with past timestamps
   # Run QA tool
   # Verify stale lock detected and removed
   ```

4. **File vs folder path handling**:
   ```bash
   ./bin/qa -t unit -p tests/Unit/SomeTest.php
   # Should use 30s ETA

   ./bin/qa -t unit -p tests/Unit
   # Should track timing
   ```

5. **Timing data collection**:
   ```bash
   ./bin/qa -t unit -p tests/Unit
   # Run multiple times
   # Verify timing data accumulates in qaConfig/.qa-lock/timing-data.json
   # Verify ETA becomes more accurate
   ```

6. **Cross-container testing**:
   ```bash
   # On desktop: Start long-running test
   ./bin/qa -t unit &

   # In yolo container: Attempt to run QA
   ./bin/qa -t phpstan
   # Should see lock held message with desktop hostname
   ```

### Edge Cases

- Empty timing data file
- Corrupted timing data JSON
- Lock directory doesn't exist
- Lock file has wrong permissions
- Process killed with -9 (no cleanup)
- Cross-container lock handling
- Multiple tools specified
- Invalid -p paths
- Heartbeat process orphaned

## Rollout Plan

### Phase 1: Implementation
1. Create `includes/generic/lock.inc.bash` with all lock functions
2. Create `includes/generic/timing.inc.bash` with timing functions
3. Modify `bin/qa` for integration (source modules, acquire/release lock)
4. Add trap setup for cleanup
5. Test in development
6. Commit and push to php-qa-ci repository

### Phase 2: Project Adoption
Projects using php-qa-ci will:
1. Run `composer update lts/php-qa-ci` to pull in changes
2. Run `./bin/qa` once to initialize lock system
   - Creates `qaConfig/.qa-lock/` directory
   - Creates `.gitignore` with lock file exclusions
   - Creates bootstrap `timing-data.json`
3. Commit auto-generated files to their repository:
   - `qaConfig/.qa-lock/.gitignore`
   - `qaConfig/.qa-lock/timing-data.json`
4. No manual .gitignore edits needed!

### Phase 3: Testing Across Projects
1. Test on multiple projects using php-qa-ci
2. Verify lock behavior is consistent
3. Verify timing data tracks independently per project
4. Test cross-container behavior for each project's setup

### Phase 4: Documentation
1. Update `README.md` with lock system overview
2. Create `docs/locking-system.md` with detailed documentation
3. Document lock file location and behavior
4. Document manual lock cleanup procedures
5. Add troubleshooting guide for stale locks
6. Document timing data file purpose and format
7. Commit documentation to php-qa-ci repository

## Risk Mitigation

### Risk 1: Stale locks blocking work

**Mitigation**:
- Conservative grace periods (10 minutes for both activity and ETA)
- Dual detection (activity timeout AND eta timeout)
- Clear error messages with resolution steps
- Manual override capability (delete lock file)

### Risk 2: Cross-container PID conflicts

**Mitigation**:
- ✅ **Solved**: No PID-based stale detection
- Time-based detection works across all containers
- Hostname included for informational purposes only
- Heartbeat mechanism is container-agnostic

### Risk 3: Corrupted timing data

**Mitigation**:
- Validate JSON before reading
- Fall back to defaults on parse errors
- Rebuild from empty if corrupted
- Timing data is project-specific (isolated failures)

### Risk 4: Lock directory permissions

**Mitigation**:
- Check write permissions before lock
- Create directory with appropriate permissions
- Clear error messages on permission failures

### Risk 5: Shared library updates breaking projects

**Mitigation**:
- Lock system is opt-in (initializes on first run)
- Backwards compatible (doesn't break existing usage)
- Self-contained (all changes in php-qa-ci repo)
- Projects control when to update via composer

## Future Enhancements

### Possible Additions (not in scope now)

1. **Lock status command**: `./bin/qa --lock-status` to query current lock
2. **Lock clear command**: `./bin/qa --clear-lock` to manually remove lock
3. **Lock queue**: Allow processes to queue instead of failing
4. **Parallel tool execution**: Lock per tool instead of global
5. **Historical analytics**: Report on QA execution patterns over time
6. **Configurable timeouts**: Allow projects to override default timeouts

## Success Criteria

- [ ] Lock prevents concurrent QA executions
- [ ] Stale locks detected and removed automatically
- [ ] Timing data tracked for folder-path executions
- [ ] ETA calculated accurately within 20% after 3 runs
- [ ] File paths use 30s ETA without tracking
- [ ] Lock works across desktop/container/LXC environments
- [ ] Clear error messages on all failure scenarios
- [ ] No false stale lock detections
- [ ] Lock cleanup happens on all exit scenarios
- [ ] Zero manual interventions needed in normal operation
- [ ] Works across all projects using php-qa-ci
- [ ] Project-specific timing data isolated

## Open Questions

1. ✅ **RESOLVED**: PID-based detection → Use time-based detection (activity + ETA timeouts)
2. ✅ **RESOLVED**: Git tracking → Lock files ignored, timing data tracked
3. ✅ **RESOLVED**: Project-agnostic → Uses $projectRoot, works for any project
4. Should lock file include git branch information? (Could be useful for debugging)
5. Should we track failed execution times separately? (Currently ignored)
6. Should ETA buffer be configurable (currently 20%)? (Or keep hard-coded)
7. Should there be a max ETA cap (e.g., 1 hour)? (Prevent excessive waits)
8. Should timing data be archived periodically? (Keep file size manageable)
9. Should we provide `--lock-status` and `--clear-lock` commands?

## Notes

- This system is designed for shared library php-qa-ci
- Works across all projects using the library
- Changes must be committed directly to php-qa-ci repository
- Lock files in `qaConfig/` ensure visibility across container boundaries
- Conservative defaults (15 min) prevent underestimating long operations
- File-specific executions always fast (30s) so no tracking needed
- Each project maintains its own timing data for accurate project-specific ETAs
