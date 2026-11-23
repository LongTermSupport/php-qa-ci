# actions-qa-ci

**Identifier**: `phpqaci.actionsQa`

A check that the project's GitHub Actions workflows pass
[actions-qa-ci](https://github.com/LongTermSupport/actions-qa-ci), at the release php-qa-ci pins.
actions-qa-ci runs actionlint, ShellCheck over every `run:` script and zizmor, and reports each
finding under a stable identifier of its own (`actionsqaci.*`), with its own offline
`actions-qa rule-doc <id>`.

## What it is about

A workflow is code that runs with the repository's token. A wrong expression, an unpinned
`uses:`, an untrusted value interpolated into a script, or a permission wider than the job needs
is a defect there just as a type error is in PHP, and nothing else in the pipeline reads a
workflow. `shellCheck` checks the project's shell scripts; this lane asks a different question,
"is this workflow correct and safe", and answers it with tools built for that question.

## Correct construction

A workflow passes when actions-qa-ci reports nothing in it:

- every `${{ }}` expression parses and type-checks, and none puts untrusted input into a script:
  pass it through `env:` and quote the variable;
- every `uses:` names an action at a full commit SHA, with a comment naming the version;
- `permissions:` grants only what each job needs, read-only by default;
- every `run:` script passes ShellCheck;
- every workflow-shaped or action-shaped file the repository tracks outside `.github/workflows`
  and `.github/actions` (a template consumers copy, a fixture) is named in the project record,
  `.github/actions-qa-ci.yml`: under `analyse.include` to have it checked, or under
  `analyse.unanalysed` with the hazard and scope that make leaving it unchecked acceptable.

A finding is fixed in the workflow. The project record can declare an exception, but only with
the justification actions-qa-ci requires; an inline suppression comment, or a tool's own ignore
file, is itself a finding. `actions-qa rule-doc <id>` prints each rule's page.

## The bundle is pinned and fetched, never committed

The bundle is tens of megabytes of binaries per CPU, so php-qa-ci commits none of it. It carries
the release version and the SHA-256 of that release's `install.sh`
(`LTS\PHPQA\Pipeline\Config\ActionsQaBinary`). Install the bundle with:

```bash
php vendor/lts/php-qa-ci/bin/actions-qa-install
```

(or `vendor/bin/actions-qa-install` through Composer's bin dir). It downloads `install.sh`,
refuses it unless its SHA-256 is the pinned one, and only then runs it, with a prefix in a staging
directory beside `vendor-bin/actions-qa-ci/`. `install.sh` checks the bundle against the digest
written into it. The staged bundle must report the pinned release before it replaces the
installed one, so a failure at any step leaves the previous install as it was. Nothing is piped
into a shell. A `composer install` never runs it: the download happens only when asked for.

When the bundle is missing, or reports a release other than the pinned one, the lane **crashes**
and prints that command. One release has to run everywhere, or QA and CI are not the same check.

Linux on x86_64 (`linux_amd64`) and aarch64 (`linux_arm64`) is supported; on any other host the
lane and the installer crash naming the host.

## What gets checked

The lane runs `actions-qa check --format agent` from the project root, so actions-qa-ci decides
what to read: the workflows in `.github/workflows`, the actions in `.github/actions` and at the
root, and whatever the project record includes. A path given with `-p` does not narrow it: the
rules are cross-file (a record entry is judged against every workflow), so the lane always checks
the whole project.

A project that is not a git work tree, or that tracks nothing under `.github/workflows`, is
skipped with the reason.

## How to fix a failure

A **finding** prints the workflow file, the line and an `actionsqaci.*` identifier. Run
`actions-qa rule-doc <identifier>` (from `vendor-bin/actions-qa-ci/bin/`) for the page that states
the correct construction, then change the workflow to match it: pin the action to a full commit
SHA, move an expression out of a `run:` script into `env:`, narrow `permissions:`, or quote the
shell variable ShellCheck names. Re-run `bin/qa -t actionsQa` until it reports nothing.

A **crash** is not a finding about the workflows, and it never counts as a pass:

- *bundle not installed* or *a different release than pinned*: run
  `php vendor/lts/php-qa-ci/bin/actions-qa-install`, which prints what it fetched and verified;
- *host not supported*: the bundle exists for Linux on x86_64 and aarch64 only;
- *actions-qa-ci refused to check* (exit 2): the project record `.github/actions-qa-ci.yml` is
  invalid or names a file that does not exist; the message names the line.

A file the record should leave unchecked is declared under `analyse.unanalysed` with the hazard and
the scope, never silenced in the workflow.

## Exit codes

| actions-qa exit | Lane outcome                                   |
| --------------- | ---------------------------------------------- |
| 0               | passed                                         |
| 1               | failed: findings, printed one a line           |
| 2               | crashed: actions-qa-ci refused to check        |
| 3               | crashed: a toolchain fault                     |
| 4               | crashed: no verdict (a file nobody could read) |
| anything else   | crashed                                        |

Only exit 1 is a verdict about the workflows. No other non-zero exit is ever a pass.

## In CI

The shipped `php-qa-ci.yml` template runs the installer after `composer install`, so the full
pipeline in CI has the bundle. A workflow written by hand that runs the full pipeline needs the
same step.
