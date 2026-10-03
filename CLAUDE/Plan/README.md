# Plan Index — php-qa-ci

Programme/work records for php-qa-ci. Each plan lives in a numbered folder
(`NNNNN-kebab-name/`) with a `PLAN.md`. Create new plans with the project-local
scaffolder — **always** use this, never the vendored daemon's copy:

```bash
CLAUDE/Plan/mkplan.bash "descriptive-kebab-name"
```

## Active Plans

- [00010: Defence Before Fix full conformance](00010-defence-before-fix-full-conformance/PLAN.md) - In Progress — empty both `known-gaps` lists in `composer.json`, planned against upstream's clause-by-clause register entry rather than our own (understated) declaration; identity, then resolution, then enforcement

- [00013: qa pipeline defect sweep](00013-qa-pipeline-defect-sweep/PLAN.md) - In Progress — a verified catalogue of the defects a day of heavy consumer use surfaced, then a fix per confirmed defect (lock contention, log retention, a reflowed managed block, `bash bin/qa`, per-file PHPStan on phar-tool configs, `bin/rule-doc` blind to project identifiers, Infection against our own advisory)

- [00015: release automation for consumers](00015-release-automation-for-consumers/PLAN.md) - In Progress — ship the changelog-driven release workflow as a template any project can adopt, semantic versioning by default (breaking moves the major), with php-qa-ci's locked major as an override in its own `qaConfig/qa.php`

## Completed Plans

- [00016: method 1.1.0 and the deferred-defect record](Completed/00016-method-1-1-0-deferred-defect-record/PLAN.md) - Complete — `qaConfig/defect-record.neon` records deferred defects and no-pattern conclusions, read and validated by the justification lane, listed by `bin/rules` and carried into the agent summary; the declaration states method 1.1.0 at both levels (delivered c2941a1)

- [00008: shellcheck lane vendored binary](Completed/00008-shellcheck-lane-vendored-binary/PLAN.md) - Complete — the `shellCheck` lane runs a vendored, pinned static ShellCheck over every git-tracked shell script, so a green `bin/qa` is a green branch; the duplicate CI job is gone and `php8.5` requires `QA Pipeline` alone (delivered b191a1b)

- [00014: changelog release automation](Completed/00014-changelog-release-automation/PLAN.md) - Complete — `CHANGELOG.md` is the only input to a release: the opt-in `changelog` lane fails unrecorded consumer-facing changes, and a green push to `php8.5` opens a release pull request whose merge publishes the release (85.1.0 shipped this way; merged ffa598e)

- [00012: agent mode terse stdout and per file reports](Completed/00012-agent-mode-terse-stdout-and-per-file-reports/PLAN.md) - Complete — `--agent-mode` / `PHPQACI_AGENT_MODE` makes stdout a count plus a report path, with the findings in per-file JSON under `var/qa/`, for PHPStan and PHPArkitect (merged 3e0c970)

- [00009: upstream php-src bug report](Completed/00009-upstream-php-src-bug-report-opcache-const-comparison/PLAN.md) - Complete — [php-src GH-23644](https://github.com/php/php-src/issues/23644) filed and Status: Verified, fix proposed in [PR 23648](https://github.com/php/php-src/pull/23648) carrying our reproducer; produced [CLAUDE/segfault-policy.md](../segfault-policy.md); `FIRST_FIXED` defended by `OpcacheDefectRangeTest` rather than awaited

- [00007: OPcache optimizer const-comparison crash](Completed/00007-opcache-optimizer-const-comparison-crash/PLAN.md) - Complete — PHP 8.5's `NO_CONST_CONST` omission leaves a const-const comparison the VM segfaults on; `opcache` lane, preflight advisory, recommended ini, and a test that fails when the declared range and the running PHP disagree

- [00011: docs self-history detector](Completed/00011-docs-self-history-detector/PLAN.md) - Complete — a Defence Before Fix execution against "a document that describes itself rather than its subject": the `docsProse` lane (`phpqaci.docsProse`) proven red on this repo's own README, committed red (24e2115), then the three instances fixed (d555f24)

- [00005: pipeline extensibility and tool coupling](Completed/00005-pipeline-extensibility-and-tool-coupling/PLAN.md) - Complete — PipelineBuilder (consumer tools and phases from qaConfig/pipeline.php), twig/yaml lanes, the qa skill as a shim, the ambiguousArrayDoc rule, and shipmonk/dead-code-detector shipped as the opt-in deadCode lane from its own PHAR

- [00001: Repo Audit & Tidy](Completed/00001-repo-audit-and-tidy/PLAN.md) - Complete — six audit and remediation waves on the Bash pipeline (psr4 gate restored, dead code and docs rot removed, consumer scripts and registry SSoT); remaining Bash-era findings superseded by Plan 00003

- [00006: PHAR-only tool delivery](Completed/00006-phar-only-tool-delivery/PLAN.md) - Complete — composer-normalize and parallel-lint via PHIVE, phpcpd and composer-dependency-analyser Box-built from build/<tool>/ manifests, a PHAR update path that really updates, the binDirTool rule (delivered d0a6573)

- [00004: QA ecosystem lanes](Completed/00004-qa-ecosystem-lanes/PLAN.md) - Complete — composer audit, deprecation rules, composer-dependency-analyser, type-coverage, phpcpd-next, twig-cs-fixer, the spaze deny-lists lifted into our own rules, and the variadicOverArrayParameter rule (delivered 085f595, `php8.5` released and made default)

- [00003: PHP pipeline rewrite](Completed/00003-php-pipeline-rewrite/PLAN.md) - Complete — Bash orchestration replaced by TDD PHP 8.5 under LTS\\PHPQA\\Pipeline; `bin/qa` is PHP, `qaConfig/qa.php` is the consumer contract (delivered 1b2c5c3)

- [00002: PHAR-vendored Rector](Completed/00002-phar-vendored-rector/PLAN.md) - Complete — Rector now ships as the committed `vendor-phar/rector.phar`; `tools/rector/` and `PhiveUpdatePlugin` deleted

## Cancelled Plans

_None yet — abandoned plans move to [Cancelled/](Cancelled/) with a matching row here._

## Legacy loose docs

[Completed/legacy-loose-docs/](Completed/legacy-loose-docs/) holds three documents that predate
the numbered plans: the Bash-era locking design (superseded by `RunLock` in Plan 00003), the
skills-deployment proposal (superseded by `scripts/deploy-skills.bash`), and the worktree
pre-commit fix (delivered in b82b293b). Historical only.
