# Composer Require Checker

**Identifier**: `phpqaci.composerRequireChecker`

An always-on check that every class, function and constant production code uses comes from a
package (or extension) the project's `composer.json` declares in `require`.

## What it is about

A symbol that resolves only through a transitive dependency, or through a `require-dev`
package, works on a development machine and breaks in a production install: a `--no-dev`
install leaves the package out, and a dependency that stops shipping its own dependency drops
the symbol silently. Explicit is better than implicit. If you use it, declare it.

## How it runs

- In the full pipeline, in the linting phase after PHP Lint.
- Standalone: `vendor/bin/qa -t cr`.
- Runs the shipped `vendor-phar/composer-require-checker.phar` without Xdebug, from the project
  root, as `check --config-file=<config> -- <project>/composer.json`.
- The config is `composerRequireChecker.json`, the project's `qaConfig/` copy first, else the
  shipped `configDefaults/generic/` default. It carries the symbol whitelist, the list of PHP
  core extensions, and the `scan-files` of `thecodingmachine/safe` generated function files.
- A non-zero exit fails the lane and prints the HOW TO FIX guidance.

## How to fix a failure

1. Add the package to `require` (not `require-dev`). If it is currently in `require-dev`,
   either move it or stop using it in production code.
2. For a PHP extension: `composer require ext-json:"*"` (and likewise for `ext-mbstring` etc.).
3. For a `\Safe\*` function the checker cannot see: add the generated file path to the
   `scan-files` section of your `qaConfig/composerRequireChecker.json` override.

Never add a dev-dependency symbol to the `symbol-whitelist`. The whitelist exists for language
built-ins and unavoidable edge cases only. Whitelisting a dev-dependency symbol hides a real
runtime failure: the class will be missing in a production install.

## Implementation

- Lane: [`ComposerRequireCheckerTool`](../../src/Pipeline/Lane/ComposerRequireCheckerTool.php).
- Default config: [`composerRequireChecker.json`](../../configDefaults/generic/composerRequireChecker.json).
