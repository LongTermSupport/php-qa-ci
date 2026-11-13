# Version Pins Check

**Identifier**: `phpqaci.versionPins`

An always-on check that every version pin in the project's QA configuration matches the toolchain
actually in use, that a workflow running on `pull_request` stops when the pull request head is
behind its base (see "Up-to-date-with-base gate" below), and that a shipped workflow template
which selects phase groups runs every one or says why not (see "Phase groups in a shipped
template" below). One lane covers three pins:

| Pin                                                                                                                         | Compared against                                                  | Detector                                                                             |
| --------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| phpunit.xml's `xsi:noNamespaceSchemaLocation` URL and any `SYMFONY_PHPUNIT_VERSION` `<server>`/`<env>` pin                  | the installed PHPUnit major (`PHPUnit\Runner\Version::id()`)      | [`PhpUnitConfigDetector`](../../src/VersionPins/PhpUnitConfigDetector.php)           |
| composer-require-checker `scan-files` entries under `thecodingmachine/safe/generated/<x.y>/`                                | the generated file safe's own dispatcher loads on the running PHP | [`SafeScanFilesDetector`](../../src/VersionPins/SafeScanFilesDetector.php)           |
| the PHP version lists and fallback defaults in GitHub Actions workflows (`.github/workflows/`, `templates/github-actions/`) | the major.minor in `composer.json`'s `require.php`                | [`WorkflowPhpVersionDetector`](../../src/VersionPins/WorkflowPhpVersionDetector.php) |

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
  other jobs.

Fix it by adding the gate step right after the checkout (which needs `fetch-depth: 0`), or a
`base-gate` job that the expensive jobs list under `needs:`; the shipped `php-qa-ci.yml` and
`qa-autofix.yml` templates show both. Findings carry the same identifier as the version pins.

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
