# PHP Lint

**Identifier**: `phpqaci.phpLint`

An always-on parallel syntax check of every PHP file under the checked paths, using the
shipped `vendor-phar/parallel-lint.phar`.

## What it is about

A file that does not parse fails every later tool in a confusing way: PHP CS Fixer reports it
as "not fixed due to errors", PHPStan reports it as a parse error among real findings, and
PHPUnit fails to load it. Linting first, in parallel, gives the fastest and clearest report of a
syntax error before anything heavier runs.

## How it runs

- In the full pipeline, in the linting phase after the Strict Types check.
- Standalone: `vendor/bin/qa -t lint`; supports `-p <path>`.
- Runs `vendor-phar/parallel-lint.phar` without Xdebug over every checked path. Each ignored path
  (`pathsToIgnore`) is passed as an `--exclude`, resolved under the project root.
- parallel-lint reports a file whose real path cannot be resolved, such as a dangling symlink, as
  an `Unknown error` that does not say why
  ([#241](https://github.com/LongTermSupport/php-qa-ci/issues/241)). The lane first walks the
  checked paths as parallel-lint does (its default extensions, in any case, not entering an
  ignored path), prints `PHP Lint skipped <path>: its real path cannot be resolved ...` for each
  such file and passes it as one more `--exclude`. A skipped file does not fail the lane: when
  every file the walk finds is skipped, parallel-lint is not run and the lane passes.
- Any non-zero exit fails the lane, including parallel-lint's `No file found to check` (exit 254)
  for a checked directory that holds no PHP file at all.

## How to fix a failure

The output names the file and line of each syntax error. Fix the file; there is nothing to
configure. A path that should never be linted (generated fixtures, deliberately broken test
assets) belongs in `pathsToIgnore`.

## Implementation

- Lane: [`PhpLintTool`](../../src/Pipeline/Lane/PhpLintTool.php).
