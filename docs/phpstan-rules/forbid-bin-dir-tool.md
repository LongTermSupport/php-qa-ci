# `phpqaci.binDirTool` — tools run from `vendor-phar/`, not the Composer bin dir

**Rule**: `ForbidBinDirToolRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

A string concatenation whose first operand is `ProjectPathsDto::$binDir`, naming any tool other
than `phpunit` or `paratest`. Literal names, class constants and run-time values all count: a
name the rule cannot read statically is reported as "a tool named at run time".

```php
$result = $context->php->withoutXdebug(
    $paths->binDir . '/parallel-lint',
    $args,
    $paths->projectRoot,
);
```

```text
Tool parallel-lint is run from the Composer bin dir. Ship it as a PHAR under vendor-phar/ and run $paths->pharDir instead: a Composer-installed CLI tool drags its dependency graph into every consumer.
```

## Why

A lane that runs `vendor/bin/<tool>` needs that tool to be a Composer dependency of php-qa-ci,
and php-qa-ci is itself a dependency of every consumer. So the tool's entire dependency graph
is resolved into every consuming project, where it can conflict with the project's own
requirements and where every one of its transitive packages is a `composer audit` surface the
project never asked for. Three lanes had grown this way (parallel-lint, phpcpd,
composer-dependency-analyser) before the tools moved to PHARs; this rule keeps the exception
from creeping back.

## How to fix

Ship the tool as a PHAR under `vendor-phar/` and run it from `$paths->pharDir`:

- an upstream release PHAR: add it to `phive.xml` (`scripts/tool-install.bash update`), with its
  GPG key in `TRUSTED_KEYS` when the release is signed;
- no upstream PHAR: a `build/<tool>/` manifest for `scripts/build-phar.bash`.

Then remove the package from `composer.json`.

## Not reported

- `$paths->binDir . '/phpunit'` and `'/paratest'`: PHPUnit's classes are autoloaded by the
  consumer's tests, so it is a Composer dependency by necessity.
- `$paths->pharDir . '/<tool>.phar'`, the intended route.
- A `binDir` property on any class other than `ProjectPathsDto`.
