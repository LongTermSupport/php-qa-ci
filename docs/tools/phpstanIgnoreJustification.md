# PHPStan ignoreErrors Justification

**Identifier**: `phpqaci.phpstanIgnoreJustification`

An always-on check that every `ignoreErrors` entry in the project's `qaConfig/phpstan.neon`
carries, directly above it, a comment naming the hazard being accepted and the scope it is
accepted for.

## What it is about

`ignoreErrors` is the project record of its accepted exceptions. An entry with no reason, or
with a reason that fits every entry ("legacy", "needed for now"), is a suppression nobody can
review: a reader cannot tell what the rule would have reported there or why that was judged
acceptable, so the entry can never be retired. The check rejects generic justifications and
entries with none.

## How it runs

- In the full pipeline, in the static analysis phase, before PHPStan itself.
- Standalone: `vendor/bin/qa -t pij`.
- A project with no `qaConfig/phpstan.neon` has no record and passes.

## How to fix a failure

Write, directly above the entry, what the rule would report at that path and why that is
acceptable there, in a sentence that fits no other entry. If you cannot write that sentence, the
entry is a suppression rather than an exception; fix the code instead. See
[phpstan.md, "Suppressing Errors"](phpstan.md#suppressing-errors).

## Implementation

- Decision: [`IgnoreErrorsJustificationDetector`](../../src/PHPStan/ProjectRecord/IgnoreErrorsJustificationDetector.php).
- Runner: [`IgnoreErrorsJustificationCheck`](../../src/PHPStan/ProjectRecord/IgnoreErrorsJustificationCheck.php);
  lane [`PhpstanIgnoreJustificationTool`](../../src/Pipeline/Lane/PhpstanIgnoreJustificationTool.php);
  standalone binary `bin/phpstan-ignore-justification`.
