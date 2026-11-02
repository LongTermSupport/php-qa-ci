# Composer Checks

**Identifier**: `phpqaci.composerChecks`

An always-on set of Composer hygiene steps: diagnose the installation, audit the lock, keep
`composer.json` normalised with the shipped composer-normalize PHAR, and dump the autoloader.

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
  2. The cooldown policy check, described [below](#the-cooldown-policy). Report-only unless
     enforcement is switched on.
  3. `composer audit --locked --abandoned=report`. A published advisory against a locked
     dependency fails the lane; an abandoned package is reported and does not. Turn it off
     with `->withComposerAudit(false)` in `qaConfig/qa.php`, or `useComposerAudit=0` for one
     run, which an offline build needs.
  4. No `suggest` entry may name a package the project already has in `require` or
     `require-dev`.
  5. **Read-only run** (`QA_READONLY=1`, GitHub Actions):
     `vendor-phar/composer-normalize.phar --dry-run composer.json`. A pending change fails the
     lane with the standard "pending changes in a READ-ONLY run" guidance. **Writable run**: the
     PHAR applies the change. The PHAR bundles Composer and the normalize command, so the
     project needs no `ergebnis/composer-normalize` package and no `allow-plugins` entry for it.
  6. `composer dump-autoload`, in every mode. It only regenerates files under `vendor/`, so it
     is safe in a read-only run.

## The cooldown policy

Composer 2.11 can withhold a package version until it has been published for a while
(`config.policy.cooldown`), which gives the community time to catch a compromised release before
a project installs it. The protection exists only if the installed Composer applies it and
`composer.json` asks for it. The lane reads both and prints a finding for each gap. It runs a
`composer --version` and reads `composer.json`; it fetches nothing.

The correct construction:

```json
{
    "name": "acme/app",
    "config": {
        "policy": {
            "cooldown": {
                "period": "7 days",
                "ignore": {
                    "acme/*": "our own packages are released by us, not by a stranger"
                }
            }
        }
    }
}
```

### Findings

- Composer older than 2.11, or a version that cannot be read: the cooldown is silently ignored.
- `config.policy.cooldown.period` unset, `null`, empty, zero or not a form Composer accepts (a
  string such as `"7 days"`, or an integer of seconds).
- The cooldown switched off in the file: `config.policy` is `false`, `config.policy.cooldown` is
  `false`, or `config.policy.cooldown.block` is `false`.
- No `config.policy` at all: "no cooldown configured".
- An internal vendor not exempted. The vendor of the root package `name` is internal (a root
  without a `name` has none), and `withComposerCooldownInternalVendors('acme-tools')` names
  further ones. Each needs a `<vendor>/*` entry in `ignore` with a non-empty reason, so the
  project's own releases do not wait out a delay meant for strangers' packages. A glob is
  allowed only for an internal vendor. `ignore` is either a list of names (which cannot carry a
  reason, so it is a finding for an internal vendor) or a map of pattern to a reason; a reason
  given as an object needs a non-empty `reason`, no `constraint` and `on-block` left on.
- A `config.policy`, `cooldown` or `ignore` of a shape this check does not recognise is reported
  by name, never guessed at.

### Security overrides

Every `ignore` entry that is not an internal-vendor glob is a security override, for a fix that
is still inside the cooldown. It must be one exact package, and its reason must be exactly:

```text
SECURITY <advisory id>: fixed in <version>; PR <url>; remove after <YYYY-MM-DD>
```

for example:

```json
"ignore": {
    "acme/*": "our own packages are released by us, not by a stranger",
    "thirdparty/lib": "SECURITY CVE-2026-1234: fixed in 1.2.3; PR https://example.com/acme/app/pull/7; remove after 2026-11-20"
}
```

The advisory id is a `CVE-`, `GHSA-` or `PKSA-` identifier, the version a version string, the PR
an `http(s)` URL and the date a real calendar date. The lane lists every override on every run
(package, advisory, fixed version, remove-after date), so every recognised entry is shown, and
reports one whose reason is not in this form, one that names a glob, one whose date is not a real
date, and one whose date is before today. An entry whose shape the check does not recognise is
listed as such and reported. Today is the current UTC date, and a date equal to today has not
passed. The keyword, the advisory prefix and `fixed in` are matched exactly, in the case shown,
and the reason ends at the date.

An override may be a plain string, an object with a `reason`, or a list of such objects, as an
internal-vendor entry may; a list passes when one of its rules does. It must exempt the package
from the cooldown whatever the version, so an object with `"on-block": false` or a `constraint`
is a finding: a security override is a plain exact-name entry.

The precondition is a review rule, not something the lane runs: use an override only when
`composer audit` flags the locked version of that package, and remove it when the date arrives.

### Report-only, enforcement and opting out

The findings are printed and the exit status is unchanged until the project asks for more:

- `->withComposerCooldownEnforced(true)` in `qaConfig/qa.php`, or `enforceComposerCooldown=1` for
  one run, makes the findings fail the lane.
- `->withoutComposerCooldownCheck('<reason>')` opts the project out of the whole check. The
  reason must not be empty (an empty one is refused at load time) and is printed on every run.
  An opted-out project's `composer.json` is not read for this check.

A `composer.json` that cannot be read (missing, or not valid JSON) is a finding named
`composer.json could not be read: ...`, treated like any other: report-only never fails the lane
for it, and an enforced lane fails after the audit and the later steps have run, so their results
are reported with it, not hidden by it. The redundant-suggest step skips itself on such a file, and
the normalise step reports it.

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

**Pending normalisation in a read-only run**: apply it where writes are allowed and commit:

```bash
QA_READONLY=0 vendor/bin/qa -t com
git add -A && git commit
```

## Implementation

- Lane: [`ComposerChecksTool`](../../src/Pipeline/Lane/ComposerChecksTool.php).
