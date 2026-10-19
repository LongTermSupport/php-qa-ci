# Plan 00022: binding clauses the floor must keep (seed)

Seed list for the `FLOOR` rows of `FACT-MAP.md` (Task 3.5). Each clause is a warning that must
stay resident in root `CLAUDE.md`, inside the floor bullet for its ruling, after condensation.
The procedure stays in the canon; the clause does not. Task 6.4's reviewer checks every row
against the rewritten file. Rows may be added during Task 3.5 when the per-fact check finds
another binding clause; none is removed without an Owner decision.

Source: the plan review, assessment item 4
([subagent-reports/261019-plan-review-opus.md](subagent-reports/261019-plan-review-opus.md)).

| ID  | Ruling              | Clause that must survive condensation                                                                                                             | Canon                                                                                  |
| --- | ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| F01 | Knowledge & memory  | Never write to the harness memory store; knowledge goes in `CLAUDE/*.md`, work records in `CLAUDE/Plan/`                                          | root `CLAUDE.md` (canonical here)                                                      |
| F02 | Defence Before Fix  | Found a defect: build the detector for its class before fixing it                                                                                 | `CLAUDE/DefenceBeforeFix.md`                                                           |
| F03 | Defence Before Fix  | A new check needs a stable identifier, a page, and an entry in the identifier index `docs/phpstan-rules/README.md`                                | `CLAUDE/DefenceBeforeFix.md`, `CLAUDE/tool-boundaries.md`                              |
| F04 | Defence Before Fix  | A suppression, a baseline or a narrowing is an Owner decision, never a silent edit                                                                | `CLAUDE/DefenceBeforeFix.md`                                                           |
| F05 | Segfaults           | Any segfault is a halt, even under a command that reported success: exit 139 or a kernel-log `segfault at` line                                   | `CLAUDE/segfault-policy.md`                                                            |
| F06 | Releases            | Never choose a version or cut a tag by hand; a consumer-visible change carries an `## Unreleased` entry or a `Changelog: none — <reason>` trailer | `CLAUDE/releases.md`                                                                   |
| F07 | Tools stay current  | At the start **and end** of every session, check the update PR, `update-deps-failure` and every open issue with no reply                          | `CLAUDE/tool-currency.md`                                                              |
| F08 | Tools stay current  | A green update PR merges only after a **fresh** sub-agent's verification (standing authorisation)                                                 | `CLAUDE/tool-currency.md`                                                              |
| F09 | Issue loop          | Approved authors are listed once, in `.claude/hooks-daemon.yaml`                                                                                  | `CLAUDE/issue-sdlc.md`                                                                 |
| F10 | Release lines       | Work on `php8.5`; `php8.4` takes a fix only when a `php8.4` project reports it, #36 the single exception; `php8.3` is dead                        | `CLAUDE/release-lines.md`                                                              |
| F11 | Release lines       | An issue states the release line it was found on                                                                                                  | `CLAUDE/release-lines.md`                                                              |
| F12 | Pre-push gate       | The pre-push battery is mandatory; pushing `php8.5` deploys to production; an agent-merged PR needs a separate verifying sub-agent                | `CLAUDE/prepush-verification.md`, `CLAUDE/pr-verification.md`                          |
| F13 | CI is not read-only | The working default is writable; reproduce a CI failure with `QA_READONLY=1`, not `CI=true`                                                       | `CLAUDE/prepush-verification.md` (the "not `CI=true`" half is added there by Task 3.5) |
