# Task 6.4: sceptical re-audit of Plan 00022

Reviewer: a fresh sub-agent, working only from the files in the worktree
`/workspace/untracked/worktrees/docs-pd` at `b8cfbee1` (branch
`chore/claude-md-progressive-disclosure`). Baseline: `636ea8dc:CLAUDE.md`. Read-only apart from
this file. Nothing was committed and the full `bin/qa` was not run.

## Verdicts

| #   | Audit                                        | Verdict | Summary                                                                                             |
| --- | -------------------------------------------- | ------- | --------------------------------------------------------------------------------------------------- |
| 0   | Generated regions unchanged                  | PASS    | Both regions are byte-identical to `425bd2ff~1:CLAUDE.md`                                           |
| 1   | `@` import audit                             | PASS    | No `^\s*@` and no `[@` outside fences in any of the nine files                                      |
| 2   | FLOOR F01–F13                                | PASS    | All 13 clauses are present and every link resolves; one MINOR finding (F03 canon)                   |
| 3   | Knowledge preservation                       | MAJOR   | D03 is marked PRESENT, but two baseline DBF facts exist nowhere in the repo                         |
| 4   | SSoT split (Decision 1)                      | PASS    | The split is right; MINOR restatements only                                                         |
| 5   | Globs, R7a shape, nested files, Decision 7   | PASS    | Every glob matches tracked files and no subtree has two triggers; one MINOR finding (a stale claim) |
| 6   | Accuracy of the new hand-written text        | PASS    | Root, nested files, rules and the CHANGELOG entry check out; MINOR findings in the edited `docs/`   |
| 7   | Regressions (links, anchors, stale headings) | PASS    | No broken link or anchor, and no reference to a removed heading                                     |

## Evidence

### 0. Generated regions

`awk` extraction of `phpqaci-active-defences:start…end` and of `<hooksdaemon>…</hooksdaemon>` from
`CLAUDE.md` and from `git show 425bd2ff~1:CLAUDE.md`, then `diff`: both identical.

### 1. Import audit: PASS

`CLAUDE.md`, `src/Pipeline/CLAUDE.md`, `src/PHPStan/CLAUDE.md`, `scripts/CLAUDE.md` and
`.claude/rules/phpqaci-{reference-docs,releases,shell-scripts,tool-delivery}.md` were scanned
with fences skipped. There were no matches.

### 2. FLOOR clauses: PASS

The marker `<!-- binding-floor: each bullet cites its canon -->` is block-level, at
`CLAUDE.md:13`. Clause by clause:

- F01: `CLAUDE.md:15-18`. Says "canonical here" and that auto memory is off. `.claude/settings.json` has `autoMemoryEnabled: false`.
- F02, F03, F04: `CLAUDE.md:19-22`. Detector first; identifier, page, `docs/phpstan-rules/README.md` index entry, `bin/rules`; "never a silent edit".
- F05: `CLAUDE.md:23-25`. Covers exit 139, `segfault at`, and "even under a command that reported success".
- F06: `CLAUDE.md:26-29`.
- F07, F08: `CLAUDE.md:30-33`. "start and end", "fresh sub-agent", "standing authorisation".
- F09: `CLAUDE.md:34-36`.
- F10, F11: `CLAUDE.md:37-39`.
- F12: `CLAUDE.md:40-42`.
- F13: `CLAUDE.md:43-44`. Its canon `CLAUDE/prepush-verification.md:26-29` now says that `CI=true` alone does not make a run read-only.

Every linked canon exists (link check below).

### 3. Knowledge preservation: MAJOR

**Mechanical pass.** A script searched each destination file for every FACT-MAP key. All keys
resolve except four:

- P01 and C01 fail only because the key text is wrapped across a line break in the destination. The fact is present.
- E21 and N10 have malformed backtick escaping in the Key column. The facts are present, at `docs/pipeline.md:100` and `CLAUDE/prepush-verification.md:27`.

**Semantic sample: 46 non-FLOOR rows checked by reading the destination.** The rows: D02, D03,
S02, R02, T03, G01–G05, A02–A14, X01–X07, H02–H05, E06, E11, E15, E18, E19, E21, E25, E26, E30,
N03, N04, N07–N09, N13–N16, N19, Q04, Q06, Q10, Q19, Q21, U03 and U04. Twenty-five more facts
from the Tools Reference map were spot-checked as well, among them the CDA `Error:` caution, the
PHPStan worker cap and `--debug`, the dead-code `@api` fail-fast, the ShellCheck version mismatch
and exit 2, the OPcache advisory, `--skip-initial-tests`, `PHPQACI_ARKITECT_EXCLUDE_PATHS`, the
phive key (`README.md:151`), the merge-base diff and the "below 100" floor.

All of them hold except D03:

- **D03 is wrongly marked PRESENT.** The baseline (`636ea8dc:CLAUDE.md:26-31`) said two things
  that are now in no tracked file outside the plan:

  - "Upstream grades us clause by clause in its [tools register](remote-docs/defence-before-fix.github.io/tools/php-qa-ci.md)."
    A `git grep` for `tools/php-qa-ci.md` finds only `.claude/REMOTE-DOCS.md`.
  - "A gap found is closed, not accepted." A `git grep` finds nothing.

  `CLAUDE/DefenceBeforeFix.md:298-337` covers `extra.defence-before-fix` and
  `DefenceBeforeFixDeclarationTest`. It describes an "accepted by Owner decision" status, but
  never states the stance that a found gap is closed rather than accepted. That stance came from
  a section marked binding.

**Unaccounted facts.** Every baseline heading (63 of them) maps to rows. Two facts were lost or
diluted in this sweep:

- the D03 pair above;
- the repo-specific list of markdown paths never to format (`remote-docs/`, `vendor-docs/`,
  `tests/assets/`). It was dropped deliberately as TR-mdf-6, and `docs/tools/markdownFormat.md:82`
  keeps the rule in generic form. MINOR.

### 4. SSoT split: PASS

- `CLAUDE/architecture.md` holds agent-only invariants and links `docs/pipeline.md` for order and
  preparation (`:3-6`, `:66-67`).
- `CLAUDE/dogfooding.md` is contributor-only.
- `CLAUDE/claude-code-integration.md` links the README and `.claude/hooks/README.md` for the user-facing parts.
- The consumer half of "not a tool proxy" is in `docs/configuration.md:198-224`, and the contributor half is in `CLAUDE/architecture.md:100-111`.

These are restatements, not duplicate blocks (all MINOR):

- `CLAUDE/release-lines.md:8-16` restates the three README bullets (`README.md:520-525`) in full.
  The plan said it would carry only what README lacks, but as the canon of the ruling the
  restatement is defensible.
- The deploy one-liner `deploy-skills.bash vendor/lts/php-qa-ci .` now appears in four places:
  `README.md:412`, `.claude/hooks/README.md:179` and `:207`, and `CLAUDE/claude-code-integration.md:7`.
- `CLAUDE/claude-code-integration.md:13-14` names the two recommended hooks that `.claude/hooks/README.md` owns.

### 5. Globs and nested files: PASS

**Rule shape.** All four rules have at most two imperative lines and a link, no tables, fences
or procedures, and at most 14 non-blank lines including the frontmatter.

**Every glob matches tracked files** (`git ls-files`):

| Glob                                            | Files                           |
| ----------------------------------------------- | ------------------------------- |
| `docs/tools/**`                                 | 31                              |
| `docs/phpstan-rules/**`                         | 40                              |
| each named `docs/*.md` page                     | 1                               |
| `CHANGELOG.md`                                  | 1                               |
| `ci.yml`, `qa.yml`, `release.yml`               | 1 each                          |
| `ci.bash`                                       | 1                               |
| `bin/**`                                        | 29                              |
| `build/**/*.bash`                               | 1 (`build/rector/prepare.bash`) |
| `phive.xml`, `composer.json`, `update-deps.yml` | 1 each                          |

The releases rule's workflow set is correct: `release.yml:41` is gated on CI, PHP QA Pipeline and QA.

**Decision 7.** The nested files cover `src/Pipeline/`, `src/PHPStan/` and `scripts/`. No project
rule covers those subtrees. `CLAUDE/Plan/mkplan.bash` falls under no rule, which is acceptable
because it is daemon tooling.

**Nested files.** They are 8, 6 and 9 non-blank lines, and every claim they make holds:

- `ActiveRulesLister` and `RuleDocResolver` are in `src/PHPStan`.
- DBF clauses 3.4 and 3.6 cover the sweep and the identifier.
- `tool-currency.md:69-85` covers doing it by hand, PHARs included.
- `issue-sdlc.md:22` covers the issue monitor.
- The architecture anchors resolve.

**Stale claim (MINOR).** `CLAUDE/DocumentationStrategy.md` says `.claude/rules/` "are expected not
to" load from a vendored checkout. The plan's own journal (17:31 item 4, 17:50 T6.2 item 2) shows
rules resolving against a nested checkout, so that expectation is weakened. `CLAUDE/dogfooding.md:37-40`
hedges with "may not", which is fine.

### 6. Accuracy: PASS

**Root `CLAUDE.md`.**

- `bin/rules`, `bin/rule-doc` and `bin/phpstan-rule` exist (`composer.json` `bin`).
- Every lane is under `src/Pipeline/Lane/`.
- The hand-written part is 79 lines and 5,914 chars, about 1.5k tokens, against 993 lines and 69,982 chars at the baseline.

**CHANGELOG `### Changed` entry** (`CHANGELOG.md:49`): it sits under `## Unreleased`, and "about a
tenth" is accurate (8.5%).

**`CLAUDE/architecture.md`.**

- `:63-65`: only `PhpunitTool:104,121` and `InfectionTool:595` read `binDir`, so the claim holds.
- `:105`: lists `--agent-mode` (`ArgumentsParser:27`) but omits `-h`. MINOR.

**`CLAUDE/claude-code-integration.md`.**

- `:16-19`: the manifest and the classic-hooks files exist.
- `:17-18`: says the legacy-name migration "runs on every deploy". It actually runs only when
  `.claude/settings.json` exists (`deploy-classic-hooks.inc.bash:7-9,31`). MINOR.

**`CLAUDE/release-lines.md:16`**: `IssueFormsTest::MAINTAINED_OLDER_LINES` exists (`:47`).

**Edited `docs/`.**

- **Not accurate (MINOR):** `docs/pipeline.md:98` says Phase 2 validates "with one exception" (PHP
  Strict Types). In a writable run `composerChecks` also rewrites `composer.json` through
  composer-normalize (`ComposerChecksTool.php:95-116`). This is FACT-MAP row E19's correction,
  which is itself inaccurate.
- **Accurate:**
  - The order claims in `versionPins`, `phpStrictTypes`, `composerRequireChecker`, `yamlLint`, `shellCheck` and `twigCsFixer` match `ToolRegistry.php:91-121`.
  - `-t` takes aliases only (`ToolRegistry:70-73,157-165`), so dropping `-t yamlLint` and `-t twigLint` is right.
  - `allLints` exists (`PhaseEnum.php:28`).
  - `deadCode` is `NotQuick` (`:115`).
  - The CI and read-only text matches `EnvironmentReader.php:91-122`.
- **Pre-existing, not introduced (MINOR):** `docs/tools/twigLint.md:18` still says "with the other
  Symfony lanes", but `twigLint` is the only platform lane (`ToolRegistry:152`).

### 7. Regressions: PASS

- **Links and anchors.** A link and anchor check over all 34 non-plan markdown files changed
  since the baseline found nothing missing and no bad anchor. That includes
  `architecture.md#bash-is-for-invocation-…`, `#the-pipeline-is-not-a-tool-proxy-owner-ruling`,
  `configuration.md#the-pipeline-is-not-a-tool-proxy`, `#read-only-runs`, `pipeline.md#2-preparation`,
  `README.md#claude-code-integration` and `#branches-and-versions`.
- **`CLAUDE.md#` anchors.** None remain anywhere outside the plan.
- **Old heading names.** A `git grep` for "Pipeline Execution Order", "Tools Reference",
  "Configuration Cascade", "Preflight Phase", "Real scripting is PHP", "Work happens on",
  "Read-Only / CI", "Tool Runner System", "Design Philosophy", "Common Customizations",
  "Overriding Tool Configurations" and "Hook System" found no hits outside `CLAUDE/Plan`. The
  only matches were unrelated "Claude Code Hooks"/"Important Notes" headings in daemon or agent files.
- **Repointed comments.** `ForbidUnanchoredVendorSubstringCheckRule.php:28`,
  `RequireDeclareStrictTypesRule.php:18`, `IssueFormsTest.php:16,42`, `.claude/hooks/README.md:419`,
  `docs/tools/shellCheck.md:33` and `docs/tools/phpstan.md:99` all point at files that exist.

## MAJOR findings (fix before merge)

1. **Two binding DBF facts were lost, and FACT-MAP D03 misreports them as PRESENT.** The facts
   are "A gap found is closed, not accepted" and "Upstream grades us clause by clause in its
   tools register (`remote-docs/defence-before-fix.github.io/tools/php-qa-ci.md`)".

   Fix:

   - Add both sentences to `CLAUDE/DefenceBeforeFix.md` in "The claim is declared where it can be
     checked" (`:300-305`), with the register link.
   - Change D03 to `ADDED` with a key such as `closed, not accepted`.
   - Optionally append "a found gap is closed, not accepted" to the DBF floor bullet at `CLAUDE.md:19-22`.

## MINOR findings

01. The DBF floor bullet links only `CLAUDE/DefenceBeforeFix.md`. FLOOR-CLAUSES lists
    `CLAUDE/tool-boundaries.md` as F03's canon too (lane or assertion). It is reachable only from
    the navigation map. Fix: add the link to `CLAUDE.md:22`.
02. `docs/pipeline.md:98`, "with one exception", is false: composer-normalize also writes
    `composer.json` in a writable run. Fix: name both, or say "Strict Types and composer-normalize".
03. The FACT-MAP keys of P01 and C01 cross a line wrap, and E21 and N10 have malformed escaping,
    so a literal key search fails on all four. Fix: choose keys that sit on a single line.
04. `CLAUDE/DocumentationStrategy.md` (nested-vs-rules section) says `.claude/rules` "are expected
    not to" load from `vendor/`. The plan's own journal evidence points the other way. Fix:
    "may or may not; never relied on".
05. `CLAUDE/claude-code-integration.md:17-18` says the legacy-name migration runs "on every
    deploy". It runs only when `.claude/settings.json` exists.
06. `CLAUDE/architecture.md:105` says "`-t`, `-p`, `--json` and `--agent-mode` are the whole
    interface", which omits `-h`.
07. `CLAUDE/release-lines.md` restates README's branch bullets rather than linking them (Decision 3
    wording). The deploy one-liner is now in four places.
08. The repo-specific never-format paths (`remote-docs/`, `vendor-docs/`, `tests/assets/`) are no
    longer named anywhere (deliberate, TR-mdf-6).
09. Pre-existing: `docs/tools/twigLint.md:18` says "the other Symfony lanes", but there are none.
10. Not a regression: the floor (for example "Tools stay current: check the update PR every
    session") still loads into consumer sessions through the vendored root `CLAUDE.md`, exactly as
    the baseline did. Consider one line saying that the floor binds work on php-qa-ci itself.
