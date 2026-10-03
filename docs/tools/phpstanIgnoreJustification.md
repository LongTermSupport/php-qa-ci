# PHPStan ignoreErrors Justification

**Identifier**: `phpqaci.phpstanIgnoreJustification`

An always-on check that every `ignoreErrors` entry in the project's `qaConfig/phpstan.neon`
carries, directly above it, a comment naming the hazard being accepted and the scope it is
accepted for, and that the project's defect record, `qaConfig/defect-record.neon`, can be read in
full.

## What it is about

`ignoreErrors` is the project record of its accepted exceptions. An entry with no reason, or
with a reason that fits every entry ("legacy", "needed for now"), is a suppression nobody can
review: a reader cannot tell what the rule would have reported there or why that was judged
acceptable, so the entry can never be retired. The check rejects generic justifications and
entries with none.

## How it runs

- In the full pipeline, in the static analysis phase, before PHPStan itself.
- Standalone: `vendor/bin/qa -t pij`.
- A project with no `qaConfig/phpstan.neon` has no `ignoreErrors` record, and one with no
  `qaConfig/defect-record.neon` has no defect record; either half passes when its file is absent.
- Both halves always run, so an unjustified entry does not hide a malformed defect record.

## How to fix a failure

Write, directly above the entry, what the rule would report at that path and why that is
acceptable there, in a sentence that fits no other entry. If you cannot write that sentence, the
entry is a suppression rather than an exception; fix the code instead. See
[phpstan.md, "Suppressing Errors"](phpstan.md#suppressing-errors).

## The defect record

The method specification (section 2) requires two things to be written where the project's
other decisions are enumerable, and not only mentioned in a conversation that ends:

- a **deferred Defect**: one found and not fixed now, naming its Class where one is already
  apparent. Whether it stays unfixed is the Owner's decision; the record is what puts it in front
  of them, and the attempt at a Defence is owed when the fix is taken up;
- a **no-pattern conclusion**: the one sentence stating that a Defect is no Instance of a
  detectable pattern, naming at least two independent techniques tried at expressing the Rule.

Both go in `qaConfig/defect-record.neon`. `vendor/bin/rules` lists them, in text and in JSON
(`defectRecord`), beside the defences and the `ignoreErrors` record, and the active-defences
region `rules --write-agent-summary` writes into `CLAUDE.md` carries them, one line each up to
ten entries and a count beyond that.

```neon
deferred:
    -
        defect: 'The cache key omits the locale, so a translated page can be served in another language'
        class: 'a cache key built from a subset of the inputs the value depends on'  # leave out when none is apparent
        found: src/Cache/PageKey.php
        deferredBy: 'Owner, until the cache layer is replaced'
noPattern:
    -
        defect: 'The invoice total was rounded twice'
        found: src/Invoice/Total.php
        conclusion: 'No pattern exists: neither technique found a second double rounding or a construction a rule could match'
        techniques:
            - 'a text search for round( over src/'
            - 'reading every caller of Money::round()'
```

| Section     | Field        | Required                                                           |
| ----------- | ------------ | ------------------------------------------------------------------ |
| `deferred`  | `defect`     | yes: what is wrong                                                 |
| `deferred`  | `class`      | where a Class is already apparent; leave it out otherwise          |
| `deferred`  | `found`      | yes: the path, component or command where it was found             |
| `deferred`  | `deferredBy` | yes: who decided not to fix it now, or that the decision is open   |
| `noPattern` | `defect`     | yes                                                                |
| `noPattern` | `found`      | yes                                                                |
| `noPattern` | `conclusion` | yes: the one-sentence conclusion                                   |
| `noPattern` | `techniques` | yes: at least two different techniques, in clause 3.1's vocabulary |

The lane fails, naming the entry, on a file that is not NEON, an unknown section or field (a
misspelt `class` would otherwise drop out of the listing unseen), a required field missing or
empty, and a `techniques` list with fewer than two different entries. `bin/rules` refuses to list
a record it cannot read, rather than listing the rest as if it were whole.

Quote a value that contains `,` `:` `(` `[` `{` or `#`: NEON reads those as syntax. An entry
whose Defect has been fixed is removed from the record.

## Implementation

- Decision: [`IgnoreErrorsJustificationDetector`](../../src/PHPStan/ProjectRecord/IgnoreErrorsJustificationDetector.php).
- Runner: [`IgnoreErrorsJustificationCheck`](../../src/PHPStan/ProjectRecord/IgnoreErrorsJustificationCheck.php);
  lane [`PhpstanIgnoreJustificationTool`](../../src/Pipeline/Lane/PhpstanIgnoreJustificationTool.php);
  standalone binary `bin/phpstan-ignore-justification` (the `ignoreErrors` half only).
- Defect record: [`DefectRecordReader`](../../src/DefectRecord/DefectRecordReader.php) reads and
  validates it, and [`DefectRecordCheck`](../../src/DefectRecord/DefectRecordCheck.php) runs it
  for the lane.
