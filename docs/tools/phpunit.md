# PHPQA PHPUnit

Full documentation about how you can use and configure PHPUnit in your PHPQA project.

PHPUnit is installed as a Composer dependency (not a PHAR).

## Iterative Mode

When you have tests that are failing and you are working towards getting everything green, try iterative mode:

```bash
vendor/bin/qa -t uniterate
```

This will run PHPUnit in isolation. The first run will be a full run (unless you have done one previously). Subsequent runs will run your failed tests first and will stop on the first error.

This allows you to quickly iterate on your test suite and push it towards getting everything passing.

## Quick Tests

There is an environment variable for PHPUnit called `phpUnitQuickTests`.

Using this, you can allow your tests to take a different path, skip tests etc if they are long running.

```php
<?php

declare(strict_types=1);

use LTS\PHPQA\Constants;
use PHPUnit\Framework\TestCase;

final class MyTest extends TestCase
{
    protected function setUp(): void
    {
        if (
            isset($_SERVER[Constants::QA_QUICK_TESTS_KEY])
            && (int) $_SERVER[Constants::QA_QUICK_TESTS_KEY] === Constants::QA_QUICK_TESTS_ENABLED
        ) {
            return;
        }
        // unnecessary setup stuff if not doing long running tests
    }

    public function testLongRunningThing(): void
    {
        if (
            isset($_SERVER[Constants::QA_QUICK_TESTS_KEY])
            && (int) $_SERVER[Constants::QA_QUICK_TESTS_KEY] === Constants::QA_QUICK_TESTS_ENABLED
        ) {
            self::markTestSkipped('Quick tests is enabled');
        }
        // long running stuff
    }
}
```

This allows you to easily skip certain tests as part of the QA pipeline, enabling faster iteration.

Generally in CI you would always run your full suite of tests, but in local development you might decide to enable quick tests mode.

### Running With Full Tests

If you are using the quick tests approach but would like to run the full pipeline with full tests:

```bash
phpUnitQuickTests=0 vendor/bin/qa
```

## Coverage

If enabled, the PHPUnit command will generate both textual output and HTML coverage.

The coverage report will go into the project root `/var` directory as configured in [configDefaults/generic/phpunit.xml](./../../configDefaults/generic/phpunit.xml).

If you want to override the coverage report location, you will need to override this config file as normal.

You can enable the coverage report on the fly:

```bash
phpUnitCoverage=1 vendor/bin/qa
```

You might decide to do this if you are running these tests in CI, as you can see in [ci.bash](./../../ci.bash).

### Config Changes When Generating Coverage

Generating coverage can cause a dramatic speed degradation. The exact run configuration depends on
whether you are in CI:

* The suite always runs to completion — it does **not** stop on the first failure. (Stop-on-failure
  flags apply only in the separate iterative mode, `vendor/bin/qa -t uniterate`; they were
  deliberately removed from the CI coverage path so CI reports every failure.)
* Test time limits (`@small` / `@medium` / `@large`) are **only** skipped when generating coverage
  **in CI** (`CI=true`). A local coverage run (`phpUnitCoverage=1 vendor/bin/qa` with `CI` unset)
  still enforces the time limits, so long tests can hit them.

### Speed Impact of Enabling Coverage

If coverage is enabled, the tests have to be run with Xdebug enabled. This on its own has a dramatic impact on the speed of PHP execution.

This is in addition to the time required to actually generate and write the coverage reports. For a large test suite the time impact can be significant.

### Persistently Setting Coverage or Quick Tests

If in your development session you want to configure PHPUnit to run as quickly as possible, you can disable coverage persistently:

#### For Fastest Iterations

```bash
export phpUnitQuickTests=1
```

Then every time you run `vendor/bin/qa` it will be as if you ran it like `phpUnitQuickTests=1 vendor/bin/qa`.

#### For Most Comprehensive Checking

For the most comprehensive checking, you need coverage enabled and quick tests disabled:

```bash
export phpUnitQuickTests=0
export phpUnitCoverage=1
```

## Paratest

You can run multiple sets of PHPUnit tests in parallel using [paratest](https://github.com/paratestphp/paratest).

Currently this is experimental and will not work in all situations.

To enable paratest, simply install it. If it is found, the QA process will use it:

```bash
composer require --dev brianium/paratest
```

## PHPUnit and PHPStan

The `phpstan-phpunit` extension is bundled with php-qa-ci and auto-loaded via the extension installer. This allows you to properly use mocks with PHPUnit tests and keep PHPStan happy.

Read the [PHPQA PHPStan docs](./phpstan.md) for more information.

## How the lane runs

The lane is `LTS\PHPQA\Pipeline\Lane\PhpunitTool` (identifier `phpqaci.phpunit`); the argv it builds lives in `PhpunitArguments` so every mode is unit-tested without running anything.

1. A missing `tests/bootstrap.php` is seeded with a documented placeholder.
2. The project's `vendor/bin/phpunit` is probed for its major version; `vendor/bin/paratest` is used instead when installed.
3. With coverage on, the Xdebug-enabled PHP binary runs under `XDEBUG_MODE=coverage` (so Infection can reuse the result); otherwise Xdebug is stripped and `XDEBUG_MODE=off`. `phpUnitQuickTests` is passed through as `1`/`0`.
4. Flags: `--strict-global-state --fail-on-risky --fail-on-warning --log-junit`, the PHPUnit 10+ `--display-*` flags, then the mode flags (iterative, no-coverage, coverage in CI, coverage interactively) and the `-p` paths.
5. An absent or empty junit log ("no tests have been run") fails the lane. Both `phpunit.junit.xml` and `phpunit.log` are archived under `var/qa/phpunit_logs`, and the `Tests: … Assertions: …` summary line is echoed.
6. Exit 0 passes, 1 and 2 fail (retried interactively), anything higher is a crash: the suite is re-run once with `--debug` for diagnosis and never retried.

## Infection

Another tool that runs your PHPUnit tests is Infection. This will only run if Xdebug is enabled and you have configured PHPUnit to generate coverage. Infection runs as a PHAR.

See the [PHPQA Infection docs](./infection.md) for more information.
