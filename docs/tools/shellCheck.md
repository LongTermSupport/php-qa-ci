# ShellCheck

**Identifier**: `phpqaci.shellCheck`

A check that every git-tracked shell script in the project passes ShellCheck at `warning`
severity, using the pinned static binary php-qa-ci ships.

## What it is about

PHP projects carry Bash: CI wrappers, deploy scripts, git hooks, `bin/` stubs. Nothing else in
the pipeline reads a shell script — `phpLint` parses PHP only — so a defect there is found by
whatever the CI runner happens to do, if anything.

That is not a hypothetical. php-qa-ci itself ran ShellCheck as a separate GitHub Actions job, and
an SC2034 in `scripts/build-phar.bash` sat red on the default branch while every local full
pipeline reported exit 0. Every PR was unmergeable and no local run could say why. A green
`bin/qa` has to mean a green branch, which means the check belongs in the pipeline, not beside it.

## The binary is vendored and pinned

Three ShellCheck versions were in play when this lane was written: one pinned in the CI job, one
from the development host's package manager, one upstream. A check whose version depends on where
it runs is not the same check — a finding appears or disappears with the host.

So the official static `linux.x86_64` build is committed at `vendor-bin/shellcheck`, with its
release tag in `vendor-bin/shellcheck.version`. The lane compares the two on every run and
**crashes** on a mismatch rather than reporting a verdict from an unknown build. It is not a PHAR
because it is not PHP: ShellCheck is a compiled Haskell executable.

Maintainers refresh it through `bin/shellcheck-install update`, which `scripts/tool-install.bash`
calls, and which `composer update` and the scheduled `update-deps.yml` workflow already run. That
updater is PHP rather than Bash — an HTTP release lookup and a `PharData` extract, with the
decision table unit-tested — per the "real scripting is PHP" rule in `CLAUDE.md`.

## What gets checked

By default, every **git-tracked** file that either

- carries a shell extension (`.bash`, `.sh`), or
- opens with a shell shebang (`sh`, `bash`, `dash`, `ksh`, directly or through `env`).

The shebang route is what finds extensionless wrappers such as `bin/phpstan` without anyone
listing them. Tracking is the contract, so untracked scratch is never checked and a new script is
covered the day it is committed.

A project that wants a different set names it in `qaConfig/qa.php`:

```php
return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
    ->withShellCheckGlobs('scripts/*.bash', 'deploy/*');
```

Globs replace discovery entirely — the project has said which files it means. `*` crosses
directory separators, so `scripts/*.bash` covers nested directories too. `withIgnoredPaths()`
still subtracts from whichever set was produced.

A glob list that matches nothing **fails the lane**. Silence from a hand-written list is a broken
configuration, not a clean result.

## How it runs

- In the full pipeline, last in the linting phase.
- Standalone: `vendor/bin/qa -t shellCheck` (aliases `-t sc`, `-t shellcheck`).
- `-p <path>` narrows the discovered set to files under that path.
- One invocation: `vendor-bin/shellcheck --severity=warning -- <files...>` from the project root.
- Exit `0` passes, exit `1` is findings and fails, anything else is a **crash** — ShellCheck
  returns `2` when it cannot read a file, and reporting that as a finding would hide a broken
  checkout behind a fixable-looking failure.
- A project that is not a git work tree skips with a reason: there is no tracked file set to
  check against.

## How to fix a failure

ShellCheck names the file, line and `SCxxxx` code, and every code has a wiki page at
`https://www.shellcheck.net/wiki/SCxxxx`. Fix the script.

Where a finding is genuinely a false positive, a targeted `# shellcheck disable=SCxxxx` directive
**with a justification comment** goes immediately above the line — never a blanket disable at the
top of a file, and never a lowered severity. Note that a variable only read by a `source`d script
looks unused to ShellCheck; exporting it states the contract instead of hiding the warning.

## Implementation

- Lane: [`ShellCheckTool`](../../src/Pipeline/Lane/ShellCheckTool.php).
- Discovery: [`ShellFileFinder`](../../src/Pipeline/Lane/ShellCheck/ShellFileFinder.php).
- The pin, the path and the fetch command: [`ShellCheckBinary`](../../src/Pipeline/Config/ShellCheckBinary.php).
