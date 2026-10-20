# Twig CS Fixer

**Identifier**: `phpqaci.twigCsFixer`

Coding standards for Twig templates, in the coding-standards phase after Rector and PHP CS Fixer,
on any project that has `twig/twig` installed.

## What it is about

PHP CS Fixer does not read Twig. On a Twig project a large amount of presentation logic
lives in `.twig` files that no formatter in the pipeline has ever touched, so they drift into
whatever each contributor's editor does — inconsistent delimiter spacing, mixed quote styles,
stray whitespace — and every template change produces a diff bigger than the change.

This lane applies the upstream `TwigCsFixer` standard to those files, so templates get the same
treatment PHP already gets.

## How it runs

- In the full pipeline, in the **coding-standards phase** after PHP CS Fixer. It is gated on the
  library, not the platform: a shipped lane, not a Symfony platform lane.
- Standalone: `vendor/bin/qa -t twigcs`.
- Skipped cleanly when `vendor/twig/twig` is absent, and when none of the configured twig
  directories exist.
- The tool is the shipped `vendor-phar/twig-cs-fixer.phar`, verified by PHIVE against the
  maintainer's signing key. Nothing is fetched at run time.
- Directories come from `withTwigDirectories()` in `qaConfig/qa.php`, defaulting to
  `templates/`. A configured directory that does not exist is dropped rather than passed
  through, because the fixer treats an unreadable path as a fatal error.
- Configuration resolves through the usual three levels as `.twig-cs-fixer.php`; the shipped
  default is
  [configDefaults/generic/.twig-cs-fixer.php](../../configDefaults/generic/.twig-cs-fixer.php).

### Read-only versus writable

- **Read-only run** (`QA_READONLY=1`, GitHub Actions): check only, with `--no-cache`, so every
  template is judged as CI judges it. A fixable violation fails the gate with the standard
  "pending changes in a READ-ONLY run" guidance, and deletes the writable run's cache.
- **Writable run**: `--fix` is added and fixes are applied, in one pass.

### The cache

twig-cs-fixer skips a template whose content its cache records as clean, and by default keeps
that cache in `.twig-cs-fixer.cache` in the project root. The lane keeps it out of both places
where it could do harm:

- A read-only run reads and writes no cache, so the gate cannot pass on what a cache says
  ([#237](https://github.com/LongTermSupport/php-qa-ci/issues/237)).
- A writable run caches in `var/qa/cache/twig-cs-fixer.cache`, never in the project root. The
  cache file is a config setting, not a command-line option, so the lane runs a config it
  generates at `var/qa/twigCsFixer/.twig-cs-fixer.php` on every writable run: the resolved
  `.twig-cs-fixer.php`, required unchanged, with its cache file set there. A cache manager the
  project config sets is dropped, because it would take precedence over that file.
- A read-only run that finds a pending fix deletes that cache, so the writable run the failure
  asks for checks every template rather than trusting a cache that may record one as clean.

One writable pass is enough, unlike PHP CS Fixer
([phpCsFixer.md](phpCsFixer.md)): twig-cs-fixer repeats its rules until a pass changes nothing,
and caches a template only when a lint of what it left is clean. A Large test pins that it
settles a template in one invocation, so a release that stops doing so fails the test.

A `.twig-cs-fixer.cache` an earlier version left in the project root is no longer read or
written; delete it.

There is one wrinkle worth knowing. `--fix` **still exits non-zero when a violation it cannot
fix remains**, so a writable run that ends non-zero does not mean nothing was fixed — it means
something was left. The lane says so rather than reporting it as a plain failure.

### Exit codes

| Code | Meaning                             | Lane outcome                |
| ---- | ----------------------------------- | --------------------------- |
| 0    | clean                               | passed                      |
| 1    | violations present                  | failed                      |
| 2    | config error or unhandled throwable | **crashed** — never retried |

Unlike the two Composer analysis lanes, this contract is unambiguous: a finding and a crash are
different codes, so the lane can classify them correctly.

## How to fix a failure

**In a read-only run**: apply the fixes where writes are allowed, then commit.

```bash
QA_READONLY=0 vendor/bin/qa
git add -A && git commit
```

**In a writable run**: the fixer has already applied everything it can, and what remains needs a
human. Correct the listed templates by hand and re-run.

**To change which rules apply**: copy the shipped config to `qaConfig/.twig-cs-fixer.php` and
edit the ruleset there. The project copy replaces the default outright; it is not merged.

## Implementation

- Lane: [`TwigCsFixerTool`](../../src/Pipeline/Lane/TwigCsFixerTool.php); the generated config:
  [`CacheFileConfig`](../../src/Pipeline/Lane/TwigCsFixer/CacheFileConfig.php); the cache file,
  shared with the phpCsFixer lane: [`FormatterCache`](../../src/Pipeline/Lane/FormatterCache.php).
- Test: [`TwigCsFixerToolCacheTest`](../../tests/Large/Pipeline/Lane/TwigCsFixerToolCacheTest.php).
- Upstream: [vincentlanglet/twig-cs-fixer](https://github.com/VincentLanglet/Twig-CS-Fixer).
- See also [twigLint.md](twigLint.md), which checks templates *parse*; this lane checks how they
  are written.
