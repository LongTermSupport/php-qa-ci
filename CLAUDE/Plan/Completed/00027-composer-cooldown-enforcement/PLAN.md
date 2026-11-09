# Plan 00027: composer cooldown enforcement

**Status**: Complete
**Created**: 2026-11-02
**Owner**: joseph
**Priority**: Medium

## Overview

Issue #382 asks for a pipeline check that a project which relies on Composer 2.11's
`config.policy.cooldown` actually gets the protection. The first half of #382 (the
`dependencyFreshness` lane follows the cooldown) is released. This plan is the second half: the
enforcement check.

The check is an assertion inside the existing `composerChecks` lane, not a new tool: it reads
`composer.json` and the installed Composer, the same input that lane already owns
([tool-boundaries](../../tool-boundaries.md)). It prints findings first and fails later, behind a
setting, so adopting it does not turn every consumer red at once.

## Goals

- Report when the installed Composer is older than 2.11, when `config.policy.cooldown.period` is
  unset or zero, and when the project's own vendor is missing from `config.policy.cooldown.ignore`.
- Report a cooldown switched off in the file: `policy: false`, `cooldown: false`, `block: false`.
- Treat an `ignore` entry for a package outside an internal vendor as a security override: one exact
  package with a `SECURITY` reason, listed on every run, a finding when malformed, a glob, narrowed or
  expired.
- Report-only by default; a setting makes the findings fail the lane. A project that opts out gives
  a reason, and the reason is printed.
- Documented on the `composerChecks` page, with tests that fail without the check.

## Non-Goals

- No `--no-cooldown` handling: the freshness lane never passes it, and a flag typed on one command
  is not visible to a file check.
- No environment-variable route (`COMPOSER_POLICY_COOLDOWN_*` set in CI or an image): that is
  checked where the environment is gathered, not in `composer.json`.

## Tasks

### Phase 1: the checks that are in force

- [x] ✅ **Task 1.1**: tests first, then the cooldown assertion in the `composerChecks` lane
- [x] ✅ **Task 1.2**: the report-only / failing setting and the printed opt-out reason
- [x] ✅ **Task 1.6**: review fixes: an unreadable `composer.json` follows the staging (named, never
  fails a report-only lane, never masks the audit, never read under the opt-out); an override list
  chooses the first rule with a usable reason; a malformed root package name is skipped
- [x] ✅ **Task 1.3**: the `composerChecks` docs page, the changelog entry, the QA run
- [x] ✅ **Task 1.4**: the security-release exception: every `ignore` entry outside an internal vendor
  is one exact package with a `SECURITY <id>: fixed in <version>; PR <url>; remove after <date>`
  reason, listed on every run, a finding when malformed, a glob or expired
- [x] ✅ **Task 1.5**: further internal vendors beyond the root package's, each needing a `<vendor>/*`
  exemption with a reason

### Phase 2: moved to issue #394 for triage (not built under this plan)

- [x] ✅ **Task 2.1**: nested `composer.json` files: filed as #394, outside the approved scope of #382
- [x] ✅ **Task 2.2**: a minimum `period`: filed as #394 with the same reason

## Success Criteria

- [x] A project with a correct cooldown passes; each failure above is reported by name.
- [x] Report-only leaves the exit status unchanged; the setting makes it fail.
- [x] `QA_READONLY=1 bin/qa` is clean on the branch.

## Delivery & Milestones

- Phase 1 delivery commit: 3ef601da (pull request #393).
- Follow-up work, not part of this plan: #394.
