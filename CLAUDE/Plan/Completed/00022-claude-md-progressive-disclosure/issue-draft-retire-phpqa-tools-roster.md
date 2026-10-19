# Issue draft: retire `docs/phpqa-tools.md` as a parallel hand roster

Draft for the php-qa-ci tracker, not filed. Found on `php8.5`.

## Problem

`docs/phpqa-tools.md` lists the pipeline's tools by hand. It is a second roster beside the one
`ToolRegistry` generates (`bin/qa -h`, the active-defences region of `CLAUDE.md`), and it has
drifted: it still calls Yaml Lint a platform lane, although Yaml Lint is a shipped lane that gates
on `symfony/yaml` and runs on any platform.

## Class

A hand-maintained tool roster drifting from its generator. Plan 00022 removed the other instances
(the roster in root `CLAUDE.md` and the numbered lane lists in `docs/pipeline.md`) by pointing at
the generated sources instead (Decision 5, option (c)). A test comparing the page to
`ToolRegistry::shipped()` is excluded as the Detector by Defence Before Fix §3.2.

## Proposed

Retire the page's roster: keep any fact it alone carries at its canonical `docs/tools/` page, and
replace the list with pointers to `bin/qa -h` and the identifier index
`docs/phpstan-rules/README.md`. Repoint every link to the page.
