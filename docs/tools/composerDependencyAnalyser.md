# Composer Dependency Analyser

**Identifier**: `phpqaci.composerDependencyAnalyser`

An always-on check that every package `composer.json` declares is actually used, that every
package the code uses is actually declared, and that neither list is on the wrong side of the
`require` / `require-dev` line.

## What it is about

[Composer Require Checker](composerRequireChecker.md) answers one direction of the question:
does every symbol production code uses come from a declared package? It cannot answer the
other direction, because a package that nothing uses produces no symbol to trace. So a
dependency can be added, its usage deleted a year later, and nothing in the pipeline will ever
mention it again — it just keeps being installed, keeps being resolved during `composer update`,
and keeps widening the surface a security advisory can land on.

This lane closes that direction. It reads the same `composer.json` and reports:

| Finding | Meaning |
| --- | --- |
| Unused dependency | declared, but no scanned file uses it |
| Shadow dependency | used, but only installed because something else depends on it |
| Dev dependency in prod | production code uses a `require-dev` package |
| Prod dependency only in dev | in `require`, but only tests use it |
| Unknown class / function | the symbol could not be autoloaded, so nothing about it was checked |

The last row is the one worth understanding: an unknown symbol is not a pass, it is an
*unanswered question*. Left unconfigured it grows until the report is noise.

## How it runs

- In the full pipeline, in the linting phase immediately after Composer Require Checker, which
  is where the two halves of the same question belong together.
- Standalone: `vendor/bin/qa -t cda`.
- Runs the shipped `vendor-phar/composer-dependency-analyser.phar`; its presence is verified
  at the start of every run with the other PHARs.
- Configuration resolves through the usual three levels; the shipped default is
  [configDefaults/generic/composer-dependency-analyser.php](../../configDefaults/generic/composer-dependency-analyser.php).
  A project copy at `qaConfig/composer-dependency-analyser.php` **replaces** it outright.
- A project copy lives under `qaConfig/`, which is autoloaded and therefore scanned, and it
  names the analyser's own `Configuration` and `ErrorType` classes, which come from the PHAR
  rather than from any Composer package. Exclude the file from its own scan with
  `->addPathToExclude(__FILE__)` or that `use` is reported as an unknown class.
- The tool exits `1` for an ignore that nothing matched. The shipped default switches that off
  because it serves every project; a project copy should leave it on so a stale ignore is
  noticed.

### The exit code is ambiguous

The tool returns `0` when clean and `1` both when it has findings and when it could not run at
all. There is no third code, so the lane cannot distinguish a finding from a crash and reports
every non-zero exit as a failure. When the lane fails, read the output before the guidance: a
red `Error:` line means nothing was analysed and the config or `composer.json` path is wrong.

## How to fix a failure

**Unused dependency**: remove it. If it is genuinely used in a way no static scan can see — a
Composer plugin, a binary the pipeline invokes, a PHP extension a tool needs at run time — keep
it and record why:

```php
->ignoreErrorsOnPackage('ergebnis/composer-normalize', [ErrorType::UNUSED_DEPENDENCY])
```

That is not a suppression in the sense the pipeline forbids elsewhere: the package is genuinely
required, and the entry states the reason in a file under review. Deleting the check and leaving
the package unexplained is the thing to avoid.

**Shadow dependency**: require it explicitly. The package that pulls it in today is under no
obligation to keep doing so, and a minor release of an unrelated dependency can remove it.

**Dev dependency in prod**: a `composer install --no-dev` will not have it, so production
crashes with class-not-found. Move the package to `require`, or move the usage out of `src/`.

**Prod dependency only in dev**: move it to `require-dev` so production installs stop carrying
it.

**Unknown class or function**: work out where the symbol comes from. If it is supplied by a PHAR
the pipeline ships rather than by a Composer package — the PHPStan and PHPArkitect classes a
`qaConfig/` file references are the common case — exclude it deliberately:

```php
->ignoreUnknownClassesRegex('#^PHPStan\\\\#')
```

The shipped default already does this for everything under `qaConfig/`.

## Relationship to Composer Require Checker

Both lanes run, and neither replaces the other. Require Checker reads the symbols and asks
which package should have been declared; this lane reads the declarations and asks which are
earning their place. A project that passes one can fail the other, which is the point of
running both.

## Implementation

- Lane: [`ComposerDependencyAnalyserTool`](../../src/Pipeline/Lane/ComposerDependencyAnalyserTool.php).
- Upstream: [shipmonk/composer-dependency-analyser](https://github.com/shipmonk-rnd/composer-dependency-analyser).
  It publishes no PHAR, so `scripts/build-phar.bash composer-dependency-analyser` boxes it from
  the `build/composer-dependency-analyser/` manifest.
