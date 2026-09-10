# PHP CS Fixer

**Identifier**: `phpqaci.phpCsFixer`

Code-style fixing over the checked paths with the shipped `php_cs.php` ruleset (or the project's
`qaConfig/php_cs.php` override). PHP CS Fixer mutates code, so it runs in the coding-standards
phase, immediately after Rector and before anything validates.

## What it is about

One ruleset across every project: `@PhpCsFixer`, `@Symfony`, `@PHP8x5Migration` and the
project-wide additions (aligned operators, `final` classes, strict comparison, ordered imports and
class elements). The pipeline owns the arguments; a project changes rules by overriding the config
file, not by passing flags.

## How it runs

One process over the committed `vendor-phar/php-cs-fixer.phar`, from the project root:

```
fix --config=<php_cs.php> --cache-file=var/qa/cache/php_cs.cache --allow-risky=yes \
    --show-progress=dots --path-mode=intersection -vvv [--dry-run] <checked paths>
```

The process output is streamed and also written to `var/qa/php-cs-fixer-output.log`.

- In the full pipeline, second in the coding-standards phase.
- Standalone: `vendor/bin/qa -t fixer` (aliases `-t f`, `-t csfixer`); supports `-p <path>`.
- **Read-only run** (`QA_READONLY=1`, GitHub Actions): `--dry-run` is passed. Exit 0 passes; exit
  8 (pending fixes) fails with the read-only remediation block; any other exit is a genuine error
  and crashes the lane, which is never retried.
- **Writable run**: fixes are applied. Any non-zero exit fails the lane, and an interactive run
  offers a retry.
- **Lint errors** in either mode: when the output reports `Files that were not fixed due to
  errors`, a scanned file could not be parsed. The lane crashes regardless of the exit code, because
  the fixer cannot check or fix a file it cannot parse.

## How to fix a failure

- **Pending fixes in a read-only run**: apply them where writes are allowed and commit:
  ```
  QA_READONLY=0 vendor/bin/qa -t fixer
  git add -A && git commit
  ```
- **Files not fixed due to errors**: the log names each file and the parse error. Fix the syntax
  (PHP Lint, later in the pipeline, would report the same file) and re-run.
- **A genuine error**: the output above the identifier names the cause, usually an invalid config
  or a rule that does not exist in the installed fixer version.

## Implementation

- Lane: [`PhpCsFixerTool`](../../src/Pipeline/Lane/PhpCsFixerTool.php).
- Read-only remediation text: [`ReadOnlyGuidance`](../../src/Pipeline/Lane/ReadOnlyGuidance.php),
  shared with [Rector](rector.md).
- Shipped config: [`php_cs.php`](../../configDefaults/generic/php_cs.php) and its finder
  [`php_cs_finder.php`](../../configDefaults/generic/php_cs_finder.php).
