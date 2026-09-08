# PHPLoc

**Identifier**: `phpqaci.phploc`

Code-size statistics (lines of code, classes, methods, complexity) over the checked paths,
printed once the whole gate has passed. Informational only: it never fails the run.

## What it is about

A quick, consistent measure of how large and how complex a codebase is, produced on every green
run so the numbers can be compared over time. It is a report, not a check.

## How it runs

- In the full pipeline, in the post-success phase after the "ALL TESTS PASSING" message and
  before the post-hook.
- Standalone: `vendor/bin/qa -t loc`; supports `-p <path>`.
- If the project has no `vendor/bin/phploc` the lane is skipped with the summary
  `phploc not installed`; nothing runs.
- Otherwise `vendor/bin/phploc` runs without Xdebug over every checked path and its output is
  printed. Whatever its exit code, the lane passes.

## How to fix a failure

This lane cannot fail. To see the statistics, install the tool in the project
(`composer require --dev phploc/phploc`).

## Implementation

- Lane: [`PhplocTool`](../../src/Pipeline/Lane/PhplocTool.php).
