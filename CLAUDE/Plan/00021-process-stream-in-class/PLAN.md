# Plan 00021: process stream in class

**Status**: In Progress
**Created**: 2026-10-11
**Owner**: dev
**Priority**: High

## Overview

A full Infection run that does not pass `--skip-initial-tests` dies part-way through its initial
test run with PHPUnit exit 143. Infection 0.35's `InitialTestsRunner` calls `$process->stop()` on
the first chunk PHPUnit writes to stderr, and `SingleRuleReportTest::aRunTheLaneReportedAsNoVerdictIsNotAnAnswer`
calls `SingleRuleReport::main()`, which writes its refusal straight to the process's `STDERR`. Random
order moves the test, so the kill lands anywhere from a quarter to nine tenths of the way through.
The pipeline's Infection lane always skips the initial tests, so the gate and CI never showed it;
measuring one file's mutation score by hand did.

The same construction leaves the output unasserted: a test cannot capture what a class writes to the
process's own stream, so a mutant that drops the write survives. This plan builds the Defence for the
class, then fixes every instance by handing the stream in.

## Defence Before Fix record (method 3.1)

- **Class**: a class-like (method, constructor or closure inside a class, or any function body)
  writes to or reads from the process's own standard stream: the `STDIN`, `STDOUT` or `STDERR`
  constant, or a `php://stdin`, `php://stdout`, `php://stderr` or `php://fd/N` stream it opens itself.

- **Hazard**: code a test can call reaches past the test runner to the real process stream, so its
  output can be neither captured nor asserted, and a stderr write stops Infection's initial test run.

- **Technique 1, a reading of what actually reaches the stream**: the whole suite run with stderr
  redirected to a file (`bin/phpunit -c qaConfig/phpunit.xml --no-coverage 2>file`). One line came
  out, from `SingleRuleReport::main()`. This finds only the instances a test reaches today.

- **Technique 2, a token search**: every first-party PHP file searched for `STDIN`, `STDOUT`,
  `STDERR` and the `php://` stream names. Inside a class or function it found:

  - `SingleRuleReport` (2 writes);
  - `ActiveRulesLister` (2);
  - `ArkitectRuleProbe` (2 to `STDERR`, 1 to `STDOUT`);
  - `ShellCheckInstaller` (7 writes and a `STDOUT` parameter default);
  - `GitHubTurboReleaseSource` (1);
  - the two runner extensions `TempLeakExtension` and `ProjectTreeLeakExtension` (1 each).

  Script-level uses: none in `src/` or `tests/`. The remaining hits are string literals naming
  Infection log targets or `-r` code run in a child process, and so not uses.
  `configDefaults/generic/phparkitect.php` writes from the closure PHPArkitect calls; that file is
  declared unanalysed and runs only inside the PHPArkitect phar's own process, whose output the lane
  and the Large test capture. Technique 2 found everything technique 1 found and more.

- **Technique 3, reading how the code that writes output is built**: `TurboInstaller::main()` builds
  a `ConsoleOutput`, which opens `php://stdout` and `php://stderr` inside Symfony, the same reach
  with no token for technique 2 to find. A search for constructed Symfony outputs found five
  `main()`s doing so: `FullQaBlockerConfigurator`, `IssueMonitor`, `PhpstanDocsInstaller`,
  `PhpstanReplaceSync` and `TurboInstaller`. `QaApplication` builds `StreamOutput`s from streams
  it is handed, which is the correct construction. A search for the functions that reach a stream
  implicitly (`error_log()`, `readline()`) found none. Technique 3 found what technique 2 missed,
  so the class was widened to it.

- **Bounds**: wider than the originating instance. The rule covers:

  - every stream constant, not `STDERR` alone;
  - a process-stream name reaching an opening call, as a literal or through a variable or
    constant PHPStan knows the value of;
  - `new ConsoleOutput()`;
  - `readline()`;
  - `error_log()` to the SAPI logger.

  Not wider: an `echo` or `print` goes through PHP's output buffer, which PHPUnit captures and
  `expectOutputString()` asserts, so it has no hazard.

- **Next wider rule, referred to the Owner**: a stream name handed to a third-party API that opens
  it (Monolog's `new StreamHandler('php://stderr')`). The hazard is real, but whether a library
  opens a name it is given is that library's semantics. A rule reporting any call given such a
  name would also report a test comparing one, which has no hazard. There are no instances in
  this repository. Leaving it unbuilt is an Owner decision (method 3.1, section 4), stated in the
  pull request.

- **Narrowings**, each with the sentence why the hazard cannot arise:

  - Script-level code, outside any function, method, closure or class: it runs only as a process's
    own entry script, so a test can only run it as a child process, whose streams the test captures.
  - A class implementing `PHPUnit\Runner\Extension\Extension`: the test runner loads and calls it,
    and no test does, so its stream is the runner's own.

- **Runner check**: `SingleRuleReportTest` pins the instance. After the fix it asserts the exact
  stderr text through a memory stream, and each other instance's test does the same.

## Goals

- `phpqaci.processStream` (`ForbidProcessStreamRule`), a PHPStan rule in `rules-optional.neon` (so
  php-qa-ci runs it and a consumer opts in), with its documentation page, its index entry and its
  fixture test. A parameter default counts: PHPStan analyses it in the scope around the method, so
  the rule treats a whole class-like body as inside.
- Every instance fixed: the stream is a parameter, which the entry script under `bin/` supplies.
- Every fixed instance's test asserts what it writes.

## Non-Goals

- Default-on for consumers: adding the rule to `rules-default.neon` would fail consumer builds on a
  release, which is a separate decision.
- Infection's behaviour: stopping the initial run on stderr is a documented requirement upstream,
  not a defect there.

## Tasks

### Phase 1: The Defence

- [x] ✅ **Task 1.1**: The rule, test first, with fixtures for each reported and each narrowed form
- [x] ✅ **Task 1.2**: Documentation page, index entry, `rules-optional.neon` entry, changelog entry
- [ ] 🔄 **Task 1.3**: Red run through the pipeline, every instance reported; commit with the instances present.
  The `phpstan` lane reported 16 in `src/`: `ArkitectRuleProbe` 3, `ActiveRulesLister` 2,
  `SingleRuleReport` 2, `ShellCheckInstaller` 8 (one a parameter default), `GitHubTurboReleaseSource` 1.
  That is every instance technique 2 found; the two runner extensions were not reported.

### Phase 2: The fix

- [ ] ⬜ **Task 2.1**: Each instance takes its stream as a parameter; each test asserts the written text
- [ ] ⬜ **Task 2.2**: Full Infection initial run (no `--skip-initial-tests`) completes
- [ ] ⬜ **Task 2.3**: Gate, PR, verification, merge

## Success Criteria

- [ ] The rule reports every instance in the red commit and none after the fix
- [ ] The suite writes nothing to stderr
- [ ] An Infection run without `--skip-initial-tests` gets past its initial test run

## Delivery & Milestones

- <!-- delivery commit hashes -->
