# Composer Checks

**Identifier**: `phpqaci.composerChecks`

An always-on set of Composer hygiene steps: diagnose the installation, require the
`ergebnis/composer-normalize` plugin, keep `composer.json` normalised, and dump the autoloader.

## What it is about

A `composer.json` that drifts in key order or formatting produces noisy diffs and hides real
dependency changes in review, and an autoloader that is out of date makes later tools (PHPStan,
PHPUnit, PHPArkitect) report missing classes that do exist. Normalising the file and dumping the
autoloader at the start of the linting phase keeps every later lane honest.

## How it runs

- In the full pipeline, in the linting phase after PSR-4 Validation.
- Standalone: `vendor/bin/qa -t com`.
- The `composer` executable is found on `PATH`; every step runs it through the configured PHP
  binary without Xdebug, from the project root.
- Steps, in order:
  1. `composer diagnose`. Informational only: a non-zero exit is reported and never fails the
     lane.
  2. `composer audit --locked --abandoned=report`. A published advisory against a locked
     dependency fails the lane; an abandoned package is reported and does not. Turn it off
     with `->withComposerAudit(false)` in `qaConfig/qa.php`, or `useComposerAudit=0` for one
     run, which an offline build needs.
  3. No `suggest` entry may name a package the project already has in `require` or
     `require-dev`.
  4. `composer config allow-plugins.ergebnis/composer-normalize` must print `true`. Otherwise
     the lane fails with the guidance below.
  5. **Read-only run** (`QA_READONLY=1`, GitHub Actions): `composer normalize --dry-run`. A
     pending change fails the lane with the standard "pending changes in a READ-ONLY run"
     guidance. **Writable run**: `composer normalize` applies the change.
  6. `composer dump-autoload`, in every mode. It only regenerates files under `vendor/`, so it
     is safe in a read-only run.

## How to fix a failure

**A known advisory**: update the affected package to a patched release and commit the lock.
Where no patched release exists yet, record an explicit exception under `config.audit.ignore`
in `composer.json`, naming the advisory id and why it is accepted.

**A redundant suggestion**: delete the entry from `suggest`. A suggestion is advice to install
something the consumer does not already get, so advice they cannot act on is noise, and noise
in that block trains the reader to skip the rest of it. If the package should be optional
rather than required, move it out of `require`/`require-dev` instead.

Note what this step does **not** check: whether a suggested package is abandoned or
unmaintained. That needs the package registry, and the pipeline fetches nothing at run time.
`composer audit` covers abandonment for packages that are actually installed, which a
suggestion by definition is not.

**Plugin not allowed**: add the plugin to your project's `composer.json` and refresh the lock:

```json
{
    "config": {
        "allow-plugins": {
            "ergebnis/composer-normalize": true
        }
    }
}
```

```bash
composer update nothing
```

**Pending normalisation in a read-only run**: apply it where writes are allowed and commit:

```bash
QA_READONLY=0 vendor/bin/qa -t com
git add -A && git commit
```

## Implementation

- Lane: [`ComposerChecksTool`](../../src/Pipeline/Lane/ComposerChecksTool.php).
