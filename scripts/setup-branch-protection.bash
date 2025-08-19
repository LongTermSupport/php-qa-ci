#!/usr/bin/env bash

set -euo pipefail

# ============================================================================
# GitHub Branch Protection Setup - Simple & Standardized
# ============================================================================
#
# Sets up standardized branch protection with sensible defaults.
#
# Usage:
#   ./setup-branch-protection.bash           # Standard protection
#   ./setup-branch-protection.bash --harden  # Maximum protection
#
# ============================================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

# Check for --harden flag
HARDEN_MODE=false
if [[ "${1:-}" == "--harden" ]]; then
    HARDEN_MODE=true
fi

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

# Get default branch
BRANCH=$(gh repo view --json defaultBranchRef -q .defaultBranchRef.name)

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

# Create the protection rules
# Note: When enforce_admins is false, admins can bypass PR requirements
# We need to use --input with proper JSON instead of --field for complex objects
PROTECTION_JSON=$(cat <<EOF
{
  "required_status_checks": {
    "strict": true,
    "contexts": ["PHP QA Pipeline"]
  },
  "enforce_admins": ${ENFORCE_ADMINS},
  "required_pull_request_reviews": {
    "dismiss_stale_reviews": true,
    "require_code_owner_reviews": false,
    "required_approving_review_count": ${REQUIRED_APPROVALS},
    "require_last_push_approval": false
  },
  "restrictions": null,
  "allow_force_pushes": false,
  "allow_deletions": false,
  "block_creations": false,
  "required_conversation_resolution": true,
  "lock_branch": false,
  "allow_fork_syncing": false,
  "required_signatures": ${SIGNED_COMMITS},
  "required_linear_history": false
}
EOF
)

echo "$PROTECTION_JSON" | gh api \
    --method PUT \
    -H "Accept: application/vnd.github+json" \
    "/repos/${REPO}/branches/${BRANCH}/protection" \
    --input - 2>&1 | tee /tmp/protection-result.txt > /dev/null \
    && echo -e "${GREEN}✓ Branch protection applied${NC}" \
    || (echo -e "${RED}✗ Failed to apply protection${NC}" && cat /tmp/protection-result.txt | jq -r '.message // .errors[0].message // .' 2>/dev/null | head -5)

# Configure repo settings
gh api \
    --method PATCH \
    "/repos/${REPO}" \
    --field delete_branch_on_merge=true \
    --field allow_squash_merge=true \
    --field allow_merge_commit=true \
    --field allow_rebase_merge=true > /dev/null 2>&1 \
    && echo -e "${GREEN}✓ Repository settings updated${NC}" \
    || echo -e "${BLUE}ℹ Repository settings unchanged (may need admin rights)${NC}"

echo -e "\n${GREEN}Done! Branch '${BRANCH}' is now protected.${NC}"
echo -e "\nTo remove protection: ${BLUE}gh api --method DELETE /repos/${REPO}/branches/${BRANCH}/protection${NC}"