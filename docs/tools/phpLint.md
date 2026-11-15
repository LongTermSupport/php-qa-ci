# PHP Lint

**Identifier**: `phpqaci.phpLint`

An always-on parallel syntax check of every PHP file under the checked paths and under every
path declared unanalysed, using the shipped `vendor-phar/parallel-lint.phar`.

## What it is about

A file that does not parse fails every later tool in a confusing way: PHP CS Fixer reports it
as "not fixed due to errors", PHPStan reports it as a parse error among real findings, and
PHPUnit fails to load it. Linting first, in parallel, gives the fastest and clearest report of a
syntax error before anything heavier runs.

## How it runs

- In the full pipeline, in the linting phase after the Strict Types check.
- Standalone: `vendor/bin/qa -t lint`; supports `-p <path>`.
- Runs `vendor-phar/parallel-lint.phar` without Xdebug over every checked path, then over every
  path declared with `withUnanalysedPath()` (a directory or a single file): a declaration
  excludes a path from style and static analysis, not from the syntax check. A declaration naming
  nothing is left to the [analysedPaths](analysedPaths.md) lane, and a run narrowed with `-p`
  lints that path alone. Each ignored path (`pathsToIgnore`) is passed as an `--exclude`,
  resolved under the project root with a leading `./` and a trailing slash dropped, as every
  other lane reads it, and a declaration at or under an ignored path is not linted, however the
  ignored path is spelt.
- parallel-lint reports a file whose real path cannot be resolved, such as a dangling symlink, as
  an `Unknown error` that does not say why
  ([#241](https://github.com/LongTermSupport/php-qa-ci/issues/241)). The lane first walks the
  checked paths as parallel-lint does (its default extensions, in any case, not entering an
  ignored path, which is matched as parallel-lint matches an `--exclude`: a doubled slash inside
  the path is not collapsed), prints `PHP Lint skipped <path>: its real path cannot be resolved ...` for each
  such file and passes it as one more `--exclude`. A skipped file does not fail the lane: when
  every file the walk finds is skipped, parallel-lint is not run and the lane passes.
- Any non-zero exit fails the lane, including parallel-lint's `No file found to check` (exit 254)
  for a checked directory that holds no PHP file at all.

## How to fix a failure

The output names the file and line of each syntax error. Fix the file; there is nothing to
configure. A path that should never be linted (deliberately broken test assets, a fixture that
does not parse) belongs in `pathsToIgnore`, also when it is declared unanalysed: declaring it
unanalysed does not exclude it from this lane.

## Implementation

- Lane: [`PhpLintTool`](../../src/Pipeline/Lane/PhpLintTool.php).
