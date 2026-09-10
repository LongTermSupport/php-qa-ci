# Dead Code Detection

**Identifier**: `phpqaci.deadCode`

An opt-in static-analysis lane that reports class members nothing reaches, using
[shipmonk/dead-code-detector](https://github.com/shipmonk-rnd/dead-code-detector) run through the
shipped `vendor-phar/phpstan.phar`.

## What it is about

Dead code is the cheapest thing to accumulate and the most expensive to keep honest: every unused
method is still read, still refactored, still a target for the next `composer audit`, and still a
place a bug can hide without a test ever reaching it. The detector answers one question per member:
does anything in the analysed code call, read or reference it? A member reached only from tests
is dead in production and is reported as such.

The tool is a PHPStan extension, so it sees exactly what PHPStan sees: real call graphs, interface
implementations, enum cases, attribute usage. Dogfooded on php-qa-ci itself before shipping: one
structural false positive in eleven reports, the rest genuine (Plan 00005, journal 2026-09-09).

## How it runs

- In the full pipeline, in the static-analysis phase straight after PHPStan. Skipped, with a
  note, until a project opts in.
- Standalone: `vendor/bin/qa -t dcd`. Whole project only; `-p` does not apply, because a partial
  run would report everything outside the path as dead.
- Runs `phpstan.phar analyse` with a generated `var/qa/deadCode/dead-code.neon` that includes the
  project's resolved `phpstan.neon` (so excludes, stubs and level carry over) and the detector's
  `rules.neon` from `vendor-phar/dead-code-detector.phar`; the detector's classes load through
  `--autoload-file` from the same PHAR. Nothing is installed through Composer, so the PHPStan
  gate never sees the detector.
- Analyses `src/`, `tests/` and every entry point listed with `withDeadCodeEntryPoints()`.
- The tests usage excluder is always on: a member only tests reach is reported.
- A library (composer `type: library`, or no type) with no `@api` tag anywhere in `src/` fails
  before anything runs: the detector treats `@api` classes as entry points, and a library without
  them has its whole public surface reported as dead. `RequireApiOrInternalTagRule` already
  requires the classification.

## Opting in

```php
return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
    ->withDeadCodeDetection(true)
    ->withDeadCodeEntryPoints('bin/console', 'bin/worker');
```

`build()` refuses `withDeadCodeDetection(true)` until the project has either listed its entry
points or said it has none with `withoutDeadCodeEntryPoints()`. A PHP script under `bin/` has no
`.php` suffix, so a directory scan never sees it, and every function it calls would be reported
dead; a forgotten list is indistinguishable from an empty one, so the choice has to be explicit.

## How to fix a failure

Each report names a member and why it is dead. The fix is to delete it, or to wire in the caller
the report shows is missing. Two shapes are not dead code:

- **Entry point.** A method only a script or an external runtime calls. List the script with
  `withDeadCodeEntryPoints()`; a class an external runtime drives (a Composer plugin, a console
  command registered by name) is `@api`, and the detector treats every `@api` class as reached.
- **Tests only** ("all usages excluded by tests excluder"). Production never calls it. Delete it
  with the tests that used it, or move it into the tests tree if it is a test helper.

Never suppress with an ignore comment or a baseline.

## Implementation

- Lane: [`DeadCodeTool`](../../src/Pipeline/Lane/DeadCodeTool.php).
- Options: [`DeadCodeOptionsDto`](../../src/Pipeline/Config/Dto/DeadCodeOptionsDto.php), set from
  `QaConfigBuilder::withDeadCodeDetection()`, `withDeadCodeEntryPoints()` and
  `withoutDeadCodeEntryPoints()`.
- PHAR: `vendor-phar/dead-code-detector.phar`, built by `scripts/build-phar.bash dead-code-detector` from `build/dead-code-detector/` (the manifest `replace`s `phpstan/phpstan`
  so the PHAR holds the detector alone, not a second PHPStan). Upstream publishes no PHAR.
