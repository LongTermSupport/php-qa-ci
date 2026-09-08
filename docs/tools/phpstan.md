# PHPQA PHPStan

Full details of how PHPStan is used with PHPQA and how you can configure it for your projects.

PHPStan runs as a **PHAR** from `vendor-phar/phpstan.phar`. The `phpstan/phpstan` Composer package is in the `replace` section of `php-qa-ci`'s `composer.json`, so the PHAR is used instead of a Composer-installed binary.

## How the lane runs

**Identifier**: `phpqaci.phpstan`. Lane: [`PhpstanTool`](../../src/Pipeline/Lane/PhpstanTool.php).

- In the full pipeline, in the static analysis phase after the ignoreErrors justification check.
  Standalone: `vendor/bin/qa -t stan`; supports `-p <path>`.
- The resolved `phpstan.neon` (project override or shipped default) is wrapped in a generated
  `var/qa/phpstan_logs/phpstan-parallel.neon` that includes it and caps
  `parallel.maximumNumberOfProcesses` at half the CPU threads, the same figure Rector and
  Infection use. The lane prints the cap it applied.
- The phar runs without Xdebug as `analyse <paths> -c <wrapper>`, with `--no-progress` in CI.
- **Text mode**: the output is streamed and written to `var/qa/phpstan_logs/phpstan.log`, and a
  timestamped copy is archived (last ten kept per full-suite or per-path pattern). Exit 1 means
  errors were found: the lane fails with the identifier trailer, and when the output mentions
  `alreadyNarrowedType`, `alwaysTrue`, `alwaysFalse` or `impossibleCheck` it first prints a note
  explaining that such an error is usually a tautology left behind by stronger types, to be
  deleted rather than silenced. An exit above 1 is a crash: the lane says so, runs PHPStan again
  with `--debug -v` so the fatal that stopped it is visible, and is never retried.
- **`--json` mode** (`vendor/bin/qa --json -t stan`): PHPStan runs with `--error-format=json`,
  the report is written to `var/qa/phpstan_logs/phpstan.json`, archived, and printed unchanged on
  the real stdout while every other line goes to stderr. Exit 1 fails, above 1 crashes, and
  nothing is re-run.

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
- **RequireSensitiveParameterAttributeRule** -- Requires `#[\SensitiveParameter]` on plaintext credential parameters (configurable name patterns / ignore substrings via the `phpqaciSensitiveParameter` parameters block)
- **RequireApiOrInternalTagRule** -- Package-type-aware: for a `type: library` project, every public class-like must be classified as exactly one of `@api` / `@internal`; no-ops for other package types. Generated/managed namespaces are exempt via the `phpqaciApiOrInternal.ignoredNamespacePrefixes` parameter. Full guidance (incl. the deliberate `@api`-vs-`@internal` judgement): [tools/requireApiOrInternal.md](requireApiOrInternal.md)
- **ApiMustNotExposeInternalRule** -- An `@api` class-like must not expose an `@internal` one from the same package through its public signature (keeps the `@api` promise honest)
- **ForbidNewDateTimeRule** -- Bans direct `new DateTime` / `new DateTimeImmutable`
- **ForbidEmptyLanguageConstructRule** -- Bans `empty()` (use explicit type-safe checks)
- **ForbidLooseComparisonRule** -- Bans `==` / `!=` (require strict `===` / `!==`)
- **ForbidDeprecatedSerializableRule** -- Bans the deprecated `Serializable` interface
- **ForbidNestedTernaryRule** -- Bans nested ternary expressions
- **RequireRuleIdentifierConstantRule** -- PHPStan rule classes must expose an identifier constant
- **ForbidHttpPrefixedEnvVarsRule** -- Bans a Symfony-consumed env var named `HTTP_*`, which Symfony refuses to read from `$_SERVER` so it resolves EMPTY in any CLI process. Reaches `config/` YAML and `.env` files itself; auto-skips on non-Symfony projects. Full guidance: [phpstan-rules/forbid-http-prefixed-env-vars.md](../phpstan-rules/forbid-http-prefixed-env-vars.md)
- **ForbidUnanchoredVendorSubstringCheckRule** -- Bans deciding project-versus-dependency with a bare `vendor/` substring check, which goes silent when the project itself sits under a `vendor/` path. Use `VendoredCodeDetector`. Full guidance: [phpstan-rules/forbid-unanchored-vendor-substring-check.md](../phpstan-rules/forbid-unanchored-vendor-substring-check.md)
- **ForbidInlinePhpstanIgnoreRule** -- Bans inline `@phpstan-ignore` annotations. A suppression that is genuinely irreducible goes in `phpstan.neon` `ignoreErrors`, where it is visible in review; inline, it silences the finding at the one place nobody looks.

`rules-default.neon` is the single source of truth for the always-on set (17 rules at time of
writing: 12 in its `rules:` block plus `ForbidMockingFinalClassRule`,
`ForbidHttpPrefixedEnvVarsRule`, `RequireSensitiveParameterAttributeRule`,
`RequireApiOrInternalTagRule`, and `ApiMustNotExposeInternalRule` registered as tagged services).
Consult that file if in doubt.

**To look up a rule from a failure, use the [identifier index](../phpstan-rules/README.md).** PHPStan
prints an identifier such as `phpqaci.nullCoalescingFalse`, not a class name, and the index is keyed
on the identifier. The list above is organised by class name and is for reading, not for lookup.

Two commands ship for working with a single rule:

- `vendor/bin/rule-doc <identifier>` prints the rule's class, bundle and summary and, where one
  exists, its remediation page. Offline, from the installed package.
- `vendor/bin/phpstan-rule <identifier> <path>` runs PHPStan over one path with the project's own
  configuration and reports whether that one rule fired there, and where. Exit 0 for did not fire,
  1 for fired. Use it to prove a new rule sees what it should before trusting a green full run.

To list every defence active in the project — without running PHPStan — use
`vendor/bin/rules [project-root] [--json]`. See the [identifier index](../phpstan-rules/README.md)
for the full description.

See the README "Configuring RequireSensitiveParameterAttributeRule" section for the full config keys and defaults.

> The codebase-wide "is `#[\SensitiveParameter]` used anywhere?" coverage check is deliberately NOT a PHPStan rule (rules are opt-in and cannot be relied on estate-wide). It ships as an always-on pipeline tool — see [tools/sensitiveParameterUsage.md](sensitiveParameterUsage.md).

Projects can add their own custom rules in addition to these defaults.

## Optional Rules

PHP-QA-CI ships 12 additional opt-in rules split across two files:

- **`rules-optional.neon`** — 8 generic rules suitable for any PHP project (6 in its `rules:` block
  plus 2 service-registered: `FactorySealedRule` and `ForbidDeprecatedPhpunitMethodRule`)
- **`rules-optional-symfony.neon`** — includes `rules-optional.neon` plus 4 Symfony/Doctrine-specific rules (12 total)

These are **not** loaded automatically — you must enable them explicitly.

One further rule, **`ForbidMagicStringAssertionRule`**, ships but is **not part of
either bundle** — it is experimental and high-noise (see *Experimental rules*
below). Cherry-pick it only for a deliberate one-off magic-string cleanup sweep.

### Symfony projects: include the Symfony set

```neon
includes:
    - ../vendor/lts/php-qa-ci/configDefaults/generic/phpstan.neon
    - ../vendor/lts/php-qa-ci/rules-optional-symfony.neon
```

This includes all generic rules plus `ForbidHeaderInjectionRule`, `ForbidRawSqlRule`, `RequireCronIntervalInDescriptionRule`, and `RequireExplicitDIAttributeRule`. When new rules are added you get them automatically on upgrade.

### Generic PHP projects: include the generic set

```neon
includes:
    - ../vendor/lts/php-qa-ci/configDefaults/generic/phpstan.neon
    - ../vendor/lts/php-qa-ci/rules-optional.neon
```

### Cherry-pick individual rules

Copy only the rules you want into your `qaConfig/phpstan.neon`. You retain full control but must add new rules manually as they are released:

```neon
includes:
    - ../vendor/lts/php-qa-ci/configDefaults/generic/phpstan.neon

rules:
    # Bans `?? ''` — almost always a logic error
    - LTS\PHPQA\PHPStan\Rules\ForbidNullCoalescingEmptyStringRule
    # Bans `?? false` — use explicit null checks instead
    - LTS\PHPQA\PHPStan\Rules\ForbidNullCoalescingFalseRule
    # catch blocks must reference the caught exception (stricter than ForbidEmptyCatchBlockRule)
    - LTS\PHPQA\PHPStan\Rules\ForbidSilentCatchRule
    # Service classes must be declared as "final readonly class"
    - LTS\PHPQA\PHPStan\Rules\RequireReadonlyServiceRule
    # Single array param annotated @param list<T> should use variadic syntax instead
    - LTS\PHPQA\PHPStan\Rules\RequireVariadicForSingleListParamRule
    # A scalar @param/@return typed as a docblock literal set ('a'|'b', 0|1) is an undeclared enum
    - LTS\PHPQA\PHPStan\Rules\RequireEnumOverLiteralUnionRule
    # Symfony: blocks user input passed directly into HTTP response headers
    - LTS\PHPQA\PHPStan\Rules\ForbidHeaderInjectionRule
    # Symfony/Doctrine: requires Doctrine DQL/ORM — bans raw SQL strings
    - LTS\PHPQA\PHPStan\Rules\ForbidRawSqlRule
    # Symfony Console: cron commands must include interval in description
    - LTS\PHPQA\PHPStan\Rules\RequireCronIntervalInDescriptionRule
    # Symfony DI: services must declare DI attributes explicitly (#[Autowire] etc.)
    - LTS\PHPQA\PHPStan\Rules\RequireExplicitDIAttributeRule
```

### Available optional rules

| Rule                                    | File                            | What it catches                                                                |
| --------------------------------------- | ------------------------------- | ------------------------------------------------------------------------------ |
| `ForbidNullCoalescingEmptyStringRule`   | `rules-optional.neon`           | `$x ?? ''` — almost always a logic bug                                         |
| `ForbidNullCoalescingFalseRule`         | `rules-optional.neon`           | `$x ?? false` — use explicit null checks                                       |
| `ForbidSilentCatchRule`                 | `rules-optional.neon`           | `catch` blocks that ignore the caught exception                                |
| `RequireReadonlyServiceRule`            | `rules-optional.neon`           | Service classes not declared `final readonly`                                  |
| `RequireVariadicForSingleListParamRule` | `rules-optional.neon`           | `array $items` annotated `@param list<T>` — use variadic syntax                |
| `RequireEnumOverLiteralUnionRule`       | `rules-optional.neon`           | A scalar `@param`/`@return` typed `'a'\|'b'` or `0\|1` — declare a backed enum |
| `FactorySealedRule`                     | `rules-optional.neon` (service) | A class marked with a sealing attribute may be constructed only by its factory |
| `ForbidDeprecatedPhpunitMethodRule`     | `rules-optional.neon` (service) | Calls to a method deprecated by the installed PHPUnit                          |
| `ForbidHeaderInjectionRule`             | `rules-optional-symfony.neon`   | User input passed directly to HTTP headers                                     |
| `ForbidRawSqlRule`                      | `rules-optional-symfony.neon`   | Raw SQL strings instead of Doctrine DQL/ORM                                    |
| `RequireCronIntervalInDescriptionRule`  | `rules-optional-symfony.neon`   | Symfony cron commands missing interval in description                          |
| `RequireExplicitDIAttributeRule`        | `rules-optional-symfony.neon`   | Symfony services without explicit DI attributes                                |

### Experimental rules (not in any bundle)

`ForbidMagicStringAssertionRule` flags test assertions that pin an identifier-like
string against a plain `string` (e.g. `assertSame('active', $x)`), on the theory
the value should be a backed enum. The idea is sound — a test pinning a magic
string is often a sign the value should be an enum (push type safety left; once it
is an enum the assertion becomes redundant and `alreadyNarrowedType` flags it). In
practice it is **high-noise**: it cannot distinguish a should-be-enum value from a
wire-contract key (`cf_*`), fixture data, an id, or a param/type name — all
identifier-like tokens. It is therefore a manual cleanup aid (cherry-pick it for a
one-off sweep and eyeball the hits), **not** a CI gate. The durable form of the
principle is guidance: *"don't bridge magic-string uncertainty with a test — model
the closed set as a backed enum."*

## Strict Rules

The strict rules are brought in as a dependency and configured by default.

PHPQA uses the PHPStan extension loader. If you need to disable specific strict rules, you will need to ignore specific rule failures rather than trying to disable the strict rule set.

See [the main PHPStan strict rules docs](https://github.com/phpstan/phpstan-strict-rules) for more information.

## Suppressing Errors

[Here](https://github.com/phpstan/phpstan#ignore-error-messages-with-regular-expressions) you can read more about how to ignore errors by modifying `phpstan.neon`.

`ignoreErrors` in `qaConfig/phpstan.neon` is the project's record of the exceptions it has decided
to keep, and the pipeline reads it as one. Every entry must be immediately preceded by a comment
that names the hazard the rule would report there and why that is acceptable at that path. The
`phpstanIgnoreJustification` lane (`bin/qa -t pij`) fails on an entry with no comment, a comment
too short to name a hazard and a scope, or a phrase that would fit any entry unchanged (`legacy`,
`needed for now`, `false positive` and the like). The check cannot tell whether a sentence is true;
that is the reviewer's judgement, which is why the entries are kept in one file where a vacuous
reason sits next to its neighbours.

```neon
parameters:
    ignoreErrors:
        # The generated API client references a class that exists only at runtime in the
        # consuming application; scoped to the generated directory, which is never edited.
        -
            identifier: class.notFound
            path: ../src/Generated/*
```

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
