# PHP Strict Types

**Identifier**: `phpqaci.phpStrictTypes`

An always-on check that every `.php` and `.phtml` file under the checked paths contains a
`declare(strict_types=1)`.

## What it is about

Without strict types PHP coerces scalars at every call boundary, so a `"12abc"` becomes `12`
and a bug that a type error would have caught at the call site surfaces later as wrong data.
The bundled PHPStan rule `phpqaci.missingStrictTypes` catches the same omission during static
analysis; this lane catches it earlier, in the linting phase, and in a writable run fixes it.

## How it runs

- In the full pipeline, in the linting phase after the Version Pins check.
- Standalone: `vendor/bin/qa -t st` (alias `-t stricttypes`); supports `-p <path>`.
- **Read-only run** (`QA_READONLY=1`, GitHub Actions): every offending file is listed and the
  lane fails.
- **Writable run**: the declaration is added to each file's opening `<?php` tag and the file is
  reported as fixed; a file with no opening tag cannot be fixed and fails the lane.

## How to fix a failure

Add `declare(strict_types=1);` immediately after the opening tag, or run the lane in a writable
run and commit what it changed. A file with no `<?php` tag at all is not PHP and should not be
under a checked path.

## Implementation

- Lane: [`PhpStrictTypesTool`](../../src/Pipeline/Lane/PhpStrictTypesTool.php).
