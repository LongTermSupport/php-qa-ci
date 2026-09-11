# Plan Index — php-qa-ci

Programme/work records for php-qa-ci. Each plan lives in a numbered folder
(`NNNNN-kebab-name/`) with a `PLAN.md`. Create new plans with the project-local
scaffolder — **always** use this, never the vendored daemon's copy:

```bash
CLAUDE/Plan/mkplan.bash "descriptive-kebab-name"
```

## Active Plans

- [00007: OPcache optimizer const-comparison crash](00007-opcache-optimizer-const-comparison-crash/PLAN.md) - In Progress — PHP 8.5.10's DFA pass leaves a const-const comparison unfolded and the VM segfaults; detector lane, preflight warning, recommended ini

- [00008: shellcheck lane vendored binary](00008-shellcheck-lane-vendored-binary/PLAN.md) - In Progress — ShellCheck runs only in CI, so a green bin/qa can still be a red branch; vendor one pinned static binary, add the lane with git-tracked shebang discovery and a per-project glob override, delete the duplicate CI job

- [00009: upstream php-src bug report](00009-upstream-php-src-bug-report-opcache-const-comparison/PLAN.md) - In Progress — [php-src GH-23644](https://github.com/php/php-src/issues/23644) filed and Status: Verified, fix proposed in [PR 23648](https://github.com/php/php-src/pull/23648); produced [CLAUDE/segfault-policy.md](../segfault-policy.md); only FIRST_FIXED remains, blocked on a release

- [00010: Defence Before Fix full conformance](00010-defence-before-fix-full-conformance/PLAN.md) - In Progress — empty both `known-gaps` lists in `composer.json`, planned against upstream's clause-by-clause register entry rather than our own (understated) declaration; identity, then resolution, then enforcement

## Completed Plans

- [00005: pipeline extensibility and tool coupling](Completed/00005-pipeline-extensibility-and-tool-coupling/PLAN.md) - Complete — PipelineBuilder (consumer tools and phases from qaConfig/pipeline.php), twig/yaml lanes, the qa skill as a shim, the ambiguousArrayDoc rule, and shipmonk/dead-code-detector shipped as the opt-in deadCode lane from its own PHAR

- [00001: Repo Audit & Tidy](Completed/00001-repo-audit-and-tidy/PLAN.md) - Complete — six audit and remediation waves on the Bash pipeline (psr4 gate restored, dead code and docs rot removed, consumer scripts and registry SSoT); remaining Bash-era findings superseded by Plan 00003

- [00006: PHAR-only tool delivery](Completed/00006-phar-only-tool-delivery/PLAN.md) - Complete — composer-normalize and parallel-lint via PHIVE, phpcpd and composer-dependency-analyser Box-built from build/<tool>/ manifests, a PHAR update path that really updates, the binDirTool rule (delivered d0a6573)

- [00004: QA ecosystem lanes](Completed/00004-qa-ecosystem-lanes/PLAN.md) - Complete — composer audit, deprecation rules, composer-dependency-analyser, type-coverage, phpcpd-next, twig-cs-fixer, the spaze deny-lists lifted into our own rules, and the variadicOverArrayParameter rule (delivered 085f595, `php8.5` released and made default)

- [00003: PHP pipeline rewrite](Completed/00003-php-pipeline-rewrite/PLAN.md) - Complete — Bash orchestration replaced by TDD PHP 8.5 under LTS\\PHPQA\\Pipeline; `bin/qa` is PHP, `qaConfig/qa.php` is the consumer contract (delivered 1b2c5c3)

- [00002: PHAR-vendored Rector](Completed/00002-phar-vendored-rector/PLAN.md) - Complete — Rector now ships as the committed `vendor-phar/rector.phar`; `tools/rector/` and `PhiveUpdatePlugin` deleted

## Cancelled Plans

_None yet — abandoned plans move to [Cancelled/](Cancelled/) with a matching row here._

## Loose docs at plan root (pre-existing, flagged by plan-qa)

`locking-system.md`, `skills-deployment-system-2025-11.md`,
`worktree-git-env-fix.md` predate the plan-workflow being enabled. They are
knowledge docs, not plan folders; relocating them (into a plan folder or
`docs/`) is tracked as housekeeping — left in place for now to avoid breaking
inbound references without review.
