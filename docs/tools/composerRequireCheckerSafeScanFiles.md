# Composer Require Checker: Safe scan-files Check

**Identifier**: `phpqaci.composerRequireCheckerSafeScanFiles`

A preflight of the Composer Require Checker lane. Every `thecodingmachine/safe` entry in the
resolved `composerRequireChecker.json`'s `scan-files` must name the generated file safe actually
loads on the PHP version QA runs under.

## What it is about

composer-require-checker treats a `\Safe\*` function as declared only if a file in `scan-files`
defines it, so the config lists safe's generated files. safe ships one dispatcher per extension
(`generated/<ext>.php`) that requires a version-specific file (`generated/<x.y>/<ext>.php`)
chosen by `PHP_VERSION`. The version directory is not simply the running PHP version: on PHP 8.5
safe loads `8.4/array.php` but `8.2/exec.php`.

An entry naming any other directory whitelists a function set that is not the one in force. A
`\Safe\*` call can then be reported as undeclared, or an undeclared one can slip through, and
nothing points at the stale entry. The list drifts silently every time the PHP requirement moves.

## How it runs

- In the full pipeline, at the start of the Composer Require Checker lane (`vendor/bin/qa -t cr`),
  before composer-require-checker itself.
- Binary: `bin/composer-require-checker-safe-scan-files-check <config> <project-root>`, delegating
  to `LTS\PHPQA\ComposerRequireChecker\SafeScanFilesCheck::main()`.

It is handed the same resolved config path composer-require-checker is about to read (the
project's own `qaConfig/composerRequireChecker.json` override when one exists, or the shipped
`configDefaults/generic/composerRequireChecker.json` otherwise). For each safe entry it reads the
matching dispatcher under the project's `vendor/` and compares the directory it requires for the
running PHP major.minor with the one the entry names. Entries that are not safe generated files,
and safe entries whose dispatcher is absent, are not judged.

## How to fix a failure

Replace each reported entry with the file the message names, or remove an entry whose dispatcher
has no branch for the running PHP at all. If QA runs under more than one PHP version, list the
files for the version the gate runs under.

## Implementation

- Decision: [`SafeScanFilesDetector`](../../src/ComposerRequireChecker/SafeScanFilesDetector.php),
  which reads the dispatcher source and compares version directories.
- Runner: [`SafeScanFilesCheck`](../../src/ComposerRequireChecker/SafeScanFilesCheck.php), which
  reads and decodes the config, maps the verdict to output and exit code, and prints the identifier.
