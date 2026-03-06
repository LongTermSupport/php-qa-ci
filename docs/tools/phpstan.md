# PHPQA PHPStan

Full details of how PHPStan is used with PHPQA and how you can configure it for your projects.

PHPStan runs as a **PHAR** from `vendor-phar/phpstan.phar`. The `phpstan/phpstan` Composer package is in the `replace` section of `php-qa-ci`'s `composer.json`, so the PHAR is used instead of a Composer-installed binary.

## Configuration

Default configuration is in [configDefaults/generic/phpstan.neon](./../../configDefaults/generic/phpstan.neon).

The default level is `max`.

To override the configuration, copy it to `{project-root}/qaConfig/phpstan.neon`.

Specifying paths can be a little bit tricky. You can have a look at the [qaConfig/phpstan.neon](./../../qaConfig/phpstan.neon) override file for the PHPQA project itself for an example.

### Extending Default Config

You can use the standard config as a base:

```neon
includes:
    - ../vendor/lts/php-qa-ci/configDefaults/generic/phpstan.neon
```

### Bootstrap

In the configuration you might want to specify a [PHP bootstrap file](https://github.com/phpstan/phpstan#bootstrap-file) to initialise your code.

If you place your `phpstan-bootstrap.php` in `{project-root}/tests/phpstan-bootstrap.php`, the neon file should look like:

```neon
parameters:
    bootstrap: ../tests/phpstan-bootstrap.php
```

## Bundled Extensions

PHP-QA-CI bundles these PHPStan extensions as Composer dependencies (auto-loaded via the PHPStan extension installer):

- **[phpstan-strict-rules](https://github.com/phpstan/phpstan-strict-rules)** -- Additional strict type-checking rules
- **[phpstan-phpunit](https://github.com/phpstan/phpstan-phpunit)** -- PHPUnit-aware analysis, including proper mock object support

These are configured and loaded automatically. You do not need to install or configure them separately.

## Custom PHPStan Rules

PHP-QA-CI ships custom PHPStan rules that are auto-loaded via the extension installer (defined in `rules-default.neon`):

- **ForbidMockingFinalClassRule** -- Prevents mocking of final classes in tests
- **ForbidAllowMockWithoutExpectationsRule** -- Bans `#[AllowMockObjectsWithoutExpectations]` attribute
- **ForbidDangerousFunctionsRule** -- Bans exec/eval/unserialize and similar unsafe functions
- **ForbidEmptyCatchBlockRule** -- Requires catch blocks to have a body
- **RequireDeclareStrictTypesRule** -- Requires `declare(strict_types=1)` in all PHP files

Projects can add their own custom rules in addition to these defaults.

## Strict Rules

The strict rules are brought in as a dependency and configured by default.

PHPQA uses the PHPStan extension loader. If you need to disable specific strict rules, you will need to ignore specific rule failures rather than trying to disable the strict rule set.

See [the main PHPStan strict rules docs](https://github.com/phpstan/phpstan-strict-rules) for more information.

## Suppressing Errors

[Here](https://github.com/phpstan/phpstan#ignore-error-messages-with-regular-expressions) you can read more about how to ignore errors by modifying `phpstan.neon`.

## Mock Objects in Tests

The bundled `phpstan-phpunit` extension handles PHPUnit mock objects automatically. Without it, PHPStan gets confused by mock objects:

```text
 ------ ------------------------------------------------------------------------------------------
  Line   Path/To/Class.php
 ------ ------------------------------------------------------------------------------------------
  20     Parameter #1 $logger of class Path\To\AnotherClass constructor expects
         Psr\Log\LoggerInterface, PHPUnit\Framework\MockObject\MockObject given.
 ------ ------------------------------------------------------------------------------------------
```

Since `phpstan-phpunit` is bundled, this is handled out of the box. See the [phpstan-phpunit documentation](https://github.com/phpstan/phpstan-phpunit#how-to-document-mock-objects-in-phpdocs) for how to document mock objects in your tests.

## Tips for Resolving Issues

### Can't use `empty()`

You should not use `empty()`. Instead, use more type-safe comparisons:

```php
<?php
$maybeEmptyArray = getMaybeEmptyArray();
if ([] === $maybeEmptyArray) {
    throw new \RuntimeException('the array is empty');
}
```

### Type Can Be False or Otherwise Uncertain

You need to be more explicit about the type you are dealing with. Check for false and handle it as an exception:

```php
<?php
$contents = \file_get_contents('/path/to/file');
if (false === $contents) {
    throw new \RuntimeException('Failed getting file contents');
}
// now work with $contents as a string
```

Note: if you are using `thecodingmachine/safe` (which the Rector safe-functions stage will convert you to), these functions throw exceptions instead of returning false, eliminating this class of issue.

### Only Booleans Allowed in `if` Conditions

This means you need to do something explicitly boolean, generally involving `===`:

```php
<?php
$subject = 'string containing pattern';
if (1 === \preg_match('%pa[t]{2}ern%', $subject)) {
    echo 'it matches';
}
```

### PHPUnit Dynamic Call to Static Method

The convention is often to use `$this->assertSame`, but `assertSame` is a static method. You should use `self::assertSame`.

Generally you can fix this in bulk by finding `$this->assert` and replacing with `self::assert`.

### Missing Type Hints

First try to declare a real PHP type hint. If you cannot (e.g., the type is mixed, or you are extending a third-party class), use PHPDoc annotations:

```php
<?php

/**
 * @param string $realPath
 *
 * @return \SplHeap<\SplFileInfo>
 */
private function getDirectoryIterator(string $realPath): \SplHeap
{
    // ...
}
```
