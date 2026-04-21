# Plan: Fix pre-commit hook for git worktree support

## Problem

The `pre-commit-check-vendor-uncommitted` hook fails in git worktrees. When a
commit is made from a worktree, git exports `GIT_DIR` (and potentially
`GIT_WORK_TREE`, `GIT_INDEX_FILE`) into the hook's environment. These variables
point to the worktree's git metadata, not to vendor sub-repos.

When the hook `cd`s into a vendor directory and runs `git status --porcelain`,
git ignores the vendor's own `.git` directory and instead uses the inherited
`GIT_DIR`. This causes git to compare the vendor directory against the
*parent project's* index, producing thousands of spurious "deleted" entries
and blocking the commit.

### Reproduction

```bash
# From a worktree:
export GIT_DIR="/workspace/.git/worktrees/worktree-child-00024-contact-sections"
cd /workspace/vendor/lts/php-qa-ci
git status --porcelain | wc -l
# => 2984 (all wrong — the vendor repo is actually clean)

# After unsetting:
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE
git status --porcelain | wc -l
# => 0 (correct)
```

## Fix

In `git-hooks/pre-commit-check-vendor-uncommitted`, unset `GIT_DIR`,
`GIT_WORK_TREE`, and `GIT_INDEX_FILE` before entering the vendor-scanning loop.
These variables are only meaningful for the *parent* repository's commit
operation; vendor sub-repos must discover their own `.git` directory naturally.

The `PROJECT_ROOT` is captured from `git rev-parse --show-toplevel` *before*
the unset, so it correctly resolves even in worktree context. The `composer.lock`
parsing also happens before the unset and doesn't use git at all.

### Variables to unset

| Variable         | Why it breaks vendor checks                            |
| ---------------- | ------------------------------------------------------ |
| `GIT_DIR`        | Forces git to use parent's git dir instead of vendor's |
| `GIT_WORK_TREE`  | Overrides the working tree, misaligning status checks  |
| `GIT_INDEX_FILE` | Points to parent's index, not vendor's                 |

### Placement

Unset immediately before the `while` loop that iterates vendor `.git`
directories (line 77). This is after `PROJECT_ROOT` and `COMPOSER_COMMITS`
are already captured, so those values are unaffected.

## Success criteria

1. Hook passes in a normal (non-worktree) repo with clean vendors
2. Hook passes in a worktree context with clean vendors
3. Hook still correctly detects real uncommitted changes in vendor repos
4. Hook still correctly detects composer.lock out-of-sync conditions
5. `PROJECT_ROOT` resolution is unaffected (captured before unset)
