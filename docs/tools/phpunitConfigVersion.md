# PHPUnit Config Version Check

**Identifier**: `phpqaci.phpunitConfigVersion`

An always-on check that the resolved phpunit.xml's version pins match the major version of the
PHPUnit that is installed. Two pins are checked:

- the schema URL in `xsi:noNamespaceSchemaLocation`
  (`https://schema.phpunit.de/<version>/phpunit.xsd`), and
- a `SYMFONY_PHPUNIT_VERSION` `<server>` or `<env>` pin, when one is present.

## What it is about

Neither pin affects whether a test run passes. The schema URL is what IDEs and validators use
to decide which attributes are legal, and `SYMFONY_PHPUNIT_VERSION` is what symfony/phpunit-bridge
uses to choose the PHPUnit it installs. When the package's PHPUnit requirement moves on, a config
still pinned to the previous major keeps validating against and advertising a release that is
no longer the one running the tests, and nothing else in the pipeline notices.

## How it runs

- In the full pipeline, in the linting phase, immediately after the Infection config source
  directories check.
- Standalone: `vendor/bin/qa -t phpunitConfigVersion`, alias `-t pcv`.
- Binary: `bin/phpunit-config-version-check`, delegating to
  `LTS\PHPQA\PhpUnitConfig\PhpUnitConfigVersionCheck::main()`.

It is handed the same resolved phpunit.xml path the PHPUnit step runs with (the project's own
`qaConfig/phpunit.xml` override when one exists, or the shipped
`configDefaults/generic/phpunit.xml` otherwise). The installed version comes from
`PHPUnit\Runner\Version::id()`.

Only the major version is compared: a `13.0` schema against an installed `13.3.2` passes. A config
with neither pin has nothing to disagree and passes.

## How to fix a failure

Set the schema URL to the installed major.minor, as the failure message spells out:

```xml
xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/13.3/phpunit.xsd"
```

For `SYMFONY_PHPUNIT_VERSION`, set the pin to the installed major.minor or remove it if the
project does not use symfony/phpunit-bridge.

## Implementation

- Decision: [`PhpUnitConfigVersionDetector`](../../src/PhpUnitConfig/PhpUnitConfigVersionDetector.php),
  which reads the pins out of the XML text and compares majors.
- Runner: [`PhpUnitConfigVersionCheck`](../../src/PhpUnitConfig/PhpUnitConfigVersionCheck.php),
  which reads the config file, maps the verdict to output and exit code, and prints the identifier.
