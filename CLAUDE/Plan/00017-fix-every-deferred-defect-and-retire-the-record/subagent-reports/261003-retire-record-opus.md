# Retire the defect record (Tasks 3.1 and 3.2)

Branch `chore/hooks-daemon-v3.68`. Two commits, not pushed:

- `b8c251c` Clear the QA drift already on the branch (`Changelog: none` trailer)
- `786673c` Remove the defect record

## Why there are two commits

PHPStan was red at HEAD before this work started. The error was not in any file this task
touched. `phpqaci.repeatedStringLiteral` fired on `tests/Small/Pipeline/Lane/AnalysedPathsToolTest.php`:
`'rector.php'` appears five times, all added by Task 2.4's red commit `4f070b2`. The detector
already existed and had fired, so the fix was the refactor it asks for: a `ROOT_SCRIPT` class
constant.

PHP CS Fixer also had pending fixes from the Task 2.2 and 2.3 commits:

- `phpdoc_align` in `src/Pipeline/Lane/Infection/IgnoredPathsInfectionConfig.php`
- `ordered_imports` in `src/Pipeline/Lane/PhpArkitectTool.php`
- `binary_operator_spaces` in `tests/Small/Pipeline/Lane/IgnoredPathsReachEveryScanningLaneTest.php`

The first two went into `b8c251c`. The third went into `786673c`, because this task also changes
a line in that file.

## What `786673c` removes

- Deleted:

  - `qaConfig/defect-record.neon`
  - `src/DefectRecord/` (5 classes)
  - `tests/Small/DefectRecord/` (5 tests)
  - the fixture `tests/assets/ActiveRulesLister/projectFixture/qaConfig/defect-record.neon`

- `PhpstanIgnoreJustificationTool`: now checks `ignoreErrors` justifications only. The constructor,
  `run()` and docblock are back to their state before the record was added (`c2941a1^`). The
  `ToolRegistry` description no longer mentions the record.

- `ActiveRulesLister` and `ActiveDefencesListingDto`: the `defectRecord` property, the reader
  dependency, the text section, the JSON key and the "cannot read the defect record" error are
  removed. `bin/rules` docblock updated.

- `ActiveDefencesSummary`: `RECORD_HEADING`, `RECORD_PATH`, `MAX_LISTED_ENTRIES`,
  `defectRecord()` and `count()` are removed. The region now ends with the last defence line,
  `"\n\n"`, then the END marker. `escapeText()` and `theRegionIsInTheMarkdownFormattersCanonicalForm`
  are unchanged.

- Tests:

  - `ActiveDefencesSummaryTest`: the two record tests, the `listingWith()` helper and the
    constants only they used are deleted.
  - Lister render, text and JSON tests: expectations no longer include the record.
  - `ActiveRulesListerTest`: the malformed-record test is replaced by an assertion that the JSON
    keys are exactly `configPath`, `rules`, `pipelineLanes` and `projectRecord`.
  - `InProcessLanesTest`: the defect-record lane test is deleted.
  - `UsesClass` lines removed from `ShippedToolLocatorTest`, `PipelineBuilderTest`,
    `ActiveRulesListerSelfCheckTest`, `ActiveRulesListerTest` and `InProcessLanesTest`. These
    were real references, not unrelated uses of the word "deferred".
  - `IgnoredPathsReachEveryScanningLaneTest`: reason string updated.

- Docs:

  - `docs/tools/phpstanIgnoreJustification.md` is restored verbatim to its version before the
    record (the only differences were the record).
  - Updated: `docs/pipeline.md`, `docs/phpstan-rules/README.md`,
    `templates/root-CLAUDE-phpqaci-block.md.template`, `.claude/skills/defence-before-fix/SKILL.md`.
  - CLAUDE.md Phase 3 item 16, outside the region, also named the record and is fixed.

- `CLAUDE/DefenceBeforeFix.md`: the deferral bullet now says:

  - there is no deferral record, and a defect is fixed in the work that found it;
  - upstream code gets an issue on the upstream project, linked from the commit;
  - a no-pattern conclusion goes in the plan's `DECISIONS.md`.

  The `bin/rules` description in the same file no longer mentions the record.

- `docs/defence-before-fix-defaults.md`: the "Deferred defects" row becomes "A Defect found",
  stating the same rule for consumers. The record path is removed from "Where the project record is".

- CHANGELOG: one `- **BREAKING**:` entry under the existing `## Unreleased` → `### Changed — breaking`.

- CLAUDE.md region: regenerated with `php bin/rules . --write-agent-summary=CLAUDE.md`; the
  Deferred defects section is gone.

## Verification (all after the final edits)

- `bin/phpunit --no-coverage`: OK, 1733 tests, 4716 assertions
- `CI=true bin/qa -t stan`: `[OK] No errors`
- `-t cl` passes, also re-run after both commits.
- These lanes also pass:
  - `-t phpstanIgnoreJustification`
  - `-t ml` (no `md` alias exists; `-t md` prints the usage)
  - `-t dp`
  - `-t f`

## Leftover search

`rg -i 'defect.?record|no.?pattern|deferred'` over src, bin, tests, docs, templates,
configDefaults, `CLAUDE/*.md`, README.md, `.claude/skills` and qaConfig finds only these:

- the new guidance wording in `CLAUDE/DefenceBeforeFix.md` and the CLAUDE block template;
- "tests deferred" in `docs/github-actions.md` and `templates/github-actions/qa-autofix.yml`,
  which is unrelated and was left alone.

CHANGELOG's released 85.3.0 entry still describes the record when it was added. That is history,
so it is correct.
