# Risk Register & Verification Strategy — v1

Author: Fable (coordinator), 2026-07-15. Scope: how the whole remediation
programme proves "nothing broke" for a library consumed by multiple projects.
This document governs the other three fix plans; where they conflict with it,
this one wins until revised.

## The one risk that dominates everything

**R0 — Waking the dead gates punishes consumers.** Three quality gates have
been silently passing for months/years (M-001 psr4 since 2023-12, M-002
annotations, M-003 strict-types-on-.php). Restoring them is NOT a no-op fix:
every consumer project has had months to accumulate violations those gates
would now catch. A naive restore turns "tidy up the library" into "break every
consumer's CI on next composer update".

**Policy (binding on fix plans): restored gates ship in a staged rollout.**
1. Release N: gate runs in WARN mode — reports violations loudly, exits 0,
   banner explains the gate was dead, what it found, and the enforcement date/
   release.
2. Release N+1 (after consumers have had a remediation window): gate enforces.
   Where a gate supports an ignore-list (psr4 already has one), document a
   baseline workflow so a consumer can adopt enforcement immediately with
   grandfathered exclusions they burn down.
This policy applies to ANY fix that strengthens a check consumers currently
pass vacuously (also: M-024 packageType aggregate-abort fix, M-004 strict-types
CI behaviour, M-027 read-only extension if it flips currently-green runs red).

## Risk register

| ID | Risk | Likelihood | Impact | Mitigation | Owned by |
|---|---|---|---|---|---|
| R0 | Restored gates fail consumer CI estate-wide | CERTAIN if naive | HIGH | Staged warn→enforce rollout (above) | bash-refactor plan |
| R1 | Consumer override contract broken by refactor (qaConfig fragments, env vars, -t aliases, exit codes, cascade order) | MEDIUM | HIGH | Contract inventory doc + characterisation tests BEFORE driver work (WP-B0); one-tool-at-a-time migration; legacy path until last tool moves | bash-refactor plan |
| R2 | deploy-skills change bricks consumer .claude/ mid-composer-update | LOW-MED | HIGH (unattended, many repos) | Fixture-consumer idempotency + interrupt-simulation tests before any deploy-skills change beyond the M-014 one-line gate fix | consumer-scripts plan |
| R3 | Driver refactor silently changes retry/exit-code semantics some consumer's CI depends on | MEDIUM | MEDIUM | Characterisation tests pin per-tool exit codes incl. crash paths; release slicing with consumable intermediate releases | bash-refactor plan |
| R4 | Docs rewrite introduces NEW inaccuracies (rot 2.0) | MEDIUM | MEDIUM | Every corrected claim carries a code evidence pointer from the register; post-fix re-audit loop (docs-rot-*-2.md); mdlinks + docs-conflict-checker in verification | docs plan |
| R5 | Shellcheck gate on own repo fails existing code, blocking unrelated work | HIGH | LOW | Baseline/burn-down approach: gate new findings only at first, ratchet down | bash-refactor plan |
| R6 | Consumers track the php8.4 BRANCH (not tags) → every merged fix ships instantly, no staging possible | UNKNOWN | HIGH if true | **OPEN QUESTION for user** (Q-1 below). If branch-tracked: create a release discipline (tags or stable branch) BEFORE landing behavioural WPs | user + coordinator |
| R7 | The repo's own qa pipeline (self-test) doesn't exercise the changed paths | MEDIUM | MEDIUM | Add the WP-B0 harness to this repo's CI; gate-liveness self-check (C3) covers the fragment class | bash-refactor plan |
| R8 | Plan-phase findings wrong in detail (AGENT-EVIDENCED rows never Fable-verified) | LOW-MED | LOW | Per-item re-verification is MANDATORY at implementation time: the implementing agent re-reads the code before changing it; SPECULATIVE rows (M-081..M-083) resolved before or during their WP | all plans |

## Verification strategy (programme-wide)

V1. **Contract inventory first.** A single doc enumerating the public consumer
    contract: every env var, -t name/alias, qaConfig file sourced, hook point,
    exit-code meaning, output artefact path (var/qa/**). This is the checklist
    every WP's "backward compatible?" claim is audited against. Source it from
    code (options.inc.bash, setConfig, tool fragments), not from docs (docs are
    the thing being fixed).
V2. **Characterisation harness before behavioural change** (WP-B0): pins boot
    sequence, cascade ordering, per-tool invocation (the binary actually runs —
    gate-liveness), retry/CI/read-only/aggregate behaviours, exit codes.
V3. **Canary consumers.** Before each behavioural release: run the full qa
    pipeline of at least one real consumer project against the candidate
    (composer path repo or branch alias). Automatable later; manual first.
V4. **Release slicing.** Behavioural WPs ship in small consumable releases:
    harness+hygiene → warn-mode gates → config ordering → driver migration
    (rolling) → enforce-mode gates. No big-bang release.
V5. **Audit loops.** (a) Plans: each fix plan gets an adversarial review pass
    before approval (T3.5, verify agents) producing -2.md revisions. (b)
    Implementations: post-fix re-audit of the touched axis (audits/*-2.md) —
    the versioned-file convention exists precisely for this.
V6. **Docs regression guard.** After M-011 (registry SSoT) lands, generate the
    tool table in docs from the registry; until then the docs plan's claim-
    evidence pairs are the guard.

## Cross-plan sequencing (top level)

```
   [Q-1 release-model decision]──────────────┐
                                             v
docs: WP pure-truth corrections ──────────► ship anytime (no code dep)
bash: WP-B0 harness+shellcheck ─► WP-B1 gates(WARN) ─► WP-B2 ordering ─► WP-B3 driver (rolling) ─► gates ENFORCE
scripts: quick wins (M-013,M-021,hygiene) ─► ownership model+tests ─► M-014 gate fix* ─► deploy-skills decomposition
docs: code-decision-dependent WPs ─────────► after M-002/M-023/M-041 decisions land
(*M-014 one-line fix may ship with quick wins if its test exists)
```

## Consolidated decision list for the user (Q-numbers; fix plans may add more)

- **Q-1** How do consumer projects pin php-qa-ci — branch (dev-php8.4) or
  tags? Determines whether release discipline must be created before any
  behavioural change ships. (R6)
- **Q-2** M-002 PHPUnit annotations gate: restore modernised, or formally
  retire (delete fragment+bin+src class+docs+options entries)? Coordinator
  lean: retire — PHPUnit 10+ attributes era makes annotation-checking legacy;
  await bash-fixplan's analysis before deciding.
- **Q-3** M-023 per-tool timing/lock hooks: wire them into the driver (they
  were designed for exactly that) or delete? Coordinator lean: wire during
  WP-B3 driver migration — the driver finally gives them a single call site.
- **Q-4** M-015 ownership: declare skills/agents php-qa-ci-owned (overwrite
  freely, document "never hand-edit") vs signature-protect? Await
  scripts-fixplan recommendation.
- **Q-5** Warn-window length for R0 staged rollout (one release? a date?).
- **Q-6** M-041: intended phpUnitCoverage default — is 1 (current code) right,
  or was 0 (documented) the intent? Determines code-fix vs doc-fix.

## Out of scope
- PHP src/ improvements beyond what the register lists (M-077 test gaps are
  PROCESS items scheduled inside WP-B0's harness work, not a new audit axis).
- Any new features. This programme is tidy-up only.
