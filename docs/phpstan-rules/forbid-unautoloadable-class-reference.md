# `phpqaci.unautoloadableClassReference` — a class another file uses is declared where its autoloader looks for it

**Rule**: `ForbidUnautoloadableClassReferenceRule`
**Bundle**: [`rules-optional.neon`](../../rules-optional.neon) (opt in)

## What fires

A reference from one file to a class that the project's own `composer.json` PSR-4 or PSR-0
mapping claims, when the class is not declared at the path that mapping gives, at a position
where PHP asks the autoloader for the class. The usual case is a helper declared at the foot of
a test file and used by another test:

```php
// tests/Runner/ToolExecutorTest.php
namespace App\Tests\Runner;

final class ToolExecutorTest extends TestCase { /* ... */ }

final readonly class AlwaysRetry implements RetryPromptInterface { /* ... */ }
```

```php
// tests/Runner/PipelineTest.php
$prompt = new AlwaysRetry();
```

```text
Class App\Tests\Runner\AlwaysRetry cannot be autoloaded: it is declared in tests/Runner/ToolExecutorTest.php, not at tests/Runner/AlwaysRetry.php, so this reference fails with a "not found" error unless that file was loaded first. Move the class to its own file at that path.
```

A file whose namespace has drifted from its directory is the same defect, and is reported the
same way. Each reference is reported at its own line, and the message says what fails at that
position when the declaring file was not loaded first:

| Position                                                                                 | What fails                                                                                                 |
| ---------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| `new`, a static call or property, a class constant, `extends`, `implements`, a trait     | the reference itself, with `Class "…" not found` (or `Interface`, `Trait`)                                 |
| `X::class`, other than as an operand of `===` or `!==`                                   | whatever loads the class by that name: `createMock()` and a container fail, `class_exists()` answers false |
| an attribute                                                                             | reading it with `newInstance()`, with `Attribute class "…" not found`                                      |
| a type in a method's signature that PHP checks against an inherited method's (see below) | declaring the class, with `Could not check compatibility … because class … is not available`               |

PHP compares a method's signature with the method it overrides or implements by name first, and
loads a class only where the names do not settle the comparison. A return type naming the class
is checked by loading it unless the inherited return type names it too, is `mixed`, or is absent;
a parameter type naming it is checked by loading it when the inherited parameter's type names a
class this parameter's type does not. A private method, a trait's concrete method and a
constructor that is neither abstract nor an interface's are not checked at all.

Two more defects of the same class are reported with messages of their own:

- **A name written in another case** than the class's declaration, where the class is at its
  path. PHP's class names ignore case, but Composer's autoloader maps the name as written to a
  path letter for letter, and a case-sensitive filesystem has no file at that path:

  ```text
  Class App\Tests\Support\AlwaysRetry is written here as App\Tests\Support\alwaysRetry: the autoloader looks for the name as written, at tests/Support/alwaysRetry.php, where a case-sensitive filesystem has no file, so this reference fails with a "not found" error unless that file was loaded first. Write the name as it is declared.
  ```

- **A second declaration** of a class the autoloader finds at its path. It is reported at the
  copy, not at the references, which load the class at its path:

  ```text
  Class App\Tests\Support\AlwaysRetry is also declared at tests/Support/AlwaysRetry.php, where the autoloader finds it: loading this file after that one fails with PHP's "Cannot redeclare" fatal error, and loading it first gives every other file this copy. Delete this copy.
  ```

## Why

Composer's autoloader looks for a claimed class at one path per prefix. A class declared
anywhere else exists only once its file has been loaded for some other reason. A full test run
usually loads that file first, so the suite passes. Then something narrows what loads:

- a single test file, run alone;
- a `--filter` run, or a random test order;
- Infection, which runs only the tests that cover a mutant;
- production code that reaches the class before anything has loaded the file.

Each gives one of the errors above from a file that is correct, and the cause is in a file the
failure does not name.

## How to fix

Move the class to its own file, at the path the message names, so the autoloader finds it
wherever it is used from. A helper more than one test shares belongs with the project's other
shared test helpers (such as `tests/Support/`), in that directory's namespace. A file whose
namespace has drifted gets its namespace corrected, or is moved to match it. A name written in
the wrong case is rewritten as declared. A second declaration is deleted.

Listing the file under `classmap` or `files` in `composer.json` also makes the class load, but
it hides the class from anyone reading the tree. Use it only for a file that cannot follow
PSR-4, such as one generated by a tool.

## Not reported

Each exclusion is a narrowing: the hazard cannot arise in the excluded code.

- **`instanceof X`, `catch (X …)`, and the type of a property, a class constant, a function's or
  closure's parameter or return, or a method's when it is not checked against an inherited one.**
  PHP does not load X there, and it need not: no object of a class exists until the class, and
  every class and interface above it, has been loaded. Against an X that was never loaded,
  `instanceof` is false, a `catch` does not match and a type check fails only for a value that
  is not an X, which is the answer they give when X is loaded.
- **`X::class` compared with `===` or `!==`.** The comparison is of text with text, and nothing
  asks the autoloader for X through it.
- **A method signature PHP checks without loading the class**, as set out under What fires.
- **A use in the declaring file itself.** Whatever loads the file declares the class, so a
  helper used only by the test it sits beside cannot be missing when that test runs.
- **A use from a file that includes the declaring file** by a path known without running it
  (`require_once __DIR__ . '/stubs.php'`). That file loads the class itself.
- **A class that no PSR-4 or PSR-0 prefix of the project claims.** The project never asked its
  autoloader to find it: it is supplied by a dependency, by the runtime, or by a stub file the
  analyser scans, so where it is declared says nothing about whether it loads.
- **A file listed under `classmap`, or a directory containing it, or a file under `files`.**
  The autoloader loads it whatever it declares. A second declaration in such a file is still
  reported.
- **Vendored code**, and a class declared inside a phar.
- **A type named only in a docblock.** A docblock loads nothing at run time.

## Limits

- **An include anywhere in the referencing file counts.** The rule does not check that the
  include runs before the reference, because the order in which a file's code runs is decided
  at run time: an include in a test's `setUp()` runs before every test method, and one in a
  constructor before every other method, though neither precedes them in the file. Requiring
  the include to come first in the text would report that correct code, so a file that includes
  the declaring file only on a branch it may not take, or in a method it may not call first, is
  not reported.
- **The rule sees a class only when the declaring file is among the analysed paths.** When it is
  not, as when a single file is analysed, PHPStan cannot find the class at all and reports it as
  `class.notFound` instead.
- **A second declaration is found only beside a copy at the claimed path.** Two declarations of
  a class neither of which is at its path are reported as misplaced at every loading reference.
- **A stranded class named in an interface method is not followed to its implementers.** An
  interface method that names the class, whose implementer widens the type to `object`, fails
  in PHP when the implementer's file loads; the rule reports nothing there.
- **A name in the wrong case is judged against the PSR-4 and PSR-0 paths.** A wrong-case name of
  a class a `classmap` loads is not reported here: PHPStan's own `class.nameCase` check, on at
  level 2 and above, reports every name written in another case.

## Defence Before Fix record

- **Class**: a reference that makes PHP ask Composer's autoloader for a class the project's
  PSR-4 or PSR-0 mapping claims, when the autoloader cannot find the class from that name: the
  class is declared elsewhere than the path the mapping gives (at the foot of another class's
  file, or under a namespace drifted from its directory), or the name is written in another case
  than the declaration. With it, a second declaration of a class the autoloader finds at its
  path.

- **Hazard**: whether the reference works depends on whether some other code loaded the
  declaring file first, so a single test, a filtered or random-order run, Infection or
  production code fails with "not found", or a container or `class_exists()` misses the class,
  from a file that is correct; a second declaration fails with PHP's "Cannot redeclare" fatal error when both
  files load, and otherwise hands every file whichever copy loaded first.

- **Search, two techniques**, chosen before the rule ran:

  - a token scan of `src/` and `tests/` for every class-like whose name does not match its file
    name. It found nothing under `src/`, five under `tests/` outside the fixtures (`AlwaysRetry`
    at the foot of `ToolExecutorTest.php`, and four helpers used only in their own file:
    `MutableClock`, `TerminatedException`, `BrokenOutput`, `StopOnFirstWriteOutput`) and 28
    fixtures and stubs under `tests/assets/`;
  - a word search for each of those names across `src/` and `tests/`, read by hand for
    references from another file. It found `AlwaysRetry` constructed in `PipelineTest.php`, and
    `CapturingIO` and `FakeRootPackage` used from `tests/Small/ComposerPlugin/`, whose every file
    `require_once`s the stubs that declare them. The fixture classes appear in other files only
    in strings and docblocks.

  For the two defects added to the class, a token scan for every class-like declared in more
  than one file found two, both fixtures (this rule's `Duplicated` and a `Fixture\` class no
  prefix claims); a token scan for names in a class position whose last segment matches a
  declared class-like in another case, read by hand, found only this rule's own fixture.

- **Instances at the sweep**: one, `PipelineTest.php:381` (`new AlwaysRetry()`), which the rule
  reported and nothing else; the rule as narrowed here reports the same one. Generated and
  vendored code is not swept, and the fixtures under `tests/assets/` are not instances.

- **Narrowings**: each sentence under Not reported.

- **Next wider rule, not built**: report every class-like that no PSR-4 or PSR-0 prefix claims
  wherever it is referenced from another file. Not built because the hazard is absent: an
  unclaimed class is never asked of the project's autoloader, so where it is declared decides
  nothing. It would report the `Composer\*` stubs in `tests/assets/ComposerPlugin/composer-stubs.php`,
  which `src/ComposerPlugin/` names and Composer supplies at run time. "One class-like per file"
  is not this hazard: a helper used only in its own file is loaded whenever its file is. The
  [`psr4` lane](../tools/psr4Validate.md) reports a second class-like in a file under a PSR-4
  root on its own account, as a class no autoloader can load.

- **Runner check**: `PipelineTest.php`, run alone with `bin/qa -t unit -p`, pins the reported
  behaviour: it failed with `Class "…\AlwaysRetry" not found` before the fix and passes after it.
  The rule's own test pins every position, message and narrowing on fixtures.

## Implementation

- Rule: [`ForbidUnautoloadableClassReferenceRule`](../../src/PHPStan/Rules/ForbidUnautoloadableClassReferenceRule.php).
- Decision against the mapping: [`AutoloadPathDetector`](../../src/PHPStan/Rules/AutoloadPathDetector.php).
- What counts as a reference, and where: [`ClassReferenceScanner`](../../src/PHPStan/Rules/ClassReferenceScanner.php)
  and [`ClassReferencePositionEnum`](../../src/PHPStan/Rules/ClassReferencePositionEnum.php).
