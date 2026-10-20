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

### Phase 0 facts

Evidence: the three `subagent-reports/261019-phase0-*.md` reports and
[UPSTREAM-DRAFTS.md](UPSTREAM-DRAFTS.md).

| Question                | Fact                                                                                                                                                                                                                                                                                                                                                                               | Task |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---- |
| Default-off config      | None. A rule missing from `mago.toml` runs at its default, so a new default-on rule is live. An allow-list in config means listing all 190 linter rules; an unknown rule name then fails closed (exit 2). CLI `--only` / `--retain-code` are allow-lists but accept unknown names with exit 0.                                                                                     | 0.1  |
| Reading E               | `config --show linter` reports the effective rules and levels; `lint --list-rules` reports default levels and is wrong for I5.                                                                                                                                                                                                                                                     | 0.1  |
| Analyser allow-list     | No per-code enable in config; `ignore` accepts unknown codes silently. `--retain-code` filters and governs the exit code.                                                                                                                                                                                                                                                          | 0.1  |
| Exit codes              | 0 clean (warnings too, by default), 1 findings or parse error, 2 config error or unreadable file, 139 crash. Exit 0 with nothing checked for a nonexistent path, a dangling symlink, a directory named `x.php` or an explicitly passed excluded file. The format never changes the exit code.                                                                                      | 0.2  |
| Segfault                | Mago 1.57.0 and 1.58.2 segfault, silently, on a binary-operator chain past about 8,500 operands at the default 8 MB stack; PHP accepts the file. Debugged to a minimal reproduction; filing awaits D12.                                                                                                                                                                            | 0.7  |
| Depth limit             | Valid PHP nested past about 512 parentheses gets `error[parse]`.                                                                                                                                                                                                                                                                                                                   | 0.2  |
| Edit-time PHPStan       | `qa -t phpstan -p FILE` takes about 5.4 s warm (4.6–6.5 s); about 3.4 s of it is `dump-parameters` and `diagnose`, which depend only on config ([#219](https://github.com/LongTermSupport/php-qa-ci/issues/219)). Under lock contention the daemon reports a lint failure for an edit that was never checked.                                                                      | 0.3  |
| Digests and attestation | No checksum files among release assets; the release API `digest` field matches `sha256sum`. `gh attestation verify` passes, pinned to `cd.yml` and the tag, and fails on a tampered copy. 1.58.0 shipped without an x86_64 musl asset. PHPStan Turbo's installer is the prior art, except that its silent skip on no network must become a loud failure under D6.                  | 0.4  |
| Analyser disagreements  | The 211 reproduce exactly: 72 A, 136 B (Mago false positives; 63 of them have a verified both-accept workaround), 2 C, 1 D. 91 come from by-reference out-variables (`$m = null;` first clears them in both tools); 50 from one Mago template bug (U3). C1 has a both-accept local-variable form; a second C13-class trap (SplHeap) was found. 15 of 30 codes can reach zero on R. | 0.5  |

Recommendation 5 stands in a modified form: the config cannot say "default off", so the shipped
`mago.toml` must enumerate every rule, generated from `lint --pedantic --list-rules --json` so it
is never hand-maintained, and I5 reads `config --show linter`. A wrapper-side `--only` is a second
belt only after the wrapper has checked every name itself.

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
- **D13: shelved until Mago 2.0.** The plan is cancelled for now, with the facts below kept for
  the revisit. [#208](https://github.com/LongTermSupport/php-qa-ci/issues/208) stays open, blocked
  on Mago 2.0
  ([carthage-software/mago#1931](https://github.com/carthage-software/mago/issues/1931)). The
  open decisions D2–D5 and D7–D12 lapse with the plan and are re-presented on the revisit, against
  2.0. The Owner's intended model, which the revisit starts from: on edit, Mago runs **instead
  of** PHPStan, to make the lint-on-edit loop fast; PHPStan runs only in a full QA run. The
  edit-time wrapper is php-qa-ci's, aware of the hooks daemon but not coupled to it.

## Shelving facts

The facts behind D13, as found while the Owner weighed whether to adopt Mago at all.

| Question                         | Fact                                                                                                                                                                                                                                                                                                                                                                   |
| -------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Mago instead of PHPStan on edit  | One-file Mago lint is about 15 ms against about 5.4 s for `qa -t phpstan -p FILE`. The cost: edit time would lose PHPStan's type analysis and 28 of the 36 `phpqaci.*` rules, which have no Mago counterpart (§3a). An agent would pass on edit and fail at full QA. That is a delay, not an A-vs-B contradiction, as long as Mago never reports what PHPStan accepts. |
| The cheaper speed lever          | About 3.4 s of the 5.4 s is per-run setup that depends only on config ([#219](https://github.com/LongTermSupport/php-qa-ci/issues/219)); the `analyse` call alone measured 1.8 s. Fixing #219 is estimated, not measured, to bring the edit-time PHPStan step to about 2 s with every rule still running.                                                              |
| The 136 false positives          | All are from the analyser, which D1 already ruled out. The linter, with the aligned config, leaves 8 findings on this repository.                                                                                                                                                                                                                                      |
| The segfault                     | Found by a generated probe file (one statement of 100,000 joined strings), not by real code. Real code is far below the threshold of about 8,500 operands; only generated code would reach it. It still crashes silently, so U1 stays a valid report.                                                                                                                  |
| Mago 2.0                         | A ground-up rework of the analysis pipeline, labelled `bc-break`. The 2.0.0 milestone is due 2027-01-10, with 16 of 30 tracking-issue tasks done and 10 closed and 14 open milestone issues, as read on 2026-10-20. Releases come every few days (1.58.0, 1.58.1 and 1.58.2 all on 2026-10-19).                                                                        |
| Custom rules (DBF compatibility) | Mago extensions exist since 1.47: PHP worker processes through the SDK in the Composer package, not sandboxed (§4). The 2.0 tracker still lists "Plugin system" as open, with the host language undecided, so the 1.x extension API is not a stable base. DBF's identifiers, `bin/rules`, `rule-doc` and the justification lane are PHPStan-only.                      |
| Verdict                          | Mago cannot carry DBF defences today. It could add fast extra checks beside PHPStan, but not replace it as the place defences live.                                                                                                                                                                                                                                    |

## Revisit when Mago 2.0 is released

1. Re-run the Phase 0 probes against 2.0: default-off config, exit codes, the U1 segfault and
   U2–U4, the analyser classification, and the speed.
2. Read 2.0's plugin system: language, stability promise, cost per run, and whether a
   `phpqaci.*` rule could live there with its identifier.
3. Weigh the Owner's model (Mago instead of PHPStan on edit) against the edit-time PHPStan time
   after #219.
4. Re-present the lapsed decisions, and file U1 if 2.0 still crashes.

## Lapsed Owner decisions (re-presented on the revisit, per D13)

| ID  | Decision                                                                                                                                                                                                              | Recommendation                                                                                                               |
| --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| D2  | Lint-on-edit route: upstream daemon change (a Mago slot, or N commands per language), php-qa-ci wrapper bin via `command_overrides`, or a project handler.                                                            | Upstream change as the clean route (the daemon is ours); wrapper bin as the interim; no project handler (per-repo).          |
| D3  | Metric rules (`too-many-methods`, `cyclomatic-complexity`, `kan-defect`, `excessive-parameter-list`, `halstead`): wanted at all, at what thresholds? This is new policy, not agreement.                               | Off. If wanted, plan separately with thresholds and a baseline decision.                                                     |
| D4  | Analyser codes: which are admitted, and how is each of the 211 resolved — a code change both tools accept, leaving the code off, or a Mago `ignore`/baseline? Ignores and baselines are Owner decisions under DBF.    | Admit only codes with zero findings on R after both-accept code fixes; no ignores, no baseline.                              |
| D5  | Inline `@mago-*` pragmas: forbidden? By extending `phpqaci.inlinePhpstanIgnore` (identifier name then misdescribes it), a new rule (e.g. `phpqaci.inlineMagoIgnore`), or renaming to a generic identifier (breaking). | Forbid; new rule with its own identifier, page and `bin/rules` entry; plus daemon suppression patterns upstream.             |
| D7  | Is replacing CS-Fixer with Mago's formatter on the table at all?                                                                                                                                                      | No.                                                                                                                          |
| D8  | Maturity: is a bus factor of one plus the coming breaking 2.0 acceptable for a tool consumers inherit? What is the exit criterion?                                                                                    | Accept for an advisory/edit-time role; pin the major and treat a major bump as an Owner-approved update.                     |
| D9  | Overlap ownership: PHPStan single owner with Mago's equivalents OFF, or admit proven mirrors (I6) for edit speed?                                                                                                     | Single owner, mirrors off (Recommendation 3).                                                                                |
| D10 | Residual linter policy: `@` operator (C12), `single-class-per-file` (5 findings), `no-duplicate-match-arm` (1).                                                                                                       | Fix the code where both tools accept the fix; otherwise leave the rule off rather than ignore.                               |
| D12 | File the upstream Mago reports in [UPSTREAM-DRAFTS.md](UPSTREAM-DRAFTS.md)? U1 (the segfault) is the segfault-policy filing; U2–U4 are agreement bugs. Each is public under a real identity.                          | File U1 now; U2 with it; U3 and U4 once their fixtures are reduced. Hold the first Mago pin bump until U1 is at least filed. |
| D11 | Does Mago's guidance appear to consumers with the "PHPStan is the final say; report a disagreement" message, and where are disagreement reports filed?                                                                | Yes; an issue against php-qa-ci, which becomes a new corpus probe.                                                           |
