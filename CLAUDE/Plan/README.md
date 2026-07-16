# Plan Index — php-qa-ci

Programme/work records for php-qa-ci. Each plan lives in a numbered folder
(`NNNNN-kebab-name/`) with a `PLAN.md`. Create new plans with the project-local
scaffolder — **always** use this, never the vendored daemon's copy:

```bash
CLAUDE/Plan/mkplan.bash "descriptive-kebab-name"
```

## Active Plans

- [00001: Repo Audit & Tidy](00001-repo-audit-and-tidy/PLAN.md) - In Progress
- [00002: PHAR-vendored Rector](00002-phar-vendored-rector/PLAN.md) - Draft (research + planning; fable review loop)

## Completed Plans

_None yet — finished plans move to [Completed/](Completed/) with a matching row here._

## Cancelled Plans

_None yet — abandoned plans move to [Cancelled/](Cancelled/) with a matching row here._

## Loose docs at plan root (pre-existing, flagged by plan-qa)

`locking-system.md`, `skills-deployment-system-2025-11.md`,
`worktree-git-env-fix.md` predate the plan-workflow being enabled. They are
knowledge docs, not plan folders; relocating them (into a plan folder or
`docs/`) is tracked as housekeeping — left in place for now to avoid breaking
inbound references without review.
