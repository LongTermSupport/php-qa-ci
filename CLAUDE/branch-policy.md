# Branch and PR Conventions

This document is the single source of truth for the `branchNamePolicy` rule
enforced by `bin/qa -t branchNamePolicy`.

## Canonical Rule

> A PR represents a new feature or a bug fix. It never represents a single plan.
> Plans represent atomic pieces of work. A feature or bug fix may be composed
> of zero or more plans.

## Why

Plans are an internal unit of work — typically scoped to a single, atomic
deliverable that takes anywhere from minutes to a few hours. They are tracked
in `CLAUDE/Plan/NNNN-description/PLAN.md` and land as one or more commits.

PRs are an **external** unit — what reviewers, CI, and downstream consumers see.
A PR should answer one question: *"what behaviour changed?"*. The answer is a
feature or a bug fix, never "we executed the steps in plan 00080".

Cutting a branch per plan and opening a PR on it produces:

- Misleadingly-named PRs (`plan/00080-zoho-description-html-live-integration-tests`)
- PR titles that describe internal process rather than user-visible behaviour
- Review fragmentation when a logical feature touches multiple plans
- A confusing PR queue where plan boundaries are visible to outsiders

The fix is to keep `plan/*` purely internal and to open PRs only from
feature/bugfix grain branches.

## Allowed Branch Prefixes for PRs

| Prefix     | When to use                                                                 |
| ---------- | --------------------------------------------------------------------------- |
| `feature/` | New behaviour, new endpoints, new capabilities                              |
| `bugfix/`  | Fixing a defect in existing behaviour                                       |
| `chore/`   | Dependency bumps, CI tweaks, doc-only changes, refactors with no API change |
| `hotfix/`  | Urgent fix that needs to land outside the normal cadence                    |

A repo may add additional prefixes via `qaConfig/branchNamePolicy.yaml` (see
"Extending the allow-list" below). Defaults are never removed.

## Disallowed for PRs

- `plan/*` — plans are internal; their commits land on a feature/bugfix branch
- Any other free-form prefix (e.g. `joe-experiments/`, `wip-stuff/`) unless
  explicitly added to the project allow-list

## Default-Branch Exemption

The repo's default branch (detected dynamically from `origin/HEAD`, or falling
back to common candidates like `main`, `master`, `develop`, `DumbItDown`,
`NewCheckout`) is always exempt — the policy fires only on non-default branches.

## Worked Example: Multi-Plan Feature

Suppose the team is shipping zoho desk support, broken into three plans:

```
CLAUDE/Plan/
  00077-zoho-desk-stub/
  00078-zoho-description-html-live-integration-tests/
  00079-zoho-attachments-upload/
```

The **right** way to ship this:

```bash
# 1. Cut a single feature branch from the default branch
git checkout DumbItDown
git pull
git checkout -b feature/zoho-desk-support

# 2. Work each plan as one or more commits ON that feature branch
#    Each plan's PLAN.md is committed alongside its implementation.
git commit -m "plan 00077: zoho desk stub"
git commit -m "plan 00078: zoho description HTML live tests"
git commit -m "plan 00079: zoho attachments upload"

# 3. Open ONE PR from feature/zoho-desk-support → DumbItDown
gh pr create --title "Zoho desk support" --body "..."
```

The **wrong** way (what this rule prevents):

```bash
# DO NOT do this
git checkout -b plan/00077-zoho-desk-stub
# ...work...
gh pr create   # ❌ PR title becomes "plan/00077-zoho-desk-stub"
```

If you discover mid-flight that a "plan" actually warrants its own PR (because
it is in fact a self-contained feature or bug fix), rename the branch:

```bash
git branch -m plan/00077-zoho-desk-stub feature/zoho-desk-stub
```

## Extending the Allow-List Per Project

Create `qaConfig/branchNamePolicy.yaml` in the consumer project:

```yaml
# qaConfig/branchNamePolicy.yaml
# Additive only — defaults (feature/, bugfix/, chore/, hotfix/) are never removed.

extra_allowed_prefixes:
  - release/
  - epic/

extra_exempt_branches:
  - integration
  - staging
```

Schema:

| Key                      | Type            | Effect                                             |
| ------------------------ | --------------- | -------------------------------------------------- |
| `extra_allowed_prefixes` | list of strings | Additional prefixes (trailing `/` is conventional) |
| `extra_exempt_branches`  | list of strings | Additional fully-qualified branch names to exempt  |

Both keys are optional. Unknown keys are ignored. The file is parsed by a
minimal pure-bash YAML reader — keep it to these two flat lists.

## Anti-Pattern: Per-Plan PRs

DO NOT do any of the following:

- Cut `plan/NNNN-*` and open a PR from it
- Rename a feature branch to `plan/*` to "track which plan it implements"
- Open multiple PRs (one per plan) when the plans together form one logical feature

If your `git log` on the PR branch reads like a series of plan completions,
that is normal and correct. The PR title still describes the **outcome**, not
the plans.

## Cross-Reference

The precedent that motivated formalising this rule:
`BallicomDev/checkout` plan `00080-customer-form-prefill-uses-stale-fields`, where
a `plan/*`-style branch was cut and opened as a PR before the convention was
written down. The PR title made it unclear what user-visible change was shipping.

## Enforcement

- Tool: `bin/qa -t branchNamePolicy`
- Runs as part of: `bin/qa -t allStatic` (CI gate)
- Single-source-of-truth doc: this file
- Project root signpost: auto-generated `<phpqaci>...</phpqaci>` block in
  `CLAUDE.md` (written on every `composer install`/`update`)

If `branchNamePolicy` fires unexpectedly, the message printed to stderr links
back here.
