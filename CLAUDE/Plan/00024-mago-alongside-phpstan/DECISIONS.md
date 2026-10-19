# Plan 00024 — Facts, recommendations and Owner decisions

Evidence: [subagent-reports/261019-mago-research.md](subagent-reports/261019-mago-research.md)
(Mago 1.57.0 measured against this repository; raw artefacts under
`untracked/scratch/mago-research/`). Section references below are to that report. Each item is
a recommendation the facts support, then the decision the Owner owns; the rulings made are
recorded under Owner rulings, the rest stay open.

## What the facts say

| Question              | Fact                                                                                                                                                                                       | Report  |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------- |
| Speed                 | Project lint 0.07–0.10s, project analyse 0.3–1.0s, one-file lint ~15ms, one-file analyse 0.3–0.8s. PHPStan 14.6s cold / 1.3s warm (project), 1.2–1.9s warm (one file). No Mago cache.      | §5      |
| Capability            | Analyser caught all 8 planted annotation errors with no false positives; on PHPStan-clean code it reports **more**: 485 analyser, 1,598 lint findings with defaults.                       | §3b     |
| Linter agreement      | Config alone gets the linter to 8 findings (5 `single-class-per-file`, 2 `@`, 1 `no-duplicate-match-arm`). Main lever: `assertion-style = self` (372 warnings, Rector conflict C2).        | §3b     |
| Analyser agreement    | 211 disagreements (148 errors) remain with the aligned config; most are fixable in a form both accept; one true contradiction (C1); the 211 are not fully classified.                      | §3b     |
| Formatter             | Cannot coexist with CS-Fixer: rewrites 554 files default, 353 tuned; formatter instability bug #2355 open.                                                                                 | §3b C3  |
| Suppression           | `@mago-ignore` / `@mago-expect` are unguarded by `phpqaci.inlinePhpstanIgnore` and by the daemon's suppression strategy.                                                                   | §3b C14 |
| DBF rule portability  | ~8 of 36 `phpqaci.*` rules have a native Mago counterpart, all syntactic; 28 need scope/reflection; extension API dates from 1.47 (August 2026), PHP workers only, needs the Composer pkg. | §3a, §4 |
| Distribution          | 31MB static musl binary, sha256 digest and a GitHub attestation per release; Composer package downloads with no hash check; cannot read PHARs.                                             | §1      |
| Daemon `lint_on_edit` | Two command slots per language, both filled for php-qa-ci consumers; no shell; 15-second per-command limit; timeout or missing tool lets the edit through.                                 | §6      |
| Maturity              | One dominant maintainer; 30 releases in the sampled window; breaking 2.0 rework (#1931) open.                                                                                              | §1      |

The Owner's assumption "Mago is less capable than PHPStan" is only partly borne out: on the
measured probes the analyser is at least as sensitive, and the problem is the opposite one —
it disagrees by reporting more.

## Recommendations (the facts support these; each is still an Owner decision below)

1. **Formatter out of scope.** Two whole-file formatters cannot own the same files, and
   replacing CS-Fixer drops its risky and semantic fixers (C3, §8C).
2. **DBF rules stay PHPStan-owned.** 28/36 need scope and reflection; `bin/rules`, `rule-doc`,
   the identifiers and the justification lane are PHPStan-only; the extension API is young and
   needs the unverified-download Composer package (§4, §8D).
3. **One owner per convention for the ~8 overlaps, and that owner is PHPStan.** Mago's
   equivalent rules (`no-empty`, `identity-comparison`, `no-nested-ternary`,
   `no-empty-catch-clause`, `strict-types`, `no-debug-symbols`, `no-eval` and related,
   `disallowed-type-instantiation`, `sensitive-parameter`, `require-api-or-internal`) are OFF.
   Weighing the edit-time goal: PHPStan already runs per file on edit (1.2–1.9s warm), so a
   Mago mirror of a syntactic rule saves about a second on a failing edit and costs a second
   implementation that must be proven equivalent (I6) on every Mago release, plus findings
   that carry no `phpqaci.*` identifier. Where the mirror differs (`sensitive-parameter`
   flagged 10 parameters PHPStan accepts; `require-api-or-internal` covers a subset) it would
   be an A-vs-B by construction. A mirror becomes worth considering only if Phase 4's
   measurement shows the PHPStan step dominating edit latency; then it is admitted rule by rule
   through I6, never by assertion.
4. **Linter first; analyser only through a curated allow-list of codes**, each code admitted
   with a P± pair (I3). C1's codes (`redundant-type-comparison`,
   `redundant-logical-operation`) are not admitted while PHPStan requires the guard.
5. **Allow-list, not deny-list.** E is declared explicitly and I5 fails on anything a new
   release switches on. Whether Mago can be configured "everything off except" is a fact to
   establish in Phase 0; if not, the detector carries the burden.
6. **The suppression defence lands before Mago is enabled anywhere** (Phase 1).

## Owner rulings

- **D1: linter only.** The first delivery is Mago's linter: lint-on-edit, and a fast lane ahead
  of PHPStan, configured to agree with it. The analyser and the formatter stay off, and PHPStan
  keeps the final say. D4 is therefore deferred: no analyser code is admitted in this delivery.
- **D6: download at install time.** The binary is not committed. Install fetches the pinned
  release and verifies it against a committed version and sha256; a mismatch, or no network,
  fails loudly rather than skipping the lane silently. The tool-currency update bumps the pin and
  checksum.

## Open Owner decisions

| ID  | Decision                                                                                                                                                                                                              | Recommendation                                                                                                      |
| --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| D2  | Lint-on-edit route: upstream daemon change (a Mago slot, or N commands per language), php-qa-ci wrapper bin via `command_overrides`, or a project handler.                                                            | Upstream change as the clean route (the daemon is ours); wrapper bin as the interim; no project handler (per-repo). |
| D3  | Metric rules (`too-many-methods`, `cyclomatic-complexity`, `kan-defect`, `excessive-parameter-list`, `halstead`): wanted at all, at what thresholds? This is new policy, not agreement.                               | Off. If wanted, plan separately with thresholds and a baseline decision.                                            |
| D4  | Analyser codes: which are admitted, and how is each of the 211 resolved — a code change both tools accept, leaving the code off, or a Mago `ignore`/baseline? Ignores and baselines are Owner decisions under DBF.    | Admit only codes with zero findings on R after both-accept code fixes; no ignores, no baseline.                     |
| D5  | Inline `@mago-*` pragmas: forbidden? By extending `phpqaci.inlinePhpstanIgnore` (identifier name then misdescribes it), a new rule (e.g. `phpqaci.inlineMagoIgnore`), or renaming to a generic identifier (breaking). | Forbid; new rule with its own identifier, page and `bin/rules` entry; plus daemon suppression patterns upstream.    |
| D7  | Is replacing CS-Fixer with Mago's formatter on the table at all?                                                                                                                                                      | No.                                                                                                                 |
| D8  | Maturity: is a bus factor of one plus the coming breaking 2.0 acceptable for a tool consumers inherit? What is the exit criterion?                                                                                    | Accept for an advisory/edit-time role; pin the major and treat a major bump as an Owner-approved update.            |
| D9  | Overlap ownership: PHPStan single owner with Mago's equivalents OFF, or admit proven mirrors (I6) for edit speed?                                                                                                     | Single owner, mirrors off (Recommendation 3).                                                                       |
| D10 | Residual linter policy: `@` operator (C12), `single-class-per-file` (5 findings), `no-duplicate-match-arm` (1).                                                                                                       | Fix the code where both tools accept the fix; otherwise leave the rule off rather than ignore.                      |
| D11 | Does Mago's guidance appear to consumers with the "PHPStan is the final say; report a disagreement" message, and where are disagreement reports filed?                                                                | Yes; an issue against php-qa-ci, which becomes a new corpus probe.                                                  |
