# Dependency Freshness

**Identifier**: `phpqaci.dependencyFreshness`

The first step of every full `bin/qa` run. It checks that the locked packages you have chosen to
keep current are what `composer update` would install today. If they are not, the run stops
before any other tool, because there is no point checking code with an out-of-date QA toolchain,
or against out-of-date copies of your own packages.

## What it checks

It reads `composer.lock` and picks every locked package, production or dev, whose name matches
one of the freshness patterns. It then asks Composer, without changing anything:

```bash
composer update <matching packages> --with-dependencies --dry-run --no-interaction --no-ansi --no-scripts --no-progress --no-audit
```

The answer is given under your own `composer.json` constraints. For a branch constraint such as
`dev-php8.5`, a newer commit on the branch counts as a newer version. For a tag range such as
`^8.5`, a newer tag in the range counts.

| Composer's answer                                             | Result                                                                                                   |
| ------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| it would upgrade a matching package                           | **fails**, listing every such package with its locked and available version and commit                   |
| nothing to upgrade                                            | **passes**, with no output                                                                               |
| it could not run, could not reach a source, or did not answer | **skipped**, with a `SKIPPED dependencyFreshness` line saying why and that the lock was **not verified** |
| no `composer.lock`                                            | **skipped**, with the same named line                                                                    |
| no locked package matches                                     | **passes** without asking Composer anything                                                              |

A failure looks like this:

```text
Dependencies are out of date. `composer update` would install newer versions of:

  - lts/php-qa-ci (locked dev-php8.5 93f1ae4, available dev-php8.5 606a9f6)

Run `composer update`, then run QA again.
```

A skip never passes in silence and never fails the run. A skip happens offline, when a package
source has gone, when Composer answered from its cache because a repository could not be loaded,
or when Composer gave no answer within 180 seconds. A skip looks like this:

```text
SKIPPED dependencyFreshness (phpqaci.dependencyFreshness): could not determine whether the locked dependencies are up to date: composer exited 1: Failed to clone https://github.com/LongTermSupport/php-qa-ci.git. composer.lock was NOT verified for: lts/php-qa-ci.
```

## When it runs

- **In a full run** (`vendor/bin/qa` with no `-t`): first, after the pre hook and the run lock,
  before the coding-standards phase. A failure ends the run in a read-only (aggregate) run too,
  and is listed in its summary.
- **Alone**: `vendor/bin/qa -t dependencyFreshness` (alias `-t df`).
- **Not** in a single-tool run (`-t stan -p src/Foo.php`) or a phase run (`-t allCS`). Those are
  the inner loop of editing, often run per file from an editor hook, and the full run is the
  gate.

It runs the same way in CI. A CI run that cannot reach the package source is skipped by name
rather than failed, so a network outage does not turn a build red. A build that goes red because
a new commit has landed on the branch you track is the check working: update the lock.

## Configuration

The default list is exactly `lts/php-qa-ci`, so out of the box the check covers php-qa-ci itself.
A project adds its own packages in `qaConfig/qa.php`:

```php
return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
    ->withDependencyFreshnessPatterns('acme/*', 'other/one-library');
```

- A pattern is a lower-case `vendor/package` name, or a glob in either half: `acme/*` matches
  every `acme/` package and nothing else, `other/one-library` matches that one package and not its
  vendor's others. A `*` never crosses the `/`. A pattern that cannot name a package is refused
  when `qa.php` loads.
- `withDependencyFreshnessPatterns()` adds to the list; `withOnlyDependencyFreshnessPatterns()`
  replaces it, the earlier additions with it.
- `lts/php-qa-ci` stays on the list whatever the configuration says.

The list lives in `qaConfig/qa.php`, not in `composer.json` `extra`, because it is QA
configuration. It belongs with the other settings, as a typed method that fails at load time
when misspelt.

There is no environment variable or flag that skips the check.

## How to fix a failure

Run `composer update` (or `composer update <package> --with-dependencies` for the packages the
failure listed), run your tests against the new versions, commit `composer.lock`, and run QA
again. A failure caused by the update is a finding about the new version: report it where that
package lives rather than holding the lock back.

### The only way out is a pin, and you should not use it

The one way past the check, for a package that matches, is to pin it in `composer.json` to a tag
or a commit (`"lts/php-qa-ci": "dev-php8.5#93f1ae4"`), so that `composer update` has nothing newer
to install. The choice then stays visible in version control.

**Do not do this to make the check pass.** Running `composer update` is the fix. A pin is for a
compelling reason only, such as a known regression in the newest version. Record that reason and
its issue link with the pin, and remove the pin as soon as the reason is gone. A pinned toolchain
stops receiving the gates and fixes it exists to deliver.

## Notes

- **php-qa-ci's own repository** is `lts/php-qa-ci` as the root package, never a locked one, so
  the check asks Composer nothing there.
- **A tagged install** (`^8.5`) is checked against the newest tag in its range.
- **A path repository** is checked as Composer reports it: if Composer would move the locked
  reference, the check fails, and `composer update <package>` records the new one.
- Composer runs with `COMPOSER_NO_INTERACTION=1` and `GIT_TERMINAL_PROMPT=0`, so a credential
  prompt cannot hang the run. A private source that needs credentials Composer does not have is
  skipped by name.
- The lane is [DependencyFreshnessTool](./../../src/Pipeline/Lane/DependencyFreshnessTool.php);
  the patterns are
  [DependencyFreshnessPatterns](./../../src/Pipeline/Config/DependencyFreshnessPatterns.php).
