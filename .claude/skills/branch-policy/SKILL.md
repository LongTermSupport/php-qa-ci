---
name: branch-policy
description: |
  Branch and PR naming convention enforced by php-qa-ci's branchNamePolicy tool.

  Use when:
  - "what branches are allowed for PRs?"
  - "why did branchNamePolicy fail?"
  - "can I open a PR from plan/00080-foo?"
  - "how do I extend the branch allow-list for this project?"
  - User is on a plan/* branch and wants to know how to proceed

  This skill answers convention questions and points at the authoritative
  doc in vendor/lts/php-qa-ci/CLAUDE/branch-policy.md.
allowed-tools: Read, Bash
---

# Branch and PR Conventions (php-qa-ci)

## The Rule

A PR represents a **new feature** or a **bug fix**. It never represents a single plan.
Plans are atomic pieces of work; a feature/bugfix branch may carry zero or more plans.

## Allowed PR Branch Prefixes

- `feature/` — new behaviour, new endpoints, new capabilities
- `bugfix/` — fixing a defect in existing behaviour
- `chore/` — dependency bumps, CI tweaks, doc-only changes, refactors
- `hotfix/` — urgent fix outside the normal cadence

A project may add extra prefixes via `qaConfig/branchNamePolicy.yaml`.

## Disallowed

- `plan/*` — plans are internal; they land as commits on a feature/bugfix branch
- Any free-form prefix not in the project allow-list

## If `branchNamePolicy` Fired

1. **Check your current branch**: `git rev-parse --abbrev-ref HEAD`
2. **If on `plan/*`**: rename or move work onto a feature branch:
   ```bash
   # Option A: rename the branch (simplest)
   git branch -m plan/NNNN-foo feature/short-feature-name

   # Option B: cut a fresh feature branch and cherry-pick
   git checkout -b feature/short-feature-name <base-branch>
   git cherry-pick <commit-from-plan-branch>
   ```
3. **If on a free-form prefix**: either rename to one of the allowed prefixes,
   or (if your project legitimately needs the prefix) add it to
   `qaConfig/branchNamePolicy.yaml`:
   ```yaml
   extra_allowed_prefixes:
     - release/
   ```

## Authoritative Documentation

The single source of truth lives at:

```
vendor/lts/php-qa-ci/CLAUDE/branch-policy.md
```

Read that file for the full convention, worked examples, anti-patterns, and
config schema.

## Quick Validation

Run the policy check manually:

```bash
bin/qa -t branchNamePolicy
```

Exit codes:

- `0` — PASS (exempt or matches allowed prefix)
- `1` — FAIL (disallowed branch; loud `plan/*` guidance printed when applicable)
