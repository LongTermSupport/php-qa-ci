# Plan 00024 — The agreement invariant

This is the specification the agreement detector (Phase 3) implements. It is the core
deliverable of the plan: Mago is allowed to block anything only where this invariant holds.

## Why it is a defence

The defect class is **"PHPStan says A, Mago says B"**: a construct one gate requires and the
other forbids, or a finding one gate raises on code the other has passed. Either makes a green
build impossible, or teaches agents to suppress one tool to please the other. Under Defence
Before Fix the detector for that class is built before any Mago rule is switched on, and it
keeps running after: Mago releases frequently and adds or changes default rules, so agreement
measured once is not agreement kept.

## Definitions

- **PHPStan** is the shipped `phpstan.phar` with the resolved `phpstan.neon` (level max,
  strict-rules, every `phpqaci.*` rule). It is the final say. Nothing in this invariant asks
  PHPStan to change.
- **E (the enabled set)** is every Mago linter rule enabled at a failing level plus every
  analyser code on the allow-list, read from Mago's *effective* configuration (what the binary
  reports it will run), never from the toml as written.
- **R** is this repository's checked paths (`src/`, `tests/`, minus what PHPStan excludes).
  R is PHPStan-clean by construction: the pipeline is green.
- **P+** is probe files PHPStan accepts.
- **P−** is probe files PHPStan rejects, each annotated with the PHPStan identifier(s) it must
  raise, so a probe that silently stops being rejected fails too.
- **P±** is probe pairs `(bad, fixed)` for each enabled code: `bad` is flagged by Mago with that
  code; `fixed` is the construction the code's guidance leads to.
- **Mirrored rules** (only if the Owner chooses mirroring, Decision D9) are Mago rules declared
  equivalent to a named `phpqaci.*` rule.

## The invariant

| ID  | Statement                                                                                                                                                      | Catches                                                      |
| --- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------ |
| I1  | For every file in R ∪ P+, Mago(E) reports nothing.                                                                                                             | A Mago-only finding on PHPStan-clean code (the 211 / C4–C11) |
| I2  | For every pair in P±, PHPStan accepts `fixed` and Mago(E) accepts `fixed`.                                                                                     | "Fix it for Mago" leading into a PHPStan error (C13)         |
| I3  | Every code in E has at least one P± pair.                                                                                                                      | A code enabled with no demonstrated both-accept fix          |
| I4  | No construct in P+ that PHPStan *requires* (a P− file whose fix adds it, e.g. the C1 guard) is flagged by Mago(E).                                             | Contradiction, C1 class                                      |
| I5  | E equals the declared allow-list exactly.                                                                                                                      | A rule a new Mago release turns on by default                |
| I6  | For each mirrored rule, on that rule's probe set: Mago flags a file ⇔ the owning PHPStan rule flags it.                                                        | A mirror that drifts from its owner                          |
| I7  | Mago exits with a documented code (0 clean, 1 findings) and produces parseable output for every corpus file; any other outcome fails as a crash, never a pass. | A Mago crash read as "no findings"                           |

**Not required:** PHPStan-only findings. PHPStan is the in-depth gate and Mago may miss things;
that is a capability gap, not a disagreement.

I1 is the strongest clause and does most of the work: R is the largest corpus anyone has that
is known PHPStan-clean. I4 is I1 applied to the probe files that pin each PHPStan *requirement*
explicitly, because R only contains a requirement if some file happens to need it.

## The corpus

- `tests/assets/MagoAgreement/` (exact path decided in Phase 3): one file per probe, named by
  its case ID, carrying a header comment stating which tool requires or forbids what.
- PHPStan sources are made visible to Mago (it cannot read classes inside a PHAR): the detector
  supplies an extracted copy or stubs to `source.includes` for the run. A run without them
  reports hundreds of unknown classes, which I7 must treat as a broken run, not findings.
- Seeded from the research report's C1–C15 (section 3b) and the annotation probe (P1–P8):

| Case | Probe                                                                                     | Kind             |
| ---- | ----------------------------------------------------------------------------------------- | ---------------- |
| C1   | `isset($_SERVER['PWD']) && \is_string(...)` guard, and the unguarded form                 | P+ (guarded), P− |
| C2   | `self::assert*` vs `static::assert*`                                                      | P+ / I5          |
| C4   | `$x = require $f;` then narrowing                                                         | P+               |
| C5   | `\Safe\preg_match(..., $matches)` with and without `$matches = null;`                     | P+, P±           |
| C6   | nullable property narrowed then read after `$this->` calls in a loop; local-variable form | P+, P±           |
| C7   | `str_repeat(' ', $int)` and the `int<0,max>` form                                         | P+, P±           |
| C8   | boolean flag updated later in the same loop                                               | P+               |
| C9   | `'uniterate' === $x`; `const HTTP_API_KEY = 'HTTP_API_KEY'`                               | P+               |
| C10  | a class over Mago's metric thresholds                                                     | P+ (unless D3)   |
| C11  | `isset()`; literal positional arguments                                                   | P+               |
| C12  | `@` error-control operator                                                                | per D10          |
| C13  | `is_string` guard on a Safe match group                                                   | P− (PHPStan)     |
| C14  | `@mago-ignore` / `@mago-expect` in source                                                 | P− (new rule)    |
| C15  | constructs `missing-docs`, `yoda-conditions` forbid, `check-missing-override` would flag  | P+ / I5          |
| P1–8 | annotation probe: aliases, shapes, generics, conditional returns, asserts, masks          | P+ and P−        |

## Where it runs

- **Here, on every pipeline run:** a Large PHPUnit test over the corpus, so the daily
  dependency update turns red when a new Mago release breaks agreement (tool-currency already
  treats a red update as a DBF fix).
- **For consumers:** the same logic behind a `bin/` command a consumer runs on their own code
  (`R` = their checked paths). Their code may disagree in ways ours does not; the shipped config
  keeps only agreed codes at a failing level, and the command tells them which of their files
  break I1 so the disagreement is reported rather than suppressed.
