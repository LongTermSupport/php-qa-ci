# PHPCPD (copy/paste detection)

**Identifier**: `phpqaci.phpcpd`

Reports duplicated code across the checked paths, after the pipeline has otherwise passed.
Informational only: it cannot fail the run.

## What it is about

Duplication is the cheapest defect to create and among the more expensive to live with: a fix
applied to one copy and not the others is the shape of a whole class of bugs that no type
checker or test can see, because every copy is individually correct.

It is also the one finding in this pipeline that is genuinely a judgement call. Two blocks that
look alike may be coincidentally similar and about to diverge, and extracting them would couple
two things that should stay apart. So this lane reports and does not gate. A lane that failed
the build on duplication would be switched off within a week, and then nobody would see the
report at all.

## How it runs

- After the "ALL TESTS PASSING" banner, before the project's `hookPost.php`.
- Standalone: `vendor/bin/qa -t cpd`.
- Runs the shipped `vendor-phar/phpcpd.phar` (self-built from `build/phpcpd/`).
- A JSON report is written to `var/qa/phpcpd/phpcpd.json` on every run, so the numbers can be
  tracked over time by something outside the pipeline even though nothing here acts on them.

### It cannot fail the run

phpcpd returns `1` both when it finds clones and when it errors, so the two are
indistinguishable and neither is treated as a failure. An exit code outside `0` and `1` is a
genuine crash and is reported on screen — still without failing the run, because an
informational lane that can break the build is not informational.

## How to act on a report

Read it, and then decide. Reasonable outcomes include:

- **Extract the duplication** where the copies are the same idea and will change together.
- **Leave it** where the copies are coincidentally similar. Two validators that both check a
  string length today are not the same rule.
- **Delete one** where a copy exists because someone did not find the original.

There is no "fix" to apply mechanically, which is exactly why this does not gate.

## Implementation

- Lane: [`PhpcpdTool`](../../src/Pipeline/Lane/PhpcpdTool.php).
- Upstream: [phpcpd-next/phpcpd](https://github.com/phpcpd-next/phpcpd), a maintained,
  dependency-free successor to the archived `sebastian/phpcpd`. It publishes no PHAR, so
  `scripts/build-phar.bash phpcpd` boxes it from the `build/phpcpd/` manifest.
