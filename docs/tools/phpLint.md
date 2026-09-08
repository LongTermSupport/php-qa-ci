# PHP Lint

**Identifier**: `phpqaci.phpLint`

An always-on parallel syntax check of every PHP file under the checked paths, using the
project's `parallel-lint` binary.

## What it is about

A file that does not parse fails every later tool in a confusing way: PHP CS Fixer reports it
as "not fixed due to errors", PHPStan reports it as a parse error among real findings, and
PHPUnit fails to load it. Linting first, in parallel, gives the fastest and clearest report of a
syntax error before anything heavier runs.

## How it runs

- In the full pipeline, in the linting phase after the Strict Types check.
- Standalone: `vendor/bin/qa -t lint`; supports `-p <path>`.
- Runs `vendor/bin/parallel-lint` without Xdebug over every checked path. Each ignored path
  (`pathsToIgnore`) is passed as an `--exclude`, resolved under the project root.
- Any non-zero exit fails the lane.

## How to fix a failure

The output names the file and line of each syntax error. Fix the file; there is nothing to
configure. A path that should never be linted (generated fixtures, deliberately broken test
assets) belongs in `pathsToIgnore`.

## Implementation

- Lane: [`PhpLintTool`](../../src/Pipeline/Lane/PhpLintTool.php).
