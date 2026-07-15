# Risk & Verification — v2 (SUPERSEDES v1)

User ruling 2026-07-15 (verbatim intent): breaking consumer CI for **valid**
failures is the reason this repo exists — it is not a risk. No warn→enforce
staging. Do the job properly, simply.

## What changes from v1

- **R0 is DELETED.** Restored gates enforce immediately. A consumer whose CI
  goes red because psr4/strict-types violations accumulated while the gate was
  dead has real issues; the gate reporting them is the product working.
- **Q-1 (release model) is MOOT** — no staging to schedule.
- **Q-5 (warn window) is DELETED.**
- The v1 sequencing DAG's "gates WARN → … → gates ENFORCE" collapses to
  "restore gates (enforcing) first".

## Decisions (resolved by coordinator under the ruling — no user gate)

- **Q-2 annotations gate: RETIRE.** Fragment 100% commented for years, nobody
  missed it, PHPUnit 10+ is attributes-era. Delete fragment, bin stub, src
  class, options entries, docs mentions.
- **Q-3 dead per-tool lock/timing hooks: DELETE.** ~100 lines never called.
  Git history preserves them; reintroduce only if a future driver needs them.
  Fix the live full-run timing key bug (M-023 tail) if trivially provable,
  else delete the broken recording path too.
- **Q-4 skills/agents ownership: php-qa-ci-owned.** Deploy overwrites freely;
  document "never hand-edit deployed copies; override via your own files".
- **Q-6 phpUnitCoverage default: code (1) is truth.** Fix the docs.

## What stays from v1 (still binding)

- **Test-first for behavioural change**: restored gates land WITH tests that
  prove they fire (gate-liveness). M-028 harness work is part of wave 1, not
  an afterthought — this is the class of bug that caused the rot.
- **Contract preservation**: consumer override surface (qaConfig fragments,
  env vars, -t names/aliases, exit-code meanings) unchanged unless a finding
  says the current behaviour is itself the bug.
- **Per-item re-verification at implementation time**: implementer re-reads
  code before changing it; AGENT-EVIDENCED rows are not gospel.
- Risks R1–R5, R7, R8 and verification V1–V6 stand, minus any warn-mode
  language.

## Execution waves (implementation order)

1. **W1 Correctness** — restore/fix/retire the silent gates (M-001, M-002,
   M-003+M-004), M-012, M-024, M-013, M-014, M-009 ordering fix; each with a
   test where the harness pattern supports it.
2. **W2 Dead code & hygiene** — M-021, M-022, M-023, M-045, M-046 deletions;
   quoting/eval/shellcheck sweep (M-025, M-026, M-048…, M-070); shellcheck
   gate in own CI.
3. **W3 Docs rot** — docs-rot-fixes plan, updated for resolved decisions
   (no warn-mode text; annotations gate documented as removed).
4. **W4 Consumer scripts** — remaining M-015..M-020, M-063..M-073 per
   consumer-scripts plan.
5. **W5 Structural** — registry SSoT (M-011) and shared driver (M-010) with
   characterisation tests first; only after W1–W4 are green.
