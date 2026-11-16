# Version Pins Check

**Identifier**: `phpqaci.versionPins`

An always-on check that every version pin in the project's QA configuration matches the toolchain
actually in use, that a workflow running on `pull_request` or `pull_request_target` stops when the
pull request head is behind its base (see "Up-to-date-with-base gate" below), and that a shipped
workflow template which selects phase groups runs every one or says why not (see "Phase groups in
a shipped template" below). One lane covers three pins:

| Pin                                                                                                                         | Compared against                                                  | Detector                                                                             |
| --------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| phpunit.xml's `xsi:noNamespaceSchemaLocation` URL and any `SYMFONY_PHPUNIT_VERSION` `<server>`/`<env>` pin                  | the installed PHPUnit major (`PHPUnit\Runner\Version::id()`)      | [`PhpUnitConfigDetector`](../../src/VersionPins/PhpUnitConfigDetector.php)           |
| composer-require-checker `scan-files` entries under `thecodingmachine/safe/generated/<x.y>/`                                | the generated file safe's own dispatcher loads on the running PHP | [`SafeScanFilesDetector`](../../src/VersionPins/SafeScanFilesDetector.php)           |
| the PHP version lists and fallback defaults in GitHub Actions workflows (`.github/workflows/`, `templates/github-actions/`) | the major.minor in `composer.json`'s `require.php`                | [`WorkflowPhpVersionDetector`](../../src/VersionPins/WorkflowPhpVersionDetector.php) |

The lane reads the workflow files directly in `.github/workflows/` and `templates/github-actions/`
in either extension GitHub runs, `.yml` and `.yaml`, and reports a finding under the file's own
name. A file in a subdirectory, such as a composite action's `action.yml`, is not read.

## What it is about

None of these pins affects whether a test run passes, so when the PHP or PHPUnit requirement
moves on nothing else in the pipeline notices:

- A stale schema URL keeps IDEs and validators checking phpunit.xml against a release that is
  no longer running the tests; a stale `SYMFONY_PHPUNIT_VERSION` makes symfony/phpunit-bridge
  install the wrong PHPUnit.
- safe ships one dispatcher per extension (`generated/<ext>.php`) that requires a
  version-specific file chosen by `PHP_VERSION`, and the directory is not simply the running
  version: on PHP 8.5 safe loads `8.4/array.php` but `8.2/exec.php`. A `scan-files` entry naming
  any other directory whitelists a function set that is not in force, so a `\Safe\*` call can be
  reported as undeclared, or an undeclared one can slip through.
- The shipped workflows pick a runner PHP by matching the constraint against a hand-written
  list; a list without the new version quietly selects an older PHP for a consumer on the new one.

## Up-to-date-with-base gate

A pull request whose head is behind its base branch gets a QA result for a tree that will never
be merged, and the run costs the full pipeline. A workflow that runs on `pull_request` therefore
needs a step that fails when `git merge-base --is-ancestor "origin/${BASE_REF}" "${HEAD_SHA}"`
is false, with `BASE_REF` set from `github.event.pull_request.base.ref` and `HEAD_SHA` from
`github.event.pull_request.head.sha`. The check
([`WorkflowBaseGateDetector`](../../src/VersionPins/WorkflowBaseGateDetector.php)) reports:

- a `pull_request` workflow with no such gate;
- a gate placed after PHP setup, `composer install` or a `qa` run in the same job;
- a job that installs or runs anything without the gate job among its `needs:`, directly or through
  other jobs;
- a workflow whose `on` trigger the check cannot reduce to event names, since whether it runs on
  `pull_request` or `pull_request_target` is then unknown: an alias, an anchor, a tag, a merge key, a null (`on: ~`, `on: null`), a list item
  written as a mapping (`- pull_request:`) or nothing under the key. The result would be a guess,
  so it is reported; write the trigger as a scalar, a list or a mapping;
- a workflow on either event whose `jobs` key is missing, empty or written as a flow mapping
  (`jobs: {qa: {...}}`), an alias or an anchored mapping, or in which any one job is written as
  anything but its id alone on a line (`deploy: {steps: [...]}`, `deploy: *job`, `deploy: &job`). A
  job the check cannot read would be passed without being judged, so the workflow is reported
  rather than passed; write `jobs:` on a line of its own, then each job id alone on a line as an
  indented key. Spaces or a tab before a key's colon (`jobs :`, `qa :`, `on :`, `needs :`, `uses :`) are
  the same key and are read;
- a workflow that writes the `on` key twice, a `pull_request` or `pull_request_target` workflow that
  writes the `jobs` key twice (the second with a value on its line or not), or one in which two jobs
  share an id. GitHub rejects the file, and
  YAML parsers disagree on which copy applies, so judging the first would pass the second unread;
  the finding names the key or the id. Keep one.

Fix it by adding the gate step right after the checkout (which needs `fetch-depth: 0`), or a
`base-gate` job that the expensive jobs list under `needs:`; the shipped `php-qa-ci.yml` and
`qa-autofix.yml` templates show both. Findings carry the same identifier as the version pins.

### A workflow on `pull_request_target`

GitHub runs a `pull_request_target` workflow in the context of the base repository's default
branch, and an `actions/checkout` without a `ref` checks out that branch, not the pull request. The
rule for it differs from the `pull_request` one in three ways:

- A gate is asked for only when a job installs or runs something (PHP setup, `composer install`, a
  `qa` run). A workflow that only labels or comments costs seconds and needs none.

- The pull request head is not in that checkout, and for a fork it is never in the base
  repository's branches. The gate fetches the base branch and the head without checking the head
  out, and tests the fetched commit:

  ```yaml
  - name: Require the branch to be up to date with its base
    env:
      BASE_REF: ${{ github.event.pull_request.base.ref }}
      PR_NUMBER: ${{ github.event.pull_request.number }}
    run: |
      git fetch origin "${BASE_REF}"
      git fetch origin "pull/${PR_NUMBER}/head"
      git merge-base --is-ancestor "origin/${BASE_REF}" FETCH_HEAD
  ```

  The job that fetches keeps its checkout's credentials: the shipped templates check out with
  `persist-credentials: false`, and copied into a `pull_request_target` workflow that leaves
  `git fetch` without credentials, which fails on a private or internal repository (the pull
  request ref is never in a `fetch-depth: 0` checkout). The head is only read as data; checking it
  out and running it with the base repository's token is the hazard GitHub describes in
  [securely using `pull_request_target`][securely], and nothing here asks for it.

- A gate conditioned on `github.event_name == 'pull_request'`, as the gate step in the shipped
  `php-qa-ci.yml` is, is skipped on this event, so the check reports it. Drop the condition, or name
  `pull_request_target` in it. The check reads the condition as text, like the rest of the gate.

### How the workflow is read

The check reads the file as text, after removing its comments and normalising line endings: a
comment never counts as the thing it names. The `on` trigger is read in every valid shape: a scalar
(`on: pull_request`), a flow list or mapping on one line or several (a flow mapping may hold bare
names, `on: {push, pull_request}`), a block list at any indentation (including none), a block
mapping, quoted names, and a quoted `on` key. Only the first
level under `on` names events, so a branch called `pull_request` under `push` is not one.

A trigger the check cannot reduce to event names is reported, never passed: an alias, an anchor, a
tag, a merge key, a null, a list item written as a mapping, no `on` key, or nothing under it. Write
it as a scalar, a list or a mapping, without an anchor, an alias or a merge key.

Jobs are read with quoted ids, trailing comments and any consistent indentation. A `needs:` is read
at the job's own key indentation as a scalar, a flow list (over several lines too) or a block list.
A job that installs or runs something is one that sets up PHP, runs `composer install`, runs `qa`
(`bin/qa`, `vendor/bin/qa`, `composer qa`), or calls a reusable workflow with a job-level `uses:`.
The `github.event_name == 'pull_request'` condition is recognised in any spacing or letter case and
with either operand first.

A workflow that lists both events keeps the `pull_request` rule, plus the condition rule above.
`pull_request_review` and `pull_request_review_comment` are different events and are not asked for
the gate. GitHub's description of the event is in [events that trigger workflows][events].

## Phase groups in a shipped template

A workflow that runs `qa -t allCS` and `qa -t allStatic` reads as complete coverage, but the
linting phase (`allLints`) is then never run, so a green check does not mean the lint lanes ran:
a `composer.json` out of order, a stale version pin or a broken shell script merges green and is
found only by a developer's local full run. A shipped template that selects phase groups by name
must therefore run every phase the pipeline defines (`allCS`, `allLints`, `allStatic`,
`allTests`, read from the pipeline's own phases, so a phase added later is required at once),
or state that it leaves one out and why, on a comment line of its own:

```yaml
# qa-phase-deferred: allTests - a PHPUnit suite usually needs a database or services not on the runner
```

The separator may be `-`, `--`, `—` or `:`, and the reason is required. A comment that merely
mentions a group does not run it, and a template that runs the whole `qa`, or single tools, selects
no group and is not judged. The check
([`WorkflowPhaseGroupsDetector`](../../src/VersionPins/WorkflowPhaseGroupsDetector.php)) reads only
the files in a project's `templates/github-actions/` directory. That directory exists in this
repository, which ships the templates; a consuming project has it only if it ships templates of
its own, and the workflows in `.github/workflows/` are never judged, so they may split the phases
across files as they like.

The check reads a template as a whole, not job by job, and does not evaluate it: a group named by
a step in any job counts as run, including a step that never executes (`if: false`) and one that
only echoes the command.

Fix a finding by adding the step the message names (`qa -t <group>`) to the template, or by adding
the `qa-phase-deferred` line with its reason.

## How it runs

- In the full pipeline, in the linting phase, immediately after the Analysed Paths check.
- Standalone: `vendor/bin/qa -t versionPins`, alias `-t vp`.
- Binary: `bin/version-pins-check <project-root> <phpunit.xml> <composerRequireChecker.json>`,
  delegating to `LTS\PHPQA\VersionPins\VersionPinsCheck::main()`.

It is handed the same resolved config paths the PHPUnit and Composer Require Checker lanes run
with (the project's `qaConfig/` override when one exists, else the shipped default). Only majors
are compared for PHPUnit. A workflow with no version detection, a `composer.json` with no PHP
major.minor, a safe entry whose dispatcher is absent, and a config with no pins at all each have
nothing to disagree and pass. The workflow template-identity check applies only where both
`templates/github-actions/php-qa-ci.yml` and `.github/workflows/qa.yml` exist, so it is a
self-check of this repository and does not affect a consuming project.

## How to fix a failure

Each message names the file, the pin and the exact replacement:

- phpunit.xml: set the schema URL to `https://schema.phpunit.de/<major.minor>/phpunit.xsd` for the
  installed PHPUnit; set `SYMFONY_PHPUNIT_VERSION` to the same or remove it if the project does
  not use symfony/phpunit-bridge.
- composerRequireChecker.json: replace each reported entry with the file the message names, or
  remove an entry whose dispatcher has no branch for the running PHP. If QA runs under more than
  one PHP version, list the files for the version the gate runs under.
- Workflows: add the required version to the front of each reported list and set the fallback
  default to it. For the template-identity failure, copy the changed file over the other.

## Implementation

- Decisions: the three detectors above, each pure over text and a version string, and
  `WorkflowBaseGateDetector` and `WorkflowPhaseGroupsDetector`, pure over a workflow's text.
- Runner: [`VersionPinsCheck`](../../src/VersionPins/VersionPinsCheck.php), which reads the
  config files, prefixes each finding with the file it came from, maps the verdict to output and
  exit code, and prints the identifier.

[events]: https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#pull_request_target
[securely]: https://docs.github.com/en/actions/reference/security/securely-using-pull_request_target
