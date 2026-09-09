# Plan Index — php-qa-ci

Programme/work records for php-qa-ci. Each plan lives in a numbered folder
(`NNNNN-kebab-name/`) with a `PLAN.md`. Create new plans with the project-local
scaffolder — **always** use this, never the vendored daemon's copy:

```bash
CLAUDE/Plan/mkplan.bash "descriptive-kebab-name"
```

## Active Plans

- [00005: pipeline extensibility and tool coupling](00005-pipeline-extensibility-and-tool-coupling/PLAN.md) - In Progress — a PipelineBuilder so consumers can add tools and groups; dead-code-detector evaluated by dogfooding through it; twig/variadic work in flight on a feature branch
- [00001: Repo Audit & Tidy](00001-repo-audit-and-tidy/PLAN.md) - In Progress

## Completed Plans

- [00004: QA ecosystem lanes](Completed/00004-qa-ecosystem-lanes/PLAN.md) - Complete — composer audit, deprecation rules, composer-dependency-analyser, type-coverage, phpcpd-next, twig-cs-fixer, the spaze deny-lists lifted into our own rules, and the variadicOverArrayParameter rule (delivered 085f595, `php8.5` released and made default)
- [00003: PHP pipeline rewrite](Completed/00003-php-pipeline-rewrite/PLAN.md) - Complete — Bash orchestration replaced by TDD PHP 8.5 under LTS\PHPQA\Pipeline; `bin/qa` is PHP, `qaConfig/qa.php` is the consumer contract (delivered 1b2c5c3)

- [00002: PHAR-vendored Rector](Completed/00002-phar-vendored-rector/PLAN.md) - Complete — Rector now ships as the committed `vendor-phar/rector.phar`; `tools/rector/` and `PhiveUpdatePlugin` deleted

## Cancelled Plans

_None yet — abandoned plans move to [Cancelled/](Cancelled/) with a matching row here._

## Loose docs at plan root (pre-existing, flagged by plan-qa)

`locking-system.md`, `skills-deployment-system-2025-11.md`,
`worktree-git-env-fix.md` predate the plan-workflow being enabled. They are
knowledge docs, not plan folders; relocating them (into a plan folder or
`docs/`) is tracked as housekeeping — left in place for now to avoid breaking
inbound references without review.
