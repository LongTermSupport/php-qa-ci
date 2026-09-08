# Rector

**Identifier**: `phpqaci.rector`

Automated refactoring over the checked paths: Safe-function conversion, PHPUnit upgrades and the
PHP 8.5 level set. Rector mutates code, so it runs first, in the coding-standards phase, before
anything validates.

## What it is about

Rector applies deterministic, config-driven rewrites: `\Safe\*` replacements for functions that
return `false` on failure, PHPUnit API upgrades, and the PHP 8.5 migration rules. Because every
edit is reproducible from the config, the lane treats its own output as authoritative: a writable
run prints an advisory telling the developer to keep and commit what Rector produced rather than
revert it.

## How it runs

Up to four passes, each a separate Rector process over the committed
`vendor-phar/rector.phar`, in this order. The first pass that fails ends the lane.

| Pass               | Config                                             | Paths                                |
| ------------------ | -------------------------------------------------- | ------------------------------------ |
| `Safe`             | `rector-safe.php` (project override or shipped)    | the checked paths                    |
| `PHPUnit`          | `rector-phpunit.php` (project override or shipped) | the tests directory                  |
| `Project Specific` | `rector.php` and/or `qaConfig/rector.php`          | the checked paths, one pass per file |
| `PHP 8.5`          | `rector-php85.php` (project override or shipped)   | the checked paths                    |

The `Project Specific` and `PHP 8.5` passes are alternatives: when a project ships its own
`rector.php` in either location the shipped PHP 8.5 config is skipped, on the assumption that the
project config covers it.

Each process runs from the project root with `--autoload-file vendor/autoload.php`,
`--clear-cache`, and the ignored paths exported newline-joined in the `rectorIgnorePaths`
environment variable, which the shipped configs read into `skip()`.

- In the full pipeline, first in the coding-standards phase.
- Standalone: `vendor/bin/qa -t rector` (alias `-t r`); supports `-p <path>`.
- **Read-only run** (`QA_READONLY=1`, GitHub Actions): every pass gets `--dry-run`. Exit 0 passes;
  exit 2 (pending changes) fails with the read-only remediation block; any other exit is a genuine
  Rector error and crashes the lane, which is never retried.
- **Writable run**: changes are applied. Any non-zero exit fails the lane, and an interactive run
  offers a retry. After every pass succeeds the writable advisory is printed.
- A missing `vendor-phar/rector.phar` crashes the lane before any pass runs.

## How to fix a failure

- **Pending changes in a read-only run**: apply them where writes are allowed and commit:
  ```
  QA_READONLY=0 vendor/bin/qa -t rector
  git add -A && git commit
  ```
- **A genuine Rector error**: the output above the identifier names the cause (an invalid config,
  a parse failure, a missing autoload file). Fix that; retrying cannot help.
- **A downstream error after a writable run**: keep the Rector edit and fix the cause it surfaced
  (narrow a type, add a missing test). Never revert Rector output to make another gate pass.

## Implementation

- Lane: [`RectorTool`](../../src/Pipeline/Lane/RectorTool.php).
- Read-only remediation text: [`ReadOnlyGuidance`](../../src/Pipeline/Lane/ReadOnlyGuidance.php),
  shared with [PHP CS Fixer](phpCsFixer.md).
- Shipped configs: [`rector-safe.php`](../../configDefaults/generic/rector-safe.php),
  [`rector-phpunit.php`](../../configDefaults/generic/rector-phpunit.php),
  [`rector-php85.php`](../../configDefaults/generic/rector-php85.php).
