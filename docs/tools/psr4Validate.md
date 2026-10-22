# PSR-4 Validation

**Identifier**: `phpqaci.psr4Validate`

An always-on check that every PHP file under the project's PSR-4 roots, the `psr-4` mappings of
`composer.json`'s `autoload` and `autoload-dev`, declares the namespace its path implies, and
declares no class, interface, trait or enum except the one its path names. Other autoload
mappings (`psr-0`, `classmap`, `files`) are not read.

## What it is about

Composer's autoloader looks for a class-like in the one file its name maps to, so it finds only
a class-like whose file sits where its fully qualified name says it should. A file whose
namespace has drifted from its path, or which declares a class-like under any other name, is
still parsed by PHPUnit and PHPStan when they are handed the path directly, so the drift shows
up only when code autoloads the class and gets a fatal error. The check reads the mapping from
`composer.json` and walks the tree.

## What it checks

For each PHP file under a PSR-4 root:

- **The namespace.** The file is parsed with `nikic/php-parser`, and its first named
  namespace declaration, `namespace X;` or `namespace X { ... }`, must be the one the file's directory
  implies under the root's namespace prefix. A comment, docblock or string mentioning a
  namespace is not a declaration. A file that declares no namespace, or only the global one
  (`namespace { ... }`), is reported as a parse error. In a file of several namespace blocks the
  first is the file's, and a class-like in a later block is reported by the class-like check.

- **The class-likes.** Every named class, interface, trait and enum the file declares, in any
  namespace block, must be the one its path names: the implied namespace
  and the file's base name, so `src/Foo/Bar.php` under `App\` may declare only `App\Foo\Bar`.
  Both shapes are reported:

  - the file's class-like has another name (`src/Foo/Bar.php` declaring `final class Baz`);
  - a second class-like sits beside the one its path names, such as a helper class, an
    exception or an enum at the foot of the file.

  Anonymous classes have no name to load and are not reported, nor is a file that declares no
  class-like at all, such as a file of functions. A class-like whose only fault is the
  namespace already reported for its file is not reported a second time.

A file the parser cannot parse, or whose names it cannot resolve (a second `use` of one short
name), is not checked further, and is reported as a parse error with the parser's reason.

A class-like declared beside the one its path names is reported even when only its own file
uses it. The lane reads one file at a time, so it cannot see whether another file names the
class-like, and a name reaches the autoloader without any reference in code: `class_exists()`,
`unserialize()` of a stored object, a class name in configuration. Such a class-like works
only while its file happens to have been loaded first; this repository had a test helper
another test used that way. Composer's optimised autoloader dump agrees that it cannot be
loaded, and leaves it out of the class map.

## How it runs

- In the full pipeline, first in the linting phase.
- Standalone: `vendor/bin/qa -t psr4` (alias `-t psr`).
- Ignore patterns: one PHP regex per line in `psr4-validate-ignore-list.txt`, the project's
  `qaConfig/` copy first, else the shipped `configDefaults/generic/` default. Files whose
  real (absolute) path matches a pattern are not checked.
- Every `withIgnoredPaths()` path adds one more pattern, anchored to its real path, so it skips
  the path and what is below it and never a sibling sharing its prefix. A fixture tree in the
  ignored paths needs no entry in the ignore list.
- A file whose real path cannot be resolved, such as a dangling symlink, is not checked: it has
  no content to read, and reading it used to stop the run without naming it
  ([#241](https://github.com/LongTermSupport/php-qa-ci/issues/241)). The ignore patterns are
  matched against its directory's real path and its own name; one they do not match is named in
  `PSR-4 validation skipped <path>: its real path cannot be resolved ...` (in the standalone
  binary, `psr4-validate skipped <path>: ...`). A skipped file does not fail the lane.
- A pattern that does not compile fails the run before any file is checked, naming the pattern,
  where it came from and PCRE's reason, for example
  `/path/to/project/qaConfig/psr4-validate-ignore-list.txt entry "#/src/(unclosed#" is not a valid regular expression: preg_match(): Compilation failed: missing closing parenthesis at offset 14`.
  The config template audit names a bad entry the same way, and fails its lane.

## Standalone binary

`bin/psr4-validate [<ignore-pattern> ...]` validates the project it is run from. Each argument
is a pattern with its delimiters, such as `'#/src/Legacy/#'`; `--help` or `-h` prints the usage
and checks nothing. An argument that is not a valid pattern is refused by name with a pointer to
`--help`, and a directory with no `composer.json` is refused with a message saying so. It exits 0
when every file agrees with the mapping and 1 otherwise.

## How to fix a failure

The output groups findings as:

- **PSR-4 errors**: the namespace does not match the path. Fix the namespace, or move the file.
- **Class-like errors**: a class-like is not the one the path names. Each entry names the file,
  the class-like its path names, the one it declares, and the remedy:
  `class App\Foo\Baz is declared in a file whose path autoloads only App\Foo\Bar, so no autoloader can load it: ...`.
  Where the file declares no class-like its path names, rename the class-like or the file so
  the two agree. Where it does, move the other class-like to a file of its own, named for it,
  and import it where it is used.
- **Parse errors**: the file declares no namespace, or it does not parse; the second is listed
  with the parser's reason.
- **Missing paths**: a mapping in `composer.json` names a directory that does not exist.
  Fix the mapping.

Add an ignore pattern only for a file that is deliberately not a PSR-4 class, such as a config
template or a fixture tree holding malformed code on purpose.

## Implementation

- Decision: [`Psr4Validator`](../../src/Psr4Validator.php).
- Lane: [`Psr4ValidateTool`](../../src/Pipeline/Lane/Psr4ValidateTool.php).
- Standalone binary: [`Psr4ValidateCommand`](../../src/Psr4Validate/Psr4ValidateCommand.php)
  behind `bin/psr4-validate`.
- Pattern compilation: [`RegexPattern`](../../src/Regex/RegexPattern.php).
