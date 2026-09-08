---
name: defence-before-fix
description: |
  Defence Before Fix (DBF, method specification 1.0.1): when a defect is found, build and
  prove the detector rule for its whole class BEFORE fixing the instance, sweep, fix every
  instance, enforce as a blocking check, document behind a stable identifier, then fix the
  original defect with a test.

  Use when:
  - A bug, review finding or incident has been found and the class should never recur
  - "defence before fix", "DBF this", "create a PHPStan rule for this bug"
  - "detect this pattern with static analysis", "ratchet this bug class"
  - User wants to encode institutional knowledge as a rule

  This skill is a shim: it forces the canonical procedure to be read, then follows it.
allowed-tools: Read, Bash, Task, Skill, WebFetch
---

# Defence Before Fix

## Step 0: read the method first (mandatory, every invocation)

Read both, in this order, before doing anything else:

1. The vendored agent prompt, generated from the specification's Appendix A:
   `remote-docs/defence-before-fix.github.io/defence-before-fix-project-prompt.md`
   (in a consuming project: `vendor/lts/php-qa-ci/remote-docs/defence-before-fix.github.io/defence-before-fix-project-prompt.md`).
   If its `stale_after` date has passed and you are online, refresh it with
   `.claude/hooks-daemon/bin/hooks-daemon remote-docs refresh --all` and say so; if you are
   offline, use it as is and note the staleness in your report.
2. The canonical procedure for this toolchain, "The procedure: six clauses, in order" and
   "Authority" in `CLAUDE/DefenceBeforeFix.md`
   (in a consuming project: `vendor/lts/php-qa-ci/CLAUDE/DefenceBeforeFix.md`).

The specification (https://defence-before-fix.github.io/raw/SPEC.md) governs where anything
else differs. Do not proceed from memory of the method; the clauses carry MUSTs that are easy
to drop (two independent search techniques, red commit separate from the fix, count recorded
before fixing, no test as the detector).

## Step 1: follow the six clauses

Work through clauses 3.1 to 3.6 of the canonical procedure in order. Where the user's
request names a later starting point ("create a rule for this pattern", "fix and verify"),
confirm the earlier clauses' records exist before starting there; if they do not, start at
3.1.

Delegation available inside the clauses:

- 3.2, PHPStan rules: `Task` with `subagent_type: php-qa-ci_phpstan-rule-creator`. Give it
  the class, hazard sentence, wrong/right examples and the expected violation locations from
  3.1. It reads `qaConfig/PHPStan/CLAUDE.md` and `examples/` in this skill directory.
- 3.2, structural conventions: PHPArkitect (`qaConfig/phparkitect.php`), not PHPStan.
- 3.2, non-PHP artefacts (config, XML, YAML, workflows): a bespoke detector run as a
  `bin/qa` lane, in the shape of the existing lanes listed under "Pipeline lanes" in
  `docs/phpstan-rules/README.md`.
- 3.3 and 3.5, proving and enforcing: the `qa` skill or `Bash` running the project's own
  entry point (`bin/qa -t <alias>`, then `QA_READONLY=1 CI=true bin/qa`).
- 3.4, sweeping: `Bash` for the independent search techniques; the `phpstan-runner` skill
  for the rule's own run.

## Step 2: report

The report names, per clause, what the record shows (class and hazard; the two search
techniques; red commit; instance count and what was fixed by hand versus by pattern; the
entry-point run that showed the rule reported; the identifier and its documentation page)
and lists any decision escalated to the Owner with the count and cost. Never claim
conformance for a remediation that skipped a clause; say which clause and why.

## Examples

Example PHPStan rules for common patterns are in `examples/` beside this file.
