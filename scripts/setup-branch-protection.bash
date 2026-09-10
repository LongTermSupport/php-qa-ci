#!/usr/bin/env bash

set -euo pipefail

# ============================================================================
# GitHub Branch Protection Setup - Simple & Standardized
# ============================================================================
#
# Sets up standardized branch protection with sensible defaults.
#
# Usage:
#   ./setup-branch-protection.bash                    # Standard protection, default branch
#   ./setup-branch-protection.bash --harden           # Maximum protection
#   ./setup-branch-protection.bash --branch php8.4    # Protect a named branch instead
#
# The required status checks are the job names of .github/workflows/ci.yml
# (GitHub keys Actions contexts by JOB name, not workflow name).
#
# ============================================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

HARDEN_MODE=false
BRANCH=""
while (( $# > 0 )); do
    case "$1" in
        --harden) HARDEN_MODE=true ;;
        --branch)
            if [[ -z "${2:-}" ]]; then
                echo -e "${RED}Error: --branch requires a branch name${NC}" >&2
                exit 1
            fi
            BRANCH="$2"
            shift
            ;;
        *)
            echo -e "${RED}Error: unknown argument '$1'${NC}" >&2
            echo "Usage: $0 [--harden] [--branch <name>]" >&2
            exit 1
            ;;
    esac
    shift
done

# Check prerequisites
if ! command -v gh &> /dev/null; then
    echo -e "${RED}Error: GitHub CLI (gh) is not installed${NC}"
    echo "Install from: https://cli.github.com/"
    exit 1
fi

if ! gh auth status &> /dev/null; then
    echo -e "${RED}Error: Not authenticated. Run: gh auth login${NC}"
    exit 1
fi

# Auto-detect repository
REPO=$(gh repo view --json nameWithOwner -q .nameWithOwner 2>/dev/null || true)
if [[ -z "$REPO" ]]; then
    echo -e "${RED}Error: Not in a GitHub repository${NC}"
    exit 1
fi

# Default to the repository's default branch unless --branch named one
if [[ -z "$BRANCH" ]]; then
    BRANCH=$(gh repo view --json defaultBranchRef -q .defaultBranchRef.name)
fi

echo -e "${BLUE}Setting up branch protection for: ${REPO}/${BRANCH}${NC}"

# Define protection settings
if [[ "$HARDEN_MODE" == "true" ]]; then
    echo -e "${BLUE}Mode: HARDENED (admin-only merge with extra protections)${NC}"
    REQUIRED_APPROVALS=1  # Non-admins need approval
    ENFORCE_ADMINS=true   # Even admins must pass CI checks
    SIGNED_COMMITS=true
    echo "  • 1 required review (but admins can bypass)"
    echo "  • CI checks enforced for everyone (including admins)"
    echo "  • Require signed commits"
    echo "  • Dismiss stale reviews"
    echo "  • Only admins can merge"
else
    echo -e "${BLUE}Mode: STANDARD (admin-only merge)${NC}"
    REQUIRED_APPROVALS=1  # Non-admins need approval
    ENFORCE_ADMINS=false  # Admins can bypass everything
    SIGNED_COMMITS=false
    echo "  • 1 required review (but admins can bypass)"
    echo "  • Dismiss stale reviews"
    echo "  • Require status checks (admins can bypass)"
    echo "  • Only admins can merge"
fi

# Common settings for both modes
echo "  • No force pushes"
echo "  • No branch deletion"
echo "  • Auto-delete merged branches"
echo "  • Require PR conversation resolution"

# Apply protection
echo -e "\n${BLUE}Applying protection...${NC}"

# Merging over the existing protection requires python3 (json). This is a
# maintainer-invoked script; fail early with a clear message if it is absent
# rather than risk a malformed payload.
if ! command -v python3 > /dev/null; then
    echo -e "${RED}Error: python3 is required to merge branch protection safely${NC}"
    exit 1
fi

# Temp files: current protection (GET), the merged PUT payload, the PUT result,
# and captured stderr. mktemp avoids the predictable, world-readable
# /tmp/protection-result.txt; the trap cleans them up on any exit.
tmp_current="$(mktemp)"
tmp_payload="$(mktemp)"
tmp_result="$(mktemp)"
tmp_err="$(mktemp)"
trap 'rm -f "$tmp_current" "$tmp_payload" "$tmp_result" "$tmp_err"' EXIT

# GET the current protection so we MERGE our modeled fields over it rather than
# blind-replacing the whole object. A wholesale PUT silently drops any existing
# status-check contexts (other CI jobs!), push restrictions, and settings this
# script does not model. A 404 means the branch is currently unprotected, in
# which case a fresh PUT of our defaults is correct.
if gh api -H "Accept: application/vnd.github+json" \
        "/repos/${REPO}/branches/${BRANCH}/protection" > "$tmp_current" 2> "$tmp_err"; then
    echo -e "${BLUE}Merging over existing branch protection...${NC}"
else
    if grep -qiE 'not protected|not found|HTTP 404' "$tmp_err"; then
        echo -e "${BLUE}ℹ No existing branch protection — applying a fresh policy.${NC}"
        echo '{}' > "$tmp_current"
    else
        echo -e "${RED}✗ Could not read current branch protection:${NC}"
        cat "$tmp_err"
        exit 1
    fi
fi

# Build the PUT payload by overlaying our modeled fields on the current state.
# The GitHub GET and PUT schemas are asymmetric (GET nests {enabled: bool}
# objects; PUT wants flat booleans and login/slug arrays), so a structured
# transform is required — hence python rather than a shell splice.
python3 - "$tmp_current" "$tmp_payload" "$ENFORCE_ADMINS" "$REQUIRED_APPROVALS" "$SIGNED_COMMITS" <<'PYTHON_MERGE'
import json
import sys

current_path, payload_path, enforce_admins, approvals, signed = sys.argv[1:6]

with open(current_path) as fh:
    current = json.load(fh) or {}


def as_bool(value):
    return str(value).lower() == "true"


def enabled_of(node):
    # GET returns many toggles as {"enabled": bool}; normalise to a plain bool.
    if isinstance(node, dict):
        return bool(node.get("enabled", False))
    return bool(node)


# Job names from .github/workflows/ci.yml — the contexts GitHub reports for
# Actions jobs. A stale context here would block every merge forever.
OUR_CONTEXTS = ["QA Pipeline", "ShellCheck (severity=warning)"]

# required_status_checks: preserve every existing context (both the legacy
# `contexts` list and the newer `checks` objects), then add ours.
existing_rsc = current.get("required_status_checks") or {}
existing_contexts = list(existing_rsc.get("contexts") or [])
for check in existing_rsc.get("checks") or []:
    ctx = check.get("context")
    if ctx and ctx not in existing_contexts:
        existing_contexts.append(ctx)
merged_contexts = list(existing_contexts)
for our_context in OUR_CONTEXTS:
    if our_context not in merged_contexts:
        merged_contexts.append(our_context)

# restrictions: preserve existing push restrictions, converting GET's object
# arrays into the login/slug arrays PUT expects.
existing_restrictions = current.get("restrictions")
if existing_restrictions:
    restrictions = {
        "users": [u.get("login") for u in existing_restrictions.get("users", []) if u.get("login")],
        "teams": [t.get("slug") for t in existing_restrictions.get("teams", []) if t.get("slug")],
        "apps": [a.get("slug") for a in existing_restrictions.get("apps", []) if a.get("slug")],
    }
else:
    restrictions = None

# required_pull_request_reviews: overlay our modeled keys, keep any others the
# repo already had; drop GET-only sub-objects that are invalid as PUT input.
existing_reviews = current.get("required_pull_request_reviews") or {}
reviews = dict(existing_reviews)
reviews.update({
    "dismiss_stale_reviews": True,
    "require_code_owner_reviews": existing_reviews.get("require_code_owner_reviews", False),
    "required_approving_review_count": int(approvals),
    "require_last_push_approval": existing_reviews.get("require_last_push_approval", False),
})
for get_only in ("url", "dismissal_restrictions", "bypass_pull_request_allowances"):
    reviews.pop(get_only, None)

payload = {
    "required_status_checks": {"strict": True, "contexts": merged_contexts},
    "enforce_admins": as_bool(enforce_admins),
    "required_pull_request_reviews": reviews,
    "restrictions": restrictions,
    "allow_force_pushes": False,
    "allow_deletions": False,
    "block_creations": enabled_of(current.get("block_creations")),
    "required_conversation_resolution": True,
    "lock_branch": enabled_of(current.get("lock_branch")),
    "allow_fork_syncing": enabled_of(current.get("allow_fork_syncing")),
    "required_signatures": as_bool(signed),
    "required_linear_history": enabled_of(current.get("required_linear_history")),
}

with open(payload_path, "w") as fh:
    json.dump(payload, fh, indent=2)

# Diff-style summary of what this run changes.
print("  Branch protection changes:")
added = [c for c in merged_contexts if c not in existing_contexts]
if added:
    print("    + status-check contexts: " + ", ".join(added))
if existing_contexts:
    print("    = preserved existing contexts: " + ", ".join(existing_contexts))
if restrictions:
    print("    = preserved existing push restrictions (users/teams/apps)")


def report_change(label, old, new):
    if old != new:
        print("    ~ {0}: {1} -> {2}".format(label, old, new))


report_change("enforce_admins", enabled_of(current.get("enforce_admins")), as_bool(enforce_admins))
report_change("required_signatures", enabled_of(current.get("required_signatures")), as_bool(signed))
report_change(
    "required_approving_review_count",
    existing_reviews.get("required_approving_review_count"),
    int(approvals),
)
PYTHON_MERGE

# PUT the merged payload. Use an explicit if/else (not A && B || C) so a failing
# success-branch cannot fall through to the failure message (SC2015).
if gh api \
        --method PUT \
        -H "Accept: application/vnd.github+json" \
        "/repos/${REPO}/branches/${BRANCH}/protection" \
        --input "$tmp_payload" > "$tmp_result" 2> "$tmp_err"; then
    echo -e "${GREEN}✓ Branch protection applied (merged over existing settings)${NC}"
else
    echo -e "${RED}✗ Failed to apply protection${NC}"
    # Surface the API error message. gh writes the response body to stdout
    # (captured in $tmp_result) and a summary line to stderr ($tmp_err).
    if command -v jq > /dev/null && jq -e . "$tmp_result" > /dev/null; then
        jq -r '.message // .errors[0].message // .' "$tmp_result"
    else
        cat "$tmp_err"
    fi
    exit 1
fi

# Configure repo settings. Merge commits only: squash and rebase merges rewrite
# the PR's commits, so `git branch -d` can never see the branch as merged.
# The response body is not interesting on success; on failure the API message
# is surfaced so a permissions problem is not mistaken for "unchanged".
if gh api \
    --method PATCH \
    "/repos/${REPO}" \
    --field delete_branch_on_merge=true \
    --field allow_squash_merge=false \
    --field allow_merge_commit=true \
    --field allow_rebase_merge=false > "$tmp_result" 2> "$tmp_err"; then
    echo -e "${GREEN}✓ Repository settings updated (merge commits only, delete branch on merge)${NC}"
else
    echo -e "${RED}✗ Repository settings not updated (admin rights needed):${NC}"
    cat "$tmp_err"
fi

echo -e "\n${GREEN}Done! Branch '${BRANCH}' is now protected.${NC}"
echo -e "\nTo remove protection: ${BLUE}gh api --method DELETE /repos/${REPO}/branches/${BRANCH}/protection${NC}"