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

- The phar is invoked as `check --config=<entry> --autoload=<projectRoot>/vendor/autoload.php --no-interaction`, without Xdebug, from the project root.

- The entry config reads its inputs from the environment the lane exports:

  | Variable                                  | Value                                                       |
  | ----------------------------------------- | ----------------------------------------------------------- |
  | `PHPQACI_ARKITECT_SRC_DIR`                | the detected source directory                               |
  | `PHPQACI_ARKITECT_RULES_DEFAULT`          | resolved `phparkitect-rules-default.php`                    |
  | `PHPQACI_ARKITECT_RULES_OPTIONAL`         | resolved `phparkitect-rules-optional.php`                   |
  | `PHPQACI_ARKITECT_RULES_OPTIONAL_SYMFONY` | resolved `phparkitect-rules-optional-symfony.php`           |
  | `PHPQACI_ARKITECT_CONSUMER_API_BOUNDARY`  | resolved `phparkitect-consumer-api-boundary.php`            |
  | `PHPQACI_ARKITECT_EXCLUDE_PATHS`          | `arkitectExcludePaths`, newline-delimited, empty when unset |

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

A `phparkitect-baseline.json` (what `phparkitect generate-baseline` writes) is never read: the
lane runs `--skip-baseline`, and says so when the file is present. A baseline reports the
violations it lists as no violation at all, so it would be an exception nobody can see in the
project record. Fix what it lists, or declare the exception as above.

## Proving a rule fires

A rule with no instance in the project's code passes the lane whether or not it can fire, so a
green run proves nothing about it. Prove it on fixture code:

```bash
vendor/bin/arkitect-rule 'controllers must be named consistently' tests/Fixtures/Arkitect/Home.php
```

- **What runs.** The project's resolved entry config, with the same rule tiers and environment
  the lane exports, from the same phar, with no baseline. The entry config is loaded and every
  rule it registered is re-added against the probed path in place of its own class sets, so a
  config that hard-codes `src/` (as the template does) is probed as readily as the default.
  `withArkitectExcludedPaths` does not apply to the probed path.
- **Naming the rule.** PHPArkitect gives a rule no identifier: its `because` clause is the only
  name it has, and every violation it prints ends in that clause. The first argument is matched
  as text against those messages, so give the whole clause, or enough of it to be unique.
- **The path.** A directory probes every class in it; a file probes the classes that file
  declares, which lets a violating and a conforming subject share a directory.
- **Exit codes.** `0` the rule did not fire, `1` it fired (each class, its file and the message
  are printed), `2` a usage error or a run with no verdict: a missing path, a crash, or an entry
  config that registered no rule at all.
- **A miss is ambiguous.** A clause that names no rule reads the same as a rule that did not
  fire, so the proof is a pair: the rule fires on its violating fixture and stays silent on its
  conforming one.
- **The fixture declares what the rule looks at.** A rule scoped to `App\Controller` sees only
  classes declaring that namespace, so the fixture declares it whatever directory it sits in.
  Such a file breaks PSR-4, so list the fixture directory in `psr4Validate`'s ignore list. A
  rule that resolves ancestry (`IsA`, the optional tiers) also needs the fixture autoloadable,
  so its fixtures keep namespaces their paths match.

Keep the fixture as the rule's own test: a PHPUnit test that runs the command on each fixture
and asserts the exit code is a test the pipeline's `phpunit` lane already runs.

## Implementation

- Lane: [`PhpArkitectTool`](../../src/Pipeline/Lane/PhpArkitectTool.php).
- Single-rule harness: [`ArkitectRuleProbe`](../../src/Arkitect/ArkitectRuleProbe.php), behind
  `bin/arkitect-rule`.
- Default entry config: [`configDefaults/generic/phparkitect.php`](../../configDefaults/generic/phparkitect.php).
- Default tier: [`configDefaults/generic/phparkitect-rules-default.php`](../../configDefaults/generic/phparkitect-rules-default.php).
