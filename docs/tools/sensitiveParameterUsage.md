# PHPQA SensitiveParameter Usage Check

An **always-on** php-qa-ci pipeline tool that asserts the native
[`#[\SensitiveParameter]`](https://www.php.net/manual/en/class.sensitiveparameter.php)
attribute is used **at least once** in your project's `src/` directory.

PHP 8.2+ replaces a `#[\SensitiveParameter]` argument with a redacted
`SensitiveParameterValue` placeholder in `Throwable::getTrace()`, so passwords,
tokens and secrets never leak into stack traces, logs or error reporters. If the
attribute appears **nowhere** in `src/`, that almost always means a real
sensitive value has been left unprotected — so this tool fails the build.

The scan is **AST-based** (nikic/php-parser), so the attribute is never
false-matched inside strings or comments.

## Why a pipeline tool and not a PHPStan rule?

php-qa-ci also ships a PHPStan rule, `RequireSensitiveParameterAttributeRule`,
that flags individual parameters whose *name* looks like a credential. That rule
is useful but it is **opt-in**: a consumer project must include php-qa-ci's rules
neon for any bundled PHPStan rule to run. Opt-in checks cannot be relied upon
estate-wide.

This codebase-wide coverage check must run for **every** consumer
unconditionally, so it is implemented as a pipeline tool invoked by `bin/qa`,
not as a PHPStan rule. It runs as part of the always-on static-analysis phase.

## How it runs

- **Part of the full pipeline**: runs automatically in the static-analysis phase
  of a plain `bin/qa`.
- **Standalone**: `vendor/bin/qa -t sensitiveParameterUsage`
  (aliases: `-t spu`, `-t sensitiveparameter`).
- **Binary**: `bin/sensitive-parameter-usage` (delegates to
  `LTS\PHPQA\SensitiveParameter\SensitiveParameterUsageScanner::main()`).

The tool scans `{projectRoot}/src` and:

- **passes (exit 0)** when one or more `#[\SensitiveParameter]` occurrences are
  found (it prints each `file:line`); or
- **fails (exit 1)** when none are found, printing guidance and the opt-out
  instructions.

## Escape hatch (opt-out, on by default)

A small number of projects — pure tooling/QA libraries, for example — genuinely
never receive a password, token or secret. Those projects opt out by setting the
following in `qaConfig/qaConfig.inc.bash`:

```bash
export useSensitiveParameterCheck=0
```

When disabled, the tool prints a skip notice and returns success.

### Canonical worked example: php-qa-ci itself

php-qa-ci is a pure QA/tooling library and handles no sensitive parameters of its
own, so it has no `#[\SensitiveParameter]` anywhere in its `src/`. It therefore
opts out of its own always-on check via exactly this mechanism — see
[`qaConfig/qaConfig.inc.bash`](./../../qaConfig/qaConfig.inc.bash):

```bash
export useSensitiveParameterCheck=0
```

This is the reference example for how a downstream consumer opts out.

## Estate-wide impact

Because this check is always on, **every** consumer project's `bin/qa` now
requires either:

- at least one `#[\SensitiveParameter]` annotation somewhere in `src/`, or
- the `useSensitiveParameterCheck=0` opt-out.

Most projects should add the annotation to the relevant parameter rather than
opt out.
