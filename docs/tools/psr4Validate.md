# PSR-4 Validation

**Identifier**: `phpqaci.psr4Validate`

An always-on check that every PHP file under the project's autoload roots declares the
namespace and class name its path implies under the `composer.json` `autoload` and
`autoload-dev` mappings (PSR-4 and PSR-0).

## What it is about

Composer's autoloader only finds a class whose file sits where its namespace says it should.
A file whose namespace has drifted from its path is still parsed by PHPUnit and PHPStan when
they are handed the path directly, so the drift shows up only when production code autoloads
the class and gets a fatal error. The check reads the mapping from `composer.json` and walks
the tree.

## How it runs

- In the full pipeline, first in the linting phase.
- Standalone: `vendor/bin/qa -t psr4` (alias `-t psr`).
- Ignore patterns: one PHP regex per line in `psr4-validate-ignore-list.txt`, the project's
  `qaConfig/` copy first, else the shipped `configDefaults/generic/` default. Files whose
  project-relative path matches a pattern are not checked.

## How to fix a failure

The output groups findings as PSR-4 errors (namespace or class name does not match the path),
parse errors (the file does not parse, so no namespace could be read) and missing paths (a
mapping in `composer.json` names a directory that does not exist). Move or rename the file, fix
the namespace, or fix the mapping. Add an ignore pattern only for a file that is deliberately not
a PSR-4 class, such as a config template.

## Implementation

- Decision: [`Psr4Validator`](../../src/Psr4Validator.php).
- Lane: [`Psr4ValidateTool`](../../src/Pipeline/Lane/Psr4ValidateTool.php); standalone binary
  `bin/psr4-validate <ignore-regex>...`.
