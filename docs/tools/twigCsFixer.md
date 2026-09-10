# Twig CS Fixer

**Identifier**: `phpqaci.twigCsFixer`

A Symfony platform lane: coding standards for Twig templates, in the coding-standards phase
alongside Rector and PHP CS Fixer.

## What it is about

PHP CS Fixer does not read Twig. On a Symfony project a large amount of presentation logic
lives in `.twig` files that no formatter in the pipeline has ever touched, so they drift into
whatever each contributor's editor does — inconsistent delimiter spacing, mixed quote styles,
stray whitespace — and every template change produces a diff bigger than the change.

This lane applies the upstream `TwigCsFixer` standard to those files, so templates get the same
treatment PHP already gets.

## How it runs

- Appended to the **coding-standards phase** on a Symfony project only (detected by
  `symfony.lock`). It is not `-t` selectable, like the other platform lanes.
- Skipped cleanly when the project is not Symfony, and when none of the configured twig
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

- **Read-only run** (`QA_READONLY=1`, GitHub Actions): check only. A fixable violation fails the
  gate with the standard "pending changes in a READ-ONLY run" guidance.
- **Writable run**: `--fix` is added and fixes are applied.

There is one wrinkle worth knowing. `--fix` **still exits non-zero when a violation it cannot
fix remains**, so a writable run that ends non-zero does not mean nothing was fixed — it means
something was left. The lane says so rather than reporting it as a plain failure.

### Exit codes

| Code | Meaning | Lane outcome |
| --- | --- | --- |
| 0 | clean | passed |
| 1 | violations present | failed |
| 2 | config error or unhandled throwable | **crashed** — never retried |

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

- Lane: [`TwigCsFixerTool`](../../src/Pipeline/Lane/TwigCsFixerTool.php).
- Upstream: [vincentlanglet/twig-cs-fixer](https://github.com/VincentLanglet/Twig-CS-Fixer).
- See also [twigLint.md](twigLint.md), which checks templates *parse*; this lane checks how they
  are written.
