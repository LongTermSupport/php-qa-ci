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
  2. `composer config allow-plugins.ergebnis/composer-normalize` must print `true`. Otherwise
     the lane fails with the guidance below.
  3. **Read-only run** (`QA_READONLY=1`, GitHub Actions): `composer normalize --dry-run`. A
     pending change fails the lane with the standard "pending changes in a READ-ONLY run"
     guidance. **Writable run**: `composer normalize` applies the change.
  4. `composer dump-autoload`, in every mode. It only regenerates files under `vendor/`, so it
     is safe in a read-only run.

## How to fix a failure

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
