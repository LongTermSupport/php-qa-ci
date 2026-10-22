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

The fixer runs after Rector, so Rector does not see what the fixer wrote in the same pass. A
writable run therefore repeats the coding-standards phase until a pass changes no checked file, at
most three passes (see [Rector](rector.md#a-writable-run-repeats-until-nothing-changes)), and
leaves nothing for a read-only run to change. The shipped ruleset also agrees with the shipped
Rector configs, so the repeat is needed only where one tool creates the other's work, such as
`final_class` making a class final for Rector's final-class rules. Enum cases come first among
class elements, and constants, properties and methods keep one blank line between them, as
Rector's `NewlineBetweenClassLikeStmtsRector` requires.
[`WritableCodingStandardsRunIsAFixedPointTest`](../../tests/Large/CodingStandards/WritableCodingStandardsRunIsAFixedPointTest.php)
runs both tools that way over every class-like kind, a class that is not final, and members out of
order, and fails when a read-only check still has a change to make.

## How it runs

One process over the committed `vendor-phar/php-cs-fixer.phar`, from the project root, or two
in a writable run that changed a file (see [the cache](#the-cache)):

```
fix --config=<php_cs.php> <cache option> --allow-risky=yes \
    --show-progress=dots --path-mode=intersection -vvv [--dry-run] <checked paths>
```

The output is streamed and also written to `var/qa/php-cs-fixer-output.log`, both passes' output
when there are two.

### The cache

The fixer's cache (`var/qa/cache/php_cs.cache`) records each file a pass left as clean, and
later cached runs skip a recorded file whose content has not changed since. The fixer is not
idempotent on every input: on a heredoc whose blank lines carry whitespace, one pass of
`heredoc_indentation` re-indents the heredoc and only the next pass strips the blank lines. A
cached pass would record the half-fixed file as clean, and every cached run after it would skip
the file while a run without the cache (CI, a fresh clone) fails it ([#235](https://github.com/LongTermSupport/php-qa-ci/issues/235)). The lane therefore
uses the cache only where it cannot decide an outcome:

- **A read-only run** passes `--using-cache=no`, so the run that decides pass or fail checks
  every file, exactly as CI does. A cache left by any earlier run, a hand-run fixer included,
  cannot make it pass. When it finds a pending fix it deletes the cache, which may record that
  file as clean, so the writable run it asks for fixes the file instead of skipping it.
- **A writable run** passes `--cache-file=var/qa/cache/php_cs.cache`, and when that pass changed
  a file (its `Fixed N of M files` summary is not `Fixed 0`, or it printed no summary) the lane
  runs the fixer once more with `--using-cache=no`. That pass makes the change the cached pass
  could not see, so the coding-standards phase sees a changed file and repeats, and the cache's
  record of the half-fixed content no longer matches the file. A writable run that changed
  nothing costs one cached pass.

[`PhpCsFixerToolCacheTest`](../../tests/Large/Pipeline/Lane/PhpCsFixerToolCacheTest.php) runs
the lane over that heredoc and fails when a read-only run, a single writable run or a settled
writable phase disagrees with an uncached read-only check.

### The generated config

With `--path-mode=intersection` the checked paths only narrow what the config's finder yields, so
an exclusion has to be in the finder. The lane therefore always generates
`var/qa/phpCsFixer/php_cs.php`, which requires the resolved `php_cs.php`, filters its finder and
is passed as `--config`. This works whichever `php_cs.php` and `php_cs_finder.php` the project
uses, so a copied config needs no filtering of its own. The filter drops:

- every file under a path `qaConfig/qa.php` names in `withIgnoredPaths()`, compared by real path;
- every file whose real path cannot be resolved, such as a dangling symlink. The fixer narrows
  its finder by real path, and a dangling `*.php` symlink anywhere the finder walks used to kill
  it with a `TypeError` that named no file ([#239](https://github.com/LongTermSupport/php-qa-ci/issues/239)).
  The lane prints one line per such file, so nothing goes unchecked silently:
  `PHP CS Fixer skipped <path>: its real path cannot be resolved ...`. A skipped file does not
  fail the lane, because it has no content to check.

The shipped finder's `ignoreVCSIgnored(true)` does not filter out a dangling symlink, even a
gitignored one. Symfony Finder decides whether a file is ignored from its real path, and a dangling
symlink has none, so the Finder treats it as not ignored.

[`PhpCsFixerToolUnresolvablePathTest`](../../tests/Large/Pipeline/Lane/PhpCsFixerToolUnresolvablePathTest.php)
runs the lane over dangling symlinks in a tracked directory and in a gitignored one. It covers a
read-only and a writable run, with and without ignored paths.

- In the full pipeline, second in the coding-standards phase.
- Standalone: `vendor/bin/qa -t fixer` (aliases `-t f`, `-t csfixer`); supports `-p <path>`.
- **Read-only run** (`QA_READONLY=1`, GitHub Actions): `--dry-run` and `--using-cache=no` are
  passed. Exit 0 passes; exit 8 (pending fixes) fails with the read-only remediation block; any
  other exit is a genuine error and crashes the lane, which is never retried.
- **Writable run**: fixes are applied, by a cached pass and, when it changed a file, an uncached
  one (see [the cache](#the-cache)). Any non-zero exit fails the lane, and an interactive run
  offers a retry.
- **Lint errors** in either mode: when the output reports `Files that were not fixed due to errors`, a scanned file could not be parsed. The lane crashes regardless of the exit code, because
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

- Lane: [`PhpCsFixerTool`](../../src/Pipeline/Lane/PhpCsFixerTool.php); the generated config
  around the resolved one: [`FilteredFinderConfig`](../../src/Pipeline/Lane/PhpCsFixer/FilteredFinderConfig.php).
- Read-only remediation text: [`ReadOnlyGuidance`](../../src/Pipeline/Lane/ReadOnlyGuidance.php),
  shared with [Rector](rector.md).
- Shipped config: [`php_cs.php`](../../configDefaults/generic/php_cs.php) and its finder
  [`php_cs_finder.php`](../../configDefaults/generic/php_cs_finder.php).
