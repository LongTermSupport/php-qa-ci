#!/usr/bin/env bash
###############################################################################
# write-claude-block.bash — idempotently inject a tag-delimited block into
# a project's CLAUDE.md file.
#
# Usage:
#   write-claude-block.bash <template-path> <target-file> [<open-tag> <close-tag>]
#
# Default tags are <phpqaci> and </phpqaci>.
#
# Behaviour:
#   - If target file does NOT exist:        create it with a header + block.
#   - If target file has NO <tag> block:    append block (with blank-line gap).
#   - If target file has exactly ONE block: replace it in place.
#   - If target file has MULTIPLE blocks:   FAIL LOUDLY (exit 2).
#   - If only OPEN or only CLOSE present:   FAIL LOUDLY (exit 3).
#   - Idempotent: running again with identical template produces no diff.
#
# Implementation note: pure-bash + python3 for the block surgery. We use
# python only because string-level block replacement is fiddly to do safely
# in bash; awk in-place rewrites are also avoided.
###############################################################################

set -euo pipefail

TEMPLATE_PATH="${1:-}"
TARGET_FILE="${2:-}"
OPEN_TAG="${3:-<phpqaci>}"
CLOSE_TAG="${4:-</phpqaci>}"

if [[ -z "$TEMPLATE_PATH" || -z "$TARGET_FILE" ]]; then
    echo "Usage: $0 <template-path> <target-file> [<open-tag> <close-tag>]" >&2
    exit 64
fi

if [[ ! -f "$TEMPLATE_PATH" ]]; then
    echo "ERROR: template not found at $TEMPLATE_PATH" >&2
    exit 65
fi

# Sanity-check the template contains exactly one open and one close tag.
# grep -c counts matches; we use grep -F for fixed strings to avoid regex surprises.
if ! TEMPLATE_OPEN_COUNT="$(grep -cF "$OPEN_TAG" "$TEMPLATE_PATH")"; then
    TEMPLATE_OPEN_COUNT=0
fi
if ! TEMPLATE_CLOSE_COUNT="$(grep -cF "$CLOSE_TAG" "$TEMPLATE_PATH")"; then
    TEMPLATE_CLOSE_COUNT=0
fi
if [[ "$TEMPLATE_OPEN_COUNT" != "1" || "$TEMPLATE_CLOSE_COUNT" != "1" ]]; then
    echo "ERROR: template at $TEMPLATE_PATH must contain exactly one '$OPEN_TAG' and one '$CLOSE_TAG' (found open=$TEMPLATE_OPEN_COUNT close=$TEMPLATE_CLOSE_COUNT)" >&2
    exit 66
fi

# Read template into memory.
TEMPLATE_BODY="$(cat "$TEMPLATE_PATH")"

# Case 1: target file missing → create with a header + block.
if [[ ! -f "$TARGET_FILE" ]]; then
    mkdir -p "$(dirname "$TARGET_FILE")"
    {
        echo "# Project Instructions"
        echo ""
        echo "Project instructions managed in this file."
        echo ""
        printf '%s\n' "$TEMPLATE_BODY"
    } > "$TARGET_FILE"
    echo "  Created $TARGET_FILE with $OPEN_TAG block"
    exit 0
fi

# Cases 2/3/4: target file exists — use python for safe block surgery.
python3 - "$TARGET_FILE" "$TEMPLATE_PATH" "$OPEN_TAG" "$CLOSE_TAG" <<'PYTHON_BLOCK'
import sys
from pathlib import Path

target_path = Path(sys.argv[1])
template_path = Path(sys.argv[2])
open_tag = sys.argv[3]
close_tag = sys.argv[4]

target_content = target_path.read_text()
template_body = template_path.read_text()

# Normalise: ensure template_body ends with exactly one newline (no trailing blanks).
template_body = template_body.rstrip("\n") + "\n"

open_count = target_content.count(open_tag)
close_count = target_content.count(close_tag)

# FAIL LOUDLY: malformed (open without close, or vice versa).
if open_count != close_count:
    print(
        f"ERROR: malformed block tags in {target_path}: "
        f"found {open_count} '{open_tag}' and {close_count} '{close_tag}'. "
        f"Refusing to corrupt the file. Fix the tags manually and rerun.",
        file=sys.stderr,
    )
    sys.exit(3)

# FAIL LOUDLY: multiple blocks.
if open_count > 1:
    print(
        f"ERROR: multiple '{open_tag}' blocks found in {target_path}. "
        f"This script only manages a single block. Delete the extras manually.",
        file=sys.stderr,
    )
    sys.exit(2)

if open_count == 0:
    # No existing block — append with a clean blank-line separator.
    if not target_content.endswith("\n"):
        target_content += "\n"
    if not target_content.endswith("\n\n"):
        target_content += "\n"
    new_content = target_content + template_body
    action = "appended"
else:
    # Exactly one existing block — replace it in place.
    start_idx = target_content.index(open_tag)
    end_idx = target_content.index(close_tag, start_idx) + len(close_tag)
    # Strip the existing block (between open_tag start and close_tag end inclusive).
    before = target_content[:start_idx]
    after = target_content[end_idx:]
    # Drop one leading newline from `after` if present (the close-tag line's terminator)
    # so we don't accumulate blank lines on repeated runs.
    if after.startswith("\n"):
        after = after[1:]
    # Reassemble. The template_body already ends with "\n".
    new_content = before + template_body.rstrip("\n") + ("\n" + after if after else "\n")
    action = "replaced"

# Idempotency: only write if changed.
if new_content == target_content:
    print(f"  No changes — {target_path} already up to date")
    sys.exit(0)

target_path.write_text(new_content)
print(f"  Block {action} in {target_path}")
PYTHON_BLOCK
