# Timing Data Schema and ETA Calculation Analysis

## Executive Summary

The timing data schema and ETA calculation system in the locking system plan has several design strengths but also contains **five concerns** that could impact timing accuracy, data integrity, and cross-project isolation. This analysis evaluates the core design decisions and identifies potential issues.

**Status**: 4 concerns require design decisions; 1 concern requires implementation validation.

---

## 1. Command Key Format Adequacy

### Current Design
```
Format: {tool}:{pathType}:{normalizedPath}
Examples:
  - "unit:folder:tests/Db"
  - "phpstan:full-suite:"
  - "allTestingTools:full-suite:"
  - "fixer:file:" (never tracked)
```

### Analysis

**CONCERN: Missing Path Normalization Specification**

The plan states:
> **Path Normalization** (line 680-683):
> - Remove trailing slashes
> - Remove leading `./`
> - Store relative to project root

However, the plan **does not specify normalization rules for**:

1. **Path separators on Windows**: If a Windows project normalizes to `tests\Unit` but a cross-platform CI normalizes to `tests/Unit`, they become different keys
   - Same logical path, two different keys
   - Timing data never converges

2. **Case sensitivity**: No specification for case normalization
   - Linux: `tests/Unit` vs `tests/unit` are different directories
   - macOS: Case-insensitive filesystem but case-preserving paths
   - Risk: Same tests on different OSes create different timing keys

3. **Symlink resolution**: Not mentioned
   - Real path vs symlink path would create different keys
   - CI containers often use different symlink structures than development

4. **Redundant path components**: No rules for `.` or `..`
   - `tests/Unit` vs `tests/./Unit` would be different keys
   - Unlikely but possible from script generation

5. **Trailing colons for full-suite**: The format shows `pathType:` with empty path
   - Inconsistent: `unit:folder:tests/Db` has trailing path, but `phpstan:full-suite:` has trailing colon
   - Minor but should be `unit:full-suite` (no trailing separator)

### Recommendation

**BEFORE IMPLEMENTATION**: Define explicit path normalization rules:

```bash
# Recommended normalization function
normalizePath() {
    local path="$1"

    # Convert all separators to forward slashes (Windows support)
    path="${path//\\/\/}"

    # Remove leading ./
    path="${path#./}"

    # Remove trailing slashes
    path="${path%/}"

    # Lowercase for case-insensitive comparison
    path="${path,,}"

    # Resolve relative path components
    python3 -c "import os; print(os.path.normpath('$path'))"

    echo "$path"
}
```

Also update command key format to be consistent:
```
{tool}:{pathType}  # For full-suite (no trailing separator)
{tool}:{pathType}:{normalizedPath}  # For folder/file
```

**Impact**: Without this, timing accuracy degrades across environments.

---

## 2. Keeping 10 Executions Per Command Key

### Current Design

> **Data Retention** (line 257-260):
> - Keep last 10 executions per command key
> - Recalculate average after each new execution
> - Prune old data when saving

### Analysis

**This is reasonable but consider**:

1. **Statistical validity**: 10 samples is marginal for a reliable average
   - With variance of ±30%, need ~15-20 samples for 95% confidence
   - Current: ~67% confidence interval with 10 samples
   - First 3-5 runs are learning; true pattern may not emerge until run 6-10

2. **Outlier sensitivity**: No outlier detection mentioned
   - One slow run (network congestion, system load) heavily skews the average
   - Example: 9 runs of 50s + 1 run of 500s = average 95s (1.9x inflation)
   - With only 10 samples, one outlier = 10% of data distortion

3. **Stale data removal**: No mention of how old data is pruned
   - Is it FIFO (first 10 runs chronologically)?
   - Or does timestamp matter (e.g., last 10 runs within 30 days)?
   - Current plan says "keep last 10" but doesn't clarify "last" = most recent by what metric

4. **Minimum sample requirement**: No minimum before using historical data
   - With only 1 sample, average = that one run
   - Better: require 3+ samples for historical data; use 900s default for <3

5. **No regression handling**: When ETA becomes worse than average
   - If runs 1-9 average 100s, but run 10 is 300s, average becomes 118s
   - No detection that something changed (test suite expanded, new slow test)

### Recommendation

**DESIGN DECISION NEEDED**:

1. **Increase to 20 samples** (better statistical validity) OR **apply outlier detection**
   - If staying at 10: add median-based outlier detection (exclude >2σ from median)
   - Better: keep 20 samples to handle variance naturally

2. **Define explicit FIFO pruning**:
   ```bash
   # Remove oldest execution if count > 10
   if jq '.commands["'$commandKey'"].executions | length' > 10; then
       jq '.commands["'$commandKey'"].executions |= .[1:]'
   fi
   ```

3. **Require minimum 3 samples** before using historical data:
   ```bash
   if [[ $(jq '.commands["'$commandKey'"].sample_count') -lt 3 ]]; then
       echo 900  # Use default until 3 samples
       return 0
   fi
   ```

4. **Add run-to-run variance calculation**:
   ```bash
   # Warn if latest run differs >50% from average
   if (( latestRun > average * 1.5 )); then
       echo "WARNING: Last run was 50% slower than average" >&2
   fi
   ```

**Impact**: Current design works but may produce inaccurate ETAs when variance is high.

---

## 3. 20% Buffer for ETA Calculation

### Current Design

```bash
# Line 292-293 in calculateEta()
# Add 20% buffer to average for safety
echo "$(echo "$avgSeconds * 1.2" | bc | cut -d. -f1)"
```

### Analysis

**CONCERN: 20% buffer is arbitrary and may be insufficient**

1. **Empirical testing needed**: 20% assumes variance pattern
   - Tests with high variance (unit tests with mocks) might need 30-40%
   - Tests with low variance (static analysis) might only need 10%
   - No data on actual QA tool variance in real projects

2. **Distribution shape unknown**: Assuming normal distribution
   - QA tools often have bimodal distribution:
     - Fast run: tests pass quickly (10s)
     - Slow run: all tests run (120s)
   - 20% buffer works if data is normally distributed; worse if bimodal

3. **Insufficient for high variance cases**:
   - If average is 100s with σ=30s, 95th percentile is ~159s
   - 20% buffer gives 120s ETA (26% underestimate)
   - User sees: "2 minutes remaining" but waits 2.5 minutes (12.5% error)

4. **Risk vs user experience tradeoff**:
   - Too small: Users see "time remaining" expire while tool still running (frustration)
   - Too large: Users wait longer than needed (frustration)
   - 20% seems optimized for typical case, but atypical cases fail

5. **No distinction between tool types**:
   - PHPStan (deterministic, <5% variance): 20% is excessive
   - PHPUnit (variable test execution): 20% may be insufficient
   - Static tools vs test tools behave differently

6. **Truncation to integer seconds loses precision**:
   ```bash
   echo "$avgSeconds * 1.2" | bc | cut -d. -f1
   ```
   - This truncates rather than rounds
   - Example: 100.8 → 100 (instead of 101)
   - Over many runs, consistent underestimation

### Recommendation

**OPTION A: Keep 20% but improve**
- Use rounding instead of truncation:
  ```bash
  echo "scale=0; ($avgSeconds * 1.2) / 1" | bc
  ```
- Or in bash: `printf "%.0f" $(echo "$avgSeconds * 1.2" | bc)`

**OPTION B: Make buffer configurable** (better long-term)
```bash
# In timing data metadata
"buffer_percentage": 20,  # Can be tuned per project

# In calculateEta()
bufferPercent=$(jq -r '.metadata.buffer_percentage // 20')
echo "$(echo "$avgSeconds * (1 + $bufferPercent / 100)" | bc)"
```

**OPTION C: Tool-specific buffers** (most accurate)
```json
{
  "metadata": {
    "version": "1.0",
    "tool_buffers": {
      "phpstan": 10,      // Deterministic
      "phpunit": 30,      // Variable
      "rector": 15,       // Medium variance
      "fixer": 15         // Medium variance
    }
  }
}
```

**My recommendation**: Start with Option A (fix truncation), then add Option C (tool-specific buffers) after 3 months of data collection.

**Impact**: Current rounding error is minor; buffer percentage is appropriate but unprincipled.

---

## 4. Default ETAs Appropriateness

### Current Design

```bash
# Line 273-275: File paths
if [[ "$path" =~ \.[a-zA-Z0-9]+$ ]]; then
    pathType="file"
    echo 30  # Hard-coded 30 seconds for files
    return 0
fi

# Line 295: Unknown/no-data fallback
echo 900  # 15 minutes for unknown
```

### Analysis

**CONCERN A: 30-second file ETA is oversimplified**

1. **File path does not always mean single-file testing**
   - `-p tests/Unit/SomeTest.php` might test one file
   - But that file could have 100 test methods (~100-300s to run)
   - 30s ETA for PHPUnit is severely underestimated

2. **Different tools on same file have different times**:
   - PHPStan on single file: ~1-2s
   - PHP CS Fixer on single file: ~0.5s
   - PHPUnit on single file: 5-60s (depending on test count)
   - Hard-coded 30s only works for PHPUnit; too slow for others

3. **Regex detection `\.[a-zA-Z0-9]+$` has false positives**:
   - `.gitkeep` matches (is a file, but not a PHP file)
   - Any hidden file: `.env` matches (not a test file)
   - Should explicitly check for `.php` or `.phtml`

4. **No distinction between test and source files**:
   - `-p tests/Unit/SomeTest.php` → likely slow (full test file)
   - `-p src/Service/SomeService.php` → likely fast (static analysis only)
   - Same 30s ETA for both; one is drastically wrong

### Recommendation

**Better file ETA strategy**:

```bash
# Determine tool's nature first
case "$tool" in
    "unit"|"phpunit")
        # Tests: higher ETA
        if [[ "$path" =~ \.php$ ]]; then
            echo 45  # Single test file: 45s
        fi
        ;;
    "stan"|"phpstan")
        # Static analysis: lower ETA
        if [[ "$path" =~ \.php$ ]]; then
            echo 5   # Single file analysis: 5s
        fi
        ;;
    "fixer"|"phpCsFixer")
        # Style fixing: lower ETA
        if [[ "$path" =~ \.php$ ]]; then
            echo 3   # Single file: 3s
        fi
        ;;
    *)
        # Unknown: use default
        echo 30
        ;;
esac
```

**CONCERN B: 900-second (15-minute) default is conservative but reasonable**

1. **Design rationale**: Better to overestimate than underestimate
   - User sees "15 minutes" and gets faster completion: positive surprise
   - User sees "2 minutes" and waits 15: negative surprise
   - This logic is sound

2. **Real-world accuracy**:
   - New project, unknown tool/path: 15 minutes is safe upper bound
   - Most QA pipelines on established projects: 2-10 minutes
   - First-time users won't be surprised (too-long estimate beats running blind)

3. **Not applicable to files**: Files won't reach this default
   - Only happens on first run of a folder without historical data
   - One 15-minute wait per new folder path is acceptable

4. **Better guidance**: After accumulating data, becomes accurate

### Recommendation

**Update file ETA logic** (see above); keep 900s default for unknown data.

**Impact**: 30s file ETA can cause significant underestimation for PHPUnit tests; should be tool-aware.

---

## 5. Path Normalization Issues and Risks

### Current Design

```
Path Normalization Rules Specified (lines 680-683):
  - Remove trailing slashes
  - Remove leading ./
  - Store relative to project root
```

Actual normalization in calculateEta() is minimal:
```bash
# Line 283-286
local commandKey="${tool}:${pathType}"
if [[ "$pathType" == "folder" ]]; then
    commandKey="${commandKey}:${path}"
fi
# The $path variable is used as-is (no normalization function called)
```

### Analysis

**CONCERN: Path normalization not actually implemented in code**

The plan describes normalization rules but the calculateEta() function shows no actual normalization being performed. This is the biggest timing accuracy risk:

1. **Cross-platform incompatibility**:
   - Windows developer: `-p tests\Unit` (backslashes)
   - Linux CI: `-p tests/Unit` (forward slashes)
   - Same logical path, two different command keys
   - Timing data diverges: never converges to reliable average

2. **Path variation inconsistency**:
   - User 1: `./bin/qa -t unit -p ./tests/Unit`
   - User 2: `./bin/qa -t unit -p tests/Unit`
   - Both remove leading `./` per spec, but code doesn't show this happening
   - If not actually implemented: creates duplicate keys

3. **Symlink problems**:
   - Docker volume: `/app/tests` → actual: `/mnt/volumes/tests`
   - Symlink not resolved: keys don't match between environments
   - CI container and desktop would have different paths
   - Timing data doesn't share across environments

4. **Relative path ambiguity**:
   - What if run from different directory?
   - `cd /var/www/project && ./bin/qa -p tests/Unit` vs
   - `cd /var/www && ./bin/qa -p project/tests/Unit` from parent
   - Both logically same path, but relative to different roots

5. **No validation that normalization actually occurred**:
   - Plan says "implement normalization" but no logging
   - No way to verify in production that paths are being normalized consistently
   - Silent timing data divergence is worst-case scenario

### Concrete Example of Problem

```
Scenario: Cross-platform timing data divergence

Day 1 (Windows developer):
  > ./bin/qa -t unit -p tests\Unit
  Creates: "unit:folder:tests\Unit"
  Records: 120 seconds

Day 2 (Linux CI):
  > ./bin/qa -t unit -p tests/Unit
  Creates: "unit:folder:tests/Unit" (different key!)
  Uses default: 900 seconds
  Records: 95 seconds

Result: Two different keys, timing data never converges
        ETA remains wildly inaccurate on Linux
```

### Recommendation

**CRITICAL - Implement actual normalization function**:

```bash
normalizePathForKey() {
    local path="$1"

    # 1. Resolve to absolute path from project root
    # Handle both cases: path is absolute or relative to current dir
    if [[ "$path" != /* ]]; then
        # Relative path - resolve from current directory to project root
        path="$(cd "$(pwd)" && realpath --relative-to="$projectRoot" "$path" 2>/dev/null || echo "$path")"
    else
        # Absolute path - make relative to project root
        path="$(realpath --relative-to="$projectRoot" "$path" 2>/dev/null || echo "$path")"
    fi

    # 2. Normalize separators to forward slashes (Windows → Linux)
    path="${path//\\/\/}"

    # 3. Remove leading ./
    path="${path#./}"

    # 4. Remove trailing slashes
    path="${path%/}"

    # 5. Lowercase for case-insensitive comparison
    path="${path,,}"

    # 6. Remove . and .. path components
    while [[ "$path" == *"/../"* ]] || [[ "$path" == *"/./"* ]]; do
        path="${path//\/.\//\/}"
        path="${path//\/[^/]*\/..//\/}"
    done

    echo "$path"
}

# Usage in calculateEta()
if [[ "$pathType" == "folder" ]]; then
    path="$(normalizePathForKey "$path")"
    commandKey="${commandKey}:${path}"
fi
```

**Add validation logging**:

```bash
# When recording timing data
echo "DEBUG: Normalized path '$originalPath' → '$normalizedPath'" >&2
```

**Test the function**:

```bash
# Should all produce same key
./bin/qa -p tests/Unit
./bin/qa -p ./tests/Unit
./bin/qa -p tests/Unit/
./bin/qa -p tests\Unit      # Windows
```

**Impact**: This is the highest-risk issue. Without proper implementation, timing data quality degrades over time, especially in cross-platform projects.

---

## Summary of Concerns

| # | Concern | Severity | Resolvability | Impact |
|---|---------|----------|---------------|--------|
| 1 | Command key format lacks normalization spec | High | Design decision + implementation | Cross-platform timing data divergence |
| 2 | 10 executions insufficient for statistical validity | Medium | Implementation adjustment (increase to 20) | 30-40% confidence in average; outliers skew data |
| 3 | 20% buffer arbitrary and lacks tool-specific tuning | Medium | Design decision (tool-specific buffers) | PHPUnit ETA underestimated; static tools overestimated |
| 4 | 30s file ETA not tool-aware | Medium | Implementation (tool-specific defaults) | PHPUnit single-file tests severely underestimated |
| 5 | Path normalization not actually implemented in code | Critical | Full implementation required | Timing data never converges; accuracy remains poor |

---

## Recommendations (Priority Order)

1. **CRITICAL - Before implementation**: Define and implement path normalization function
2. **HIGH - Before implementation**: Add explicit path normalization rules to plan
3. **MEDIUM - During implementation**: Increase sample size to 20 or add outlier detection
4. **MEDIUM - During implementation**: Make file ETA tool-aware
5. **MEDIUM - Post-implementation**: Add tool-specific ETA buffers after 3 months data

---

## Testing Strategy for Timing System

Once implemented, test these scenarios:

```bash
# Test 1: Path normalization consistency
./bin/qa -t unit -p tests/Unit
./bin/qa -t unit -p ./tests/Unit
./bin/qa -t unit -p tests/Unit/
# All three should use same command key

# Test 2: File path ETA accuracy
./bin/qa -t unit -p tests/Unit/SomeTest.php  # Should be ~45s
./bin/qa -t stan -p src/Service.php          # Should be ~5s

# Test 3: Folder timing accumulation
# Run same folder 5 times, verify ETA improves
for i in {1..5}; do
    ./bin/qa -t unit -p tests/Unit
    sleep 5
done
# After run 5, ETA should be within 20% of actual time

# Test 4: Cross-platform consistency
# Run on Windows: note generated command key
# Run on Linux: verify same key is created
# Verify timing data file contains single merged entry

# Test 5: Outlier handling
# Run normally 5 times (~100s average)
# Run once with system load (pause while running)
# Verify ETA doesn't jump >30% higher
```

---

## Conclusion

The timing data schema and ETA calculation system is well-designed in principle but has **implementation gaps** that could significantly reduce timing accuracy, especially in cross-platform and high-variance scenarios. The critical issue is path normalization, which is specified in the plan but not shown in the code examples.

**Recommendation**: Address the five concerns (especially #1 and #5) **before implementation** to ensure the timing system provides reliable ETAs from day one. Otherwise, users will experience inaccurate time estimates that erode confidence in the locking system's value.
