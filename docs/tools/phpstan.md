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

## Optional Rules

PHP-QA-CI ships additional opt-in rules in `rules-optional.neon`. These are **not** loaded automatically — you must enable them explicitly.

### Recommended: include the whole set

Add an `includes` entry to your `qaConfig/phpstan.neon`. When new optional rules are added in future php-qa-ci versions you get them automatically without any change to your config:

```neon
includes:
    - ../vendor/lts/php-qa-ci/configDefaults/generic/phpstan.neon
    - ../vendor/lts/php-qa-ci/rules-optional.neon
```

### Alternative: enable individual rules

Copy only the rules you want into your `qaConfig/phpstan.neon`. You retain full control but must add new rules manually as they are released:

```neon
includes:
    - ../vendor/lts/php-qa-ci/configDefaults/generic/phpstan.neon

rules:
    # Bans `?? ''` — almost always a logic error
    - LTS\PHPQA\PHPStan\Rules\ForbidNullCoalescingEmptyStringRule
    # Bans `?? false` — use explicit null checks instead
    - LTS\PHPQA\PHPStan\Rules\ForbidNullCoalescingFalseRule
    # Blocks user input passed directly into HTTP response headers
    - LTS\PHPQA\PHPStan\Rules\ForbidHeaderInjectionRule
    # Requires Doctrine DQL/ORM — bans raw SQL strings
    - LTS\PHPQA\PHPStan\Rules\ForbidRawSqlRule
    # Symfony Console cron commands must include interval in description
    - LTS\PHPQA\PHPStan\Rules\RequireCronIntervalInDescriptionRule
    # Symfony services must declare DI attributes explicitly (#[Autowire] etc.)
    - LTS\PHPQA\PHPStan\Rules\RequireExplicitDIAttributeRule
    # catch blocks must reference the caught exception (stricter than ForbidEmptyCatchBlockRule)
    - LTS\PHPQA\PHPStan\Rules\ForbidSilentCatchRule
    # Bans inline @phpstan-ignore annotations — use phpstan.neon ignoreErrors instead
    - LTS\PHPQA\PHPStan\Rules\ForbidInlinePhpstanIgnoreRule
    # Service classes must be declared as "final readonly class"
    - LTS\PHPQA\PHPStan\Rules\RequireReadonlyServiceRule
    # Single array param annotated @param list<T> should use variadic syntax instead
    - LTS\PHPQA\PHPStan\Rules\RequireVariadicForSingleListParamRule
```

### Available optional rules

| Rule | What it catches |
|---|---|
| `ForbidNullCoalescingEmptyStringRule` | `$x ?? ''` — almost always a logic bug |
| `ForbidNullCoalescingFalseRule` | `$x ?? false` — use explicit null checks |
| `ForbidHeaderInjectionRule` | User input passed directly to HTTP headers |
| `ForbidRawSqlRule` | Raw SQL strings instead of Doctrine DQL/ORM |
| `RequireCronIntervalInDescriptionRule` | Symfony cron commands missing interval in description |
| `RequireExplicitDIAttributeRule` | Symfony services without explicit DI attributes |
| `ForbidSilentCatchRule` | `catch` blocks that ignore the caught exception |
| `ForbidInlinePhpstanIgnoreRule` | Inline `@phpstan-ignore` annotations in source files |
| `RequireReadonlyServiceRule` | Service classes not declared `final readonly` |
| `RequireVariadicForSingleListParamRule` | `array $items` annotated `@param list<T>` — use variadic syntax |

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
