# Version Pins Check

**Identifier**: `phpqaci.versionPins`

An always-on check that every version pin in the project's QA configuration matches the toolchain
actually in use. One lane covers three pins:

| Pin | Compared against | Detector |
| --- | --- | --- |
| phpunit.xml's `xsi:noNamespaceSchemaLocation` URL and any `SYMFONY_PHPUNIT_VERSION` `<server>`/`<env>` pin | the installed PHPUnit major (`PHPUnit\Runner\Version::id()`) | [`PhpUnitConfigDetector`](../../src/VersionPins/PhpUnitConfigDetector.php) |
| composer-require-checker `scan-files` entries under `thecodingmachine/safe/generated/<x.y>/` | the generated file safe's own dispatcher loads on the running PHP | [`SafeScanFilesDetector`](../../src/VersionPins/SafeScanFilesDetector.php) |
| the PHP version lists and fallback defaults in GitHub Actions workflows (`.github/workflows/`, `templates/github-actions/`) | the major.minor in `composer.json`'s `require.php` | [`WorkflowPhpVersionDetector`](../../src/VersionPins/WorkflowPhpVersionDetector.php) |

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

## How it runs

- In the full pipeline, in the linting phase, immediately after the Infection config source
  directories check.
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

- Decisions: the three detectors above, each pure over text and a version string.
- Runner: [`VersionPinsCheck`](../../src/VersionPins/VersionPinsCheck.php), which reads the
  config files, prefixes each finding with the file it came from, maps the verdict to output and
  exit code, and prints the identifier.
