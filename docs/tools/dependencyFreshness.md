# Dependency Freshness

**Identifier**: `phpqaci.dependencyFreshness`

The first step of every full `bin/qa` run. It checks that the locked packages you have chosen to
keep current are what `composer update` would install today. If they are not, the run stops
before any other tool, because there is no point checking code with an out-of-date QA toolchain,
or against out-of-date copies of your own packages. The same lane also fails a first-party
dependency whose constraint excludes a newer release, see [First-party constraints](#first-party-constraints).

## What it checks

It reads `composer.lock` and picks every locked package, production or dev, whose name matches
one of the freshness patterns. It then asks Composer, without changing anything:

```bash
composer update <matching packages> --with-dependencies --dry-run --no-interaction --no-ansi --no-scripts --no-progress --no-audit
```

The answer is given under your own `composer.json` constraints. What a package is judged against
is what your composer configuration makes it follow:

| The constraint names                                                             | Judged against                                                                                                          | Asked with                                                                                  |
| -------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| branches only (`dev-php8.5`, `dev-php8.5@dev`, `dev-php8.5 as 1.0.0`, `1.x-dev`) | the head of that branch, whatever the lock is on                                                                        | the plain command                                                                           |
| release tags only (`^8.5`, `~8.5.0`, `8.5.1`, `>=8.5`)                           | the newest tag within the constraint, whatever the lock is on                                                           | `--prefer-stable`                                                                           |
| a branch together with tags (`dev-php8.5@dev \|\| ^8.5`)                         | decided by the **locked version**: on a branch, the head of that branch; on a tag, the newest tag within the constraint | on a branch, the constraint reduced to its branch alternatives; on a tag, `--prefer-stable` |
| any version (`*`, `*@dev`), or a package you do not require directly             | decided by the **locked version**: a `dev-` or `-dev` version is a branch, anything else a tag                          | on a branch, the plain command; on a tag, `--prefer-stable`                                 |

A package is never judged against the other kind: a tag-following lock is not failed because its
branch has moved on, and a branch-following lock is not failed because a tag exists. Under a
mixed constraint a lock on a branch head passes while it is at the head, even when the head is
ahead of the newest tag, and a lock on a tag passes while it is on the newest tag the constraint
allows. The kind is read from the constraint's `||` alternatives, ignoring a `#commit`, an
`@stability` flag and an `as` alias; `*` admits both.

Composer prefers a tag to a branch head under a mixed constraint, so a package locked on the
branch is asked with a throwaway copy of `composer.json` and `composer.lock` (named
`.qa-freshness-<random>.json` and `.lock`, beside the real files, removed straight after) that
requires it by its branch alternatives alone. Your real files are not touched.

A package that follows tags is asked with `--prefer-stable` so that, with `minimum-stability` set
to `dev`, Composer answers with the newest tag and not with a branch your constraint also
admits. Your `minimum-stability` and constraint still decide what is installable.

| Composer's answer                                                                          | Result                                                                                                   |
| ------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------- |
| it would upgrade a matching package                                                        | **fails**, listing every such package with its locked and available version and commit                   |
| it answered and nothing matching is to be upgraded                                         | **passes**, with no message from the check                                                               |
| it could not run, could not reach a source, did not answer, or gave no recognisable answer | **skipped**, with a `SKIPPED dependencyFreshness` line saying why and that the lock was **not verified** |
| no `composer.lock`                                                                         | **skipped**, with the same named line                                                                    |
| no locked package matches                                                                  | **passes** without asking Composer anything                                                              |

A pass rests on a positive sign that Composer answered, never on an exit code alone. Composer's
resolver prints `Updating dependencies` and then either `Nothing to modify in lock file` or
`Lock file operations: ...`; a run that exits 0 having printed none of those lines is not an answer.
The usual cause is a `composer` on the `PATH` that is a shell script (a version-manager shim or a
wrapper): the check runs Composer through `php -f`, which prints a shell script's text and exits 0
without Composer having run. The skip line quotes the first line that was printed, so the shim is
easy to recognise. Point `PATH` at the real `composer` (a PHAR or a PHP script), then run QA again.

A failure looks like this:

```text
Dependencies are out of date. `composer update` would install newer versions of:

  - lts/php-qa-ci (locked dev-php8.5 93f1ae4, available dev-php8.5 606a9f6)

Run `composer update`, then run QA again. There is no point checking code with
an out-of-date toolchain, or against out-of-date copies of your own packages.

Do NOT pin a package in composer.json to make this check pass. A pin to a tag or
a commit leaves `composer update` nothing newer to install, so it is the only way
past this check, and it is for a compelling reason only, such as a known
regression in the newest version. Record that reason and its issue link with the
pin, and remove the pin as soon as the reason is gone.
🪪  phpqaci.dependencyFreshness  (vendor/bin/rule-doc phpqaci.dependencyFreshness)
```

A skip never passes in silence and never fails the run. A skip happens offline, when a package
source has gone, when Composer answered from its cache because a repository could not be loaded,
when Composer gave no answer within 180 seconds, or when Composer's output holds no resolver line
at all. A skip looks like this:

```text
SKIPPED dependencyFreshness (phpqaci.dependencyFreshness): could not determine whether the locked dependencies are up to date: composer failed (exit 1): Failed to clone https://github.com/LongTermSupport/php-qa-ci.git. composer.lock was NOT verified for: lts/php-qa-ci.
```

A `composer` that is a shell script looks like this:

```text
SKIPPED dependencyFreshness (phpqaci.dependencyFreshness): could not determine whether the locked dependencies are up to date: composer gave no recognisable answer (it printed: exec /usr/bin/composer "$@"). composer.lock was NOT verified for: lts/php-qa-ci.
```

A failure for a package that follows tags names the remedy that clears it, since a plain
`composer update` could move the lock to a branch instead:

```text
Dependencies are out of date. `composer update` would install newer versions of:

  - acme/library (locked 1.0.0, available 1.1.0)

For the packages that follow release tags, run `composer update acme/library --with-dependencies --prefer-stable`.
The newest tag within your constraint is what counts, and `--prefer-stable` keeps
Composer from choosing a branch your constraint also admits. Then run QA again.
```

A branch lock under a mixed constraint cannot be moved by `composer update`, which would choose
a tag that may be older than the lock. Its failure names the command that follows the branch
instead and never moves a lock backwards:

```text
  composer require 'acme/library:dev-php8.5@dev' --with-dependencies
```

(`--dev` is added for a package in `require-dev`.) That makes the constraint branch-only. To follow
releases rather than the branch, require a tag range and run the tag remedy above.

Branch-following and tag-following packages are asked in separate dry runs; when a package source
cannot be reached for one of them, that one is skipped by name, and a stale answer from the other
still fails the run.

### Composer's cooldown policy

The lane follows the project's `config.policy.cooldown` (Composer 2.11) and never passes
`--no-cooldown`: a package is behind only when a newer version is installable under the policy.

- A newer version the cooldown withholds is not available yet, so it does not fail the run.
- When the cooldown withholds every version a package may take, the locked one included, Composer
  cannot resolve at all (exit 2). That is not a skip and not a failure: the lane prints
  `NOTE dependencyFreshness: <package> is held back by the cooldown policy` and carries on. This
  applies only when every Problem Composer printed is such a refusal of a package the lane asked
  about; a conflict beside it, a refusal of a dependency, or any other exit status is a skip by
  name as for any failed run. When several packages were asked together, each is then asked alone
  (the worst case for N packages is 1 + N Composer runs, each with its own timeout), so a withheld package
  cannot hide another that is behind, and a real failure of one is still named as not verified.
- A package on `policy.cooldown.ignore` (`vendor/*` wildcards included) is not withheld, so it is
  judged immediately, as for a project with no policy.

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
a new commit has landed on the branch you track, or a new release tag within the range you track,
is the check working: update the lock. To follow releases rather than a branch, require a tag
range (`~8.5.0` or `^8.5`) instead of the branch.

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

There is no environment variable or flag that skips the lock check. The
[first-party constraint check](#first-party-constraints) below has its own switch.

## First-party constraints

The lock check judges the lock only within your own constraints. It cannot see a constraint that
is itself the problem: with `"acme/widgets": "^0.2"` and a release 0.3.0 out, `composer update`
can never move to 0.3.0, the lock check reports nothing, and the project keeps installing, shipping
and testing the old version. The same lane therefore also checks the constraints on your
first-party packages, on by default.

**Who is first party.** The vendors you name in `composer.json`:

```json
{
    "name": "acme/app",
    "extra": {
        "php-qa-ci": {
            "firstPartyVendors": ["acme", "acme-labs"]
        }
    }
}
```

Without that key the first-party vendor is your own: the part of `name` before the slash (`acme`
for `acme/app`). A project with no `name` and no key has no first-party vendors; the lane prints
`NOTE dependencyFreshness: no first-party vendors: composer.json has no "name" and ...` and checks
no constraint. An empty list (`"firstPartyVendors": []`) also means none, and says so. A value that
is not a list of vendor names (a package name such as `acme/widgets` is not one) fails the lane
with an `ERROR` line rather than being guessed at. The same setting chooses the vendor clones the
git pre-commit hook protects ([README](../../README.md#the-git-pre-commit-hook)).

**What is checked.** Every package in `require` or `require-dev` whose vendor is first party. Packages
that are only transitive are not. One `composer outdated --locked --direct --format=json` gives
the newest stable release Composer would consider for each; the lane reads your constraint with
Composer's own semver library and sets the two side by side:

| The constraint                                                                                  | A newer stable release it excludes exists                         |
| ----------------------------------------------------------------------------------------------- | ----------------------------------------------------------------- |
| loose (`^0.2`, `~1.2.0`, `>=0.2 <0.3`, `0.2.*`, a range with `\|\|`)                            | **fails**, naming the package, constraint, newest release, remedy |
| admits the newest release (`^0.2 \|\| ^0.3`, `>=0.2`, `*`)                                      | passes: a newer release inside the constraint is the lock check's |
| an **exact pin**: one version (`0.2.3`, `=0.2.3`, `v0.2.3`, `1.2`, `0.2.3@stable`, `0.2.3#ref`) | **reported, does not fail**                                       |
| a branch (`dev-main`, `1.x-dev`, `dev-main as 1.0.0`, `dev-main@dev`)                           | left to the lock check; never failed here, never reported         |
| one Composer's parser cannot read                                                               | **skipped** by name                                               |

A new 0.x minor (`^0.2` and 0.3.0) and a new major (`^1.4` and 2.0.0) both count: the caret
excludes them. Only a stable release counts as newer; a prerelease or a branch head does not. A
release Composer's cooldown policy still withholds is not offered by `composer outdated` and so is
not a finding.

A failure looks like this:

```text
First-party dependency constraints exclude newer releases:

  - acme/widgets: constraint "^0.2" excludes the newest release 0.3.0 (locked 0.2.1)
      widen it to "^0.3", or to "^0.2 || ^0.3" to keep admitting the old line
      or, to stay on purpose, pin it exactly: "0.2.1"

`composer update` can never move to a release the constraint excludes, so the project
keeps installing, shipping and testing the old version. Widen each constraint in
composer.json, run `composer update acme/widgets --with-dependencies`, then run QA again.
...
🪪  phpqaci.dependencyFreshness  (vendor/bin/rule-doc phpqaci.dependencyFreshness)
```

The escape hatch is the exact pin: staying on an old release on purpose is then a decision visible
in `composer.json`. A pinned package with a newer release is named on every run:

```text
NOTE dependencyFreshness: acme/widgets is pinned exactly to "=0.2.3" and a newer release exists (0.3.0): not failed, because an exact pin is deliberate.
```

(A pin with nothing newer is not mentioned.) This differs from the lock check, where a pin is
discouraged: here widening the constraint is the fix, and the pin is the recorded decision to stay.

**When Composer cannot answer** (no network, no `composer.lock`, a failure, no answer in 180
seconds, an answer from a stale cache, output that is not Composer's JSON), the check is skipped by
name and never fails:

```text
SKIPPED dependencyFreshness (phpqaci.dependencyFreshness): could not determine whether first-party dependency constraints are current: composer did not answer within 180 seconds. first-party constraints were NOT verified for: acme/widgets.
```

**Cost.** It is quiet when everything is fine, and asks Composer nothing unless the project requires
a first-party package directly: a project whose first-party vendor has no direct dependency (this
repository's, for one) passes without a Composer call. When it does ask, it is one `composer outdated`
beside the lock check's dry runs.

**Narrow or disable it.** Narrow it by naming the vendors (`extra.php-qa-ci.firstPartyVendors`
above; the list replaces the own-vendor default). Turn it off with `->withFirstPartyConstraintFreshness(false)`
in `qaConfig/qa.php`, or `useFirstPartyConstraintFreshness=0` for one run. Neither touches the lock check.

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
- **A tagged install** (`^8.5`) is checked against the newest tag in its range, never against a
  branch head, however far the branch is ahead of that tag.
- **A path repository** is checked as Composer reports it: if Composer would move the locked
  reference, the check fails, and `composer update <package>` records the new one.
- Composer runs with `COMPOSER_NO_INTERACTION=1` and `GIT_TERMINAL_PROMPT=0`, so a credential
  prompt cannot hang the run. A private source that needs credentials Composer does not have is
  skipped by name.
- The lane is [DependencyFreshnessTool](./../../src/Pipeline/Lane/DependencyFreshnessTool.php);
  the patterns are
  [DependencyFreshnessPatterns](./../../src/Pipeline/Config/DependencyFreshnessPatterns.php).
  The first-party step is
  [FirstPartyConstraintFreshness](./../../src/Pipeline/Lane/DependencyFreshness/FirstPartyConstraintFreshness.php),
  with [FirstPartyVendors](./../../src/Pipeline/Lane/DependencyFreshness/FirstPartyVendors.php) and
  [FirstPartyConstraintJudge](./../../src/Pipeline/Lane/DependencyFreshness/FirstPartyConstraintJudge.php).
