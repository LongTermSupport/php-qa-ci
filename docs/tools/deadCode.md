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
  `--autoload-file`. PHPStan gets the detector unpacked into `var/qa/cache/dead-code-detector/`,
  once per PHAR version, rather than the PHAR itself: with Turbo, PHPStan forks its workers, and
  forked workers reading a second PHAR corrupt each other's reads. Each run removes everything
  else in that directory (copies of earlier PHARs, staging a killed run left); an entry it cannot
  remove is named in a `WARNING:` line and left for you to delete. Nothing is installed through
  Composer, so the PHPStan gate never sees the detector.
- PHPStan's cache (its `tmpDir`) is `var/qa/cache/deadCode/`, apart from the PHPStan lane's
  `var/qa/cache/phpstan/`: PHPStan keeps one result cache per `tmpDir`, so two configurations
  sharing one would invalidate each other's on every run. Deleting `var/qa/` resets it. A `tmpDir`
  the project's `phpstan.neon` chain sets is used instead, including one behind an include only
  PHPStan can resolve, found the same way as in the [PHPStan lane](phpstan.md#how-the-lane-runs);
  PHPStan is asked with the detector's `--autoload-file`, as the analysis runs.
- Analyses `src/`, `tests/` and every entry point listed with `withDeadCodeEntryPoints()`, less
  the `withIgnoredPaths()` paths, which it excludes exactly as the [PHPStan lane](phpstan.md)
  does. Code under an ignored path is not analysed, so a member reached only from there is
  reported as unused: ignore generated production code only if nothing hand-written depends on
  it being called from there.
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

## Every PHP script under bin/ is declared

A list that was right when written drifts as scripts are added. A script left off it is analysed
by nothing, so its own defects are never reported and every member only it reaches is reported
dead. Before anything runs, the lane therefore reads every file under the project's `bin/`, at any
depth and through symlinks, and fails, naming each one, when a PHP script there is one its run
would not read:

```text
PHP scripts under bin/ not declared as dead-code entry points:
  bin/cron/job
  bin/worker
```

A file is PHP by its content, never its name, read after an optional UTF-8 byte-order mark: a `#!`
line whose command is a PHP binary (`php`, `php-cgi` or `phpdbg`, versioned or not, such as
`php8.5`), directly or through `env` past its options and `NAME=value` assignments
(`#!/usr/bin/env -S FOO=1 php -d x=y`); or, with no `#!` line, a `<?php` open tag in any case, or
a `<?=` tag, after nothing but blank space. A `#!` line naming any other command decides the file
is not PHP, whatever follows it. Each file is judged by
the file it resolves to, so `bin/worker` linking to `../scripts/worker` hides that script exactly
as a regular file would.

A PHP file is not required when the run already analyses it. What the run analyses is what
PHPStan makes of the lane's paths (`src/`, `tests/` and the declared entry points) merged with the
project's own `phpstan.neon` `paths`, `excludePaths` and `fileExtensions`, read from the one
`dump-parameters` answer the lane also takes its cache directory from. It is judged as PHPStan
judges it:

- a listed file, a declared entry point or a `paths` entry, is analysed whatever its name;
- a listed directory analyses its files with a `fileExtensions` suffix (`.php` by default, matched
  case-sensitively), except dot files, files in dot directories and the VCS directories Symfony
  Finder leaves out; so `bin/app` linking to `../src/app.php` is analysed and `bin/app` linking to
  `../src/app` is not;
- either is not analysed when an `excludePaths` entry matches it, by name, by directory or by
  pattern, so `bin/app` linking to a `.php` file in an excluded `src/legacy/` is required, and so is
  a declared script the project's `phpstan.neon` excludes.

Declaring a link by its `bin/` path or by its target's path both count. These are not required
either:

- a script in another language, such as a Bash wrapper, which PHPStan does not read;
- a Composer binary proxy (Composer writes one into `bin/` for each package binary when its
  `bin-dir` is `bin`). A proxy is recognised by the template Composer's `BinaryInstaller` writes: a
  `#!` line, the open tag and the header docblock naming the included path, then
  `namespace Composer;`, with `return include` as the last statement, whatever it includes. It
  holds no code of its own, and Composer rewrites it on every install. That covers a
  path-repository package, whose script the proxy includes through a link in the vendor dir. A
  script of the project's own that quotes Composer's header, or that adds code after it, is
  required;
- a file that resolves into Composer's vendor dir (`config.vendor-dir`, default `vendor`, wherever
  it is, `bin/` included) or out of the project: the project cannot change code it does not own,
  so a finding there could not be fixed;
- a file under a `withIgnoredPaths()` entry, by its `bin/` path or its target's: the project has
  recorded that the tree is not analysed, and every lane honours that;
- a file git ignores, by its `bin/` path or its target's, as one
  `git ls-files --others --ignored --exclude-standard --directory` in the project root lists it:
  it is never committed, so CI never has it, and requiring it would make the verdict depend on one
  checkout's local state. Symfony's `simple-phpunit` installs PHPUnit in `bin/.phpunit/`, which a
  Symfony project git-ignores, so it is skipped this way with no setting. A tracked file is
  committed, so it is never skipped as ignored. When git cannot answer (the project is not a git
  checkout), the lane says so and skips nothing as ignored;
- a dangling symlink, which holds no script to analyse.

Every other subdirectory of `bin/` is read: a first-party `bin/cron/job` is as unanalysed as
`bin/worker`. A symlinked directory is read once, by its real path, so a link back up the tree
ends. A linked directory whose real path is out of the project or in the vendor dir is not
entered, so a link to `/` reads nothing. A path the lane cannot read (a directory it cannot list,
a file it cannot open, a path too long to resolve) fails the lane, named, because it may hold an
undeclared script:

```text
Paths under bin/ the lane could not read:
  bin/locked (scandir(/project/bin/locked): Failed to open directory: Permission denied)
```

`withoutDeadCodeEntryPoints()` with a PHP script under `bin/` fails the same way. The fix is to
list the script with `withDeadCodeEntryPoints()`, or to remove the `excludePaths` entry that takes
it out of the run. Declaring it brings it into the analysis, so expect the run that follows to
report what the script had been hiding.

## How to fix a failure

Each report names a member and why it is dead. The fix is to delete it, or to wire in the caller
the report shows is missing. Two shapes are not dead code:

- **Entry point.** A method only a script or an external runtime calls. List the script with
  `withDeadCodeEntryPoints()`; a class an external runtime drives (a Composer plugin, a console
  command registered by name) is `@api`, and the detector treats every `@api` class as reached.
- **Tests only** ("all usages excluded by tests excluder"). Production never calls it. Delete it
  with the tests that used it, or move it into the tests tree if it is a test helper.

Never suppress with an ignore comment or a baseline.

## Class record

The record for the check in [Every PHP script under bin/ is declared](#every-php-script-under-bin-is-declared)
([#277](https://github.com/LongTermSupport/php-qa-ci/issues/277)).

- **Class**: a PHP file reachable under `bin/` that the lane's PHPStan run analyses by no path,
  judged from PHPStan's own merged `paths`, `excludePaths` and `fileExtensions` for the run.

- **Hazard**: a PHP script nothing analyses, so its own defects go unreported and every member
  only it reaches is reported dead.

- **Searches**:

  - A content scan of every file under this repository's `bin/` for a PHP `#!` line or open tag,
    each checked against the `withDeadCodeEntryPoints()` list in `qaConfig/qa.php`.
  - A cross-check of the declarations against the scripts this repository says it ships:
    composer.json `bin` and the `bin/` allow-list in `.gitignore`.

  Both found the same two undeclared scripts, `bin/autoload-included-files` and
  `bin/opcache-optimizer-dump`, and nothing else. A third pass took each shape a `bin/` entry can
  have and asked whether the hazard holds for it: a symlink (judged by its target), a subdirectory
  (a first-party `bin/cron/job` is as unanalysed as `bin/worker`), a link to a `.php` file under
  `src/` (already analysed, so not the hazard), a BOM, an open tag in another case, `env` with
  assignments, and the `phpdbg` and `php-cgi` binaries. It also read the proxy template in
  Composer's `BinaryInstaller` for the shape the proxy check matches. Each shape is a case in
  `BinEntryPointScriptsTest`. The scan of `bin/` at full depth found no subdirectory and no link
  in this repository.

  A second verification bounded the class against the project's PHPStan configuration and the
  tree a link can reach. It read PHPStan's `FileFinder`, `DirectoryWalker` and `FileExcluder` in
  the shipped phar and probed `dump-parameters` with a project neon that adds `paths`,
  `fileExtensions` and `excludePaths`. Each bound and its outcome:

  - **Too broad**: a script the project's `paths` lists, or a file with a suffix its
    `fileExtensions` adds, was reported though the run analyses it. It is no longer reported.
  - **Too narrow**: a `.php` file the project's `excludePaths` removes counted as analysed. It is
    now required, and so is a declared script the project excludes. This repository's own neon
    excludes `tests/Small/ComposerPlugin/*`, which no `bin/` entry reaches.
  - **Proxies**: a real Composer proxy for a path-repository package was required. A proxy is now
    recognised by Composer's template whatever it includes.
  - **The walk**: it entered linked directories out of the project (a link to `/usr/share` took
    3 s) and threw on a tree it could not read. It now enters neither those nor the vendor dir,
    and it reports an unreadable path instead of throwing.
  - **Git-ignored files**: `bin/.phpunit`, Symfony `simple-phpunit`'s install, gave 51 false
    reports. A git-ignored file is no longer required. The sentence above holds because an
    ignored file is never committed: CI never has it, so the lane's verdict there cannot depend
    on it.

  Each is a case in `DeadCodeToolTest` or `BinEntryPointScriptsTest`.

- **Next wider rule, and the bounds left for the Owner**: none of these is left unbuilt for want
  of a hazard, so each is an Owner decision, not the practitioner's.

  - **A PHP file anywhere in the project that the run analyses by no path**, not only under
    `bin/`. That is an extensionless PHP script outside `bin/`, which `analysedPaths` does not
    count because it counts `.php` files only. The only such script in this repository is
    `scripts/issue-monitor`, which is declared. Building it means a content scan of the whole
    project.
  - **A link out of the project to first-party code**, in a monorepo where `bin/worker` links to
    `../../shared/bin/worker`. The sentence "the project cannot change code it does not own" does
    not hold there, so skipping it is a suppression unless the Owner rules otherwise.
  - **A file PHPStan reaches through a link inside an analysed directory**, such as
    `src/tool.php` linking to `../bin/tool`. PHPStan analyses it there, but this check judges each
    file by its real path and so reports it. That is a false report, and fixing it means walking
    every analysed directory for links.
  - **Rarer `#!` shapes are not recognised as PHP**: `env frankenphp php-cli`, `php-8.5`,
    `zts-php`, `nice php` and `env --split-string=php`.
  - **A file in Composer's proxy template with first-party code between its header and its last
    `return include`** is skipped as a proxy. Composer rewrites such a file on every install, but
    the sentence does not cover one a person edited.

- **Runner check**: `BinEntryPointScriptsTest`, `AnalysedFilesTest` and `GitIgnoredFilesTest`
  pin each judgement on both sides of its boundary. `DeadCodeToolTest` pins the lane through
  the dump-parameters answer it reads, run once. It pins that the lane fails, with the identifier
  and before PHPStan analyses anything, on an undeclared script, a link to one, a script in a
  subdirectory, a script the project excludes and a path it cannot read. It also pins that the lane
  runs the detector when the only script is one the run analyses, sits under an ignored path or
  is git-ignored, and that it does not enter a linked directory out of the project or in the
  vendor dir.

## Implementation

- Lane: [`DeadCodeTool`](../../src/Pipeline/Lane/DeadCodeTool.php).
- Options: [`DeadCodeOptionsDto`](../../src/Pipeline/Config/Dto/DeadCodeOptionsDto.php), set from
  `QaConfigBuilder::withDeadCodeDetection()`, `withDeadCodeEntryPoints()` and
  `withoutDeadCodeEntryPoints()`.
- PHAR: `vendor-phar/dead-code-detector.phar`, built by `scripts/build-phar.bash dead-code-detector` from `build/dead-code-detector/` (the manifest `replace`s `phpstan/phpstan`
  so the PHAR holds the detector alone, not a second PHPStan). Upstream publishes no PHAR.
