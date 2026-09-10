# PHPArkitect

**Identifier**: `phpqaci.phpArkitect`

An on-by-default lane that enforces architectural rules (class naming, namespace layering,
dependency direction) with [PHPArkitect](https://github.com/phparkitect/arkitect), run from the
shipped `vendor-phar/phparkitect.phar`.

## What it is about

Some conventions are structural rather than semantic: an interface ends in `Interface`, a DTO is
final and readonly and lives in a `Dto` namespace, one layer must not depend on another.
PHPStan can express these only awkwardly; PHPArkitect states them directly against a class set.
Where a convention needs finer, method-level or semantic detection it belongs in a PHPStan rule
instead, never in both engines at once. See the
[README "Where does a rule belong"](../../README.md#where-does-a-rule-belong--phparkitect-or-phpstan)
guide.

## How it runs

- In the full pipeline, in the static analysis phase after PHPStan.
- Standalone: `vendor/bin/qa -t arch`. The paths to scan live inside the entry config, so
  `-p <path>` does not apply.
- **Disabled** with `->withArkitect(false)` in `qaConfig/qa.php` (or `useArkitect=0` in the environment for one run): the lane prints a
  "disabled" line and is skipped. Prefer excluding generated paths (below) over disabling.
- **Entry config** is resolved through the normal cascade: `qaConfig/phparkitect.php` if the
  project supplies one, otherwise the shipped
  [`configDefaults/generic/phparkitect.php`](../../configDefaults/generic/phparkitect.php), which
  applies the default tier to the detected source directory. If no entry config resolves at all
  the lane is skipped with a "no entry config resolved" line.
- The phar is invoked as `check --config=<entry> --autoload=<projectRoot>/vendor/autoload.php
  --no-interaction`, without Xdebug, from the project root.
- The entry config reads its inputs from the environment the lane exports:

  | Variable                                  | Value                                                        |
  | ----------------------------------------- | ------------------------------------------------------------ |
  | `PHPQACI_ARKITECT_SRC_DIR`                | the detected source directory                                |
  | `PHPQACI_ARKITECT_RULES_DEFAULT`          | resolved `phparkitect-rules-default.php`                     |
  | `PHPQACI_ARKITECT_RULES_OPTIONAL`         | resolved `phparkitect-rules-optional.php`                    |
  | `PHPQACI_ARKITECT_RULES_OPTIONAL_SYMFONY` | resolved `phparkitect-rules-optional-symfony.php`            |
  | `PHPQACI_ARKITECT_CONSUMER_API_BOUNDARY`  | resolved `phparkitect-consumer-api-boundary.php`             |
  | `PHPQACI_ARKITECT_EXCLUDE_PATHS`          | `arkitectExcludePaths`, newline-delimited, empty when unset  |

  Each `resolved` file honours a `qaConfig/` override of the same name.
- The output is written to `var/qa/phparkitect_logs/phparkitect.log` and a timestamped copy is
  archived alongside it (last ten kept).
- **Exit 1** is a rule violation: the lane fails and prints the identifier trailer.
- **Exit above 1** is a crash (invalid config, unparseable file, bad `ClassSet` path): the lane
  prints an explanation and is never retried. An incomplete autoloader does not crash; it makes
  the ancestry (`IsA`) rules of the optional tiers silently match nothing, so run
  `composer dump-autoload` if those rules find suspiciously little.

## How to fix a failure

Read the violation list: each line names the class, the rule and the reason the rule gives.
Rename or move the class to satisfy the convention. For generated code that cannot be renamed,
declare its path in `qaConfig/qa.php`:

```php
return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
    ->withArkitectExcludedPaths('Quote/API');
```

The path is relative to `src/`. To extend, replace or opt into the optional tiers, copy
[`templates/qaConfig-phparkitect.php`](../../templates/qaConfig-phparkitect.php) to
`qaConfig/phparkitect.php`; the
[README PHPArkitect section](../../README.md#phparkitect-architecture-rules) covers every option.

## Implementation

- Lane: [`PhpArkitectTool`](../../src/Pipeline/Lane/PhpArkitectTool.php).
- Default entry config: [`configDefaults/generic/phparkitect.php`](../../configDefaults/generic/phparkitect.php).
- Default tier: [`configDefaults/generic/phparkitect-rules-default.php`](../../configDefaults/generic/phparkitect-rules-default.php).
