# A Process whose child a signal kills is reported by name

**Identifier**: `phpqaci.unhandledProcessSignal` — opt-in, see [Optional Rules](../tools/phpstan.md#optional-rules)

## What it requires

A call that waits for the child of a Symfony `Process` to end, `run()`, `mustRun()` or `wait()`,
sits in the `try` block of a `try` with a `catch` naming `ProcessSignaledException`, alone or in a
union (`catch (ProcessFailedException|ProcessSignaledException $e)`). Reported, outside test code:

- such a call with no `try` around it, or in a `try` whose catches name only other types,
  including a supertype such as `RuntimeException`;
- such a call in a `catch` or `finally` block, which a catch of the same `try` does not guard;
- such a call in a closure, arrow function, function or class declared inside the `try` block,
  which runs whenever it is called, possibly after the `try` has ended.

## Why

`run()`, `mustRun()` and `wait()` throw `ProcessSignaledException` when a signal the `Process`
did not send itself killed the child: a segmentation fault (SIGSEGV), an out-of-memory kill
(SIGKILL), a SIGTERM from outside. Uncaught, it surfaces as

```text
The process has been signaled with signal "11".
```

with no name for the signal, no word for what it means, no command, and a location inside
Symfony's `Process.php`. It also abandons whatever called it. In php-qa-ci that was every
remaining lane of a `bin/qa` run, after a lane whose tool segfaulted, with no lane named and an
exit code of 1 ([#275](https://github.com/LongTermSupport/php-qa-ci/issues/275)). A segfault is
a defect to halt on, debug and file, and it can only be handled once something names it.

## What is not reported

- **Test code**: a file under the project's own `tests/` directory, or a namespace with a segment
  ending in `Tests`. There the exception fails the one test that ran the child, and PHPUnit
  reports it against that test with the signal's number.
- **A call that does not wait**: `start()` returns while the child runs, and `waitUntil()` returns
  `false` when the child has gone rather than throwing.
- **A first-class callable** such as `$process->run(...)`, which runs nothing until called.
- **A catch of a supertype that tests for the subtype inside it**: only a catch naming
  `ProcessSignaledException` is read, so such code is reported; catch the subtype by name instead.

## How to fix it

Catch the exception where the child is waited for, and report the command and the signal there.
`ProcessSignaledException::getSignal()` gives the signal's number; a shell would report the
child's end as exit code 128 plus that number.

```php
// WRONG — a segfault reads as "The process has been signaled with signal "11"."
$process->run();

// RIGHT — the command and the signal are named, and a segfault is told apart
try {
    $process->run();
} catch (ProcessSignaledException $signaled) {
    $signal = $signaled->getSignal();

    throw new RuntimeException(sprintf(
        '%s was killed by signal %d%s',
        $process->getCommandLine(),
        $signal,
        SIGSEGV === $signal ? ', a segmentation fault: halt on it, debug it and report it' : '',
    ), 0, $signaled);
}
```

In php-qa-ci, `SymfonyProcessRunner` turns such a child into a result with exit code 128 plus the
signal and records it, and the pipeline reports the lane that ran it as crashed; see
[the pipeline's exit codes](../pipeline.md#exit-codes).

## Defence Before Fix record

- **Class**: a wait for a Symfony `Process` child, `run()`, `mustRun()` or `wait()`, with no catch
  naming the `ProcessSignaledException` it throws when a signal the `Process` did not send kills
  the child.
- **Hazard**: a child killed by a signal, a segmentation fault among them, surfaces as
  `The process has been signaled with signal "11".`, naming neither the command nor the signal,
  and abandons whatever waited for it: in `bin/qa`, every remaining lane.
- **Search, two techniques**: a structural text search, and a reading of the call paths.
  - **Text search.** Over `src/`, `bin/`, `scripts/`, `templates/`, `configDefaults/` and
    `qaConfig/`, the search looked for `new Process(`, `fromShellCommandline`, imports of
    `Symfony\Component\Process\Process`, `proc_open`, `passthru` and `exec`, and for every
    `->run(`, `->mustRun(`, `->wait(` and `->start(` call. Each call was then kept only if its
    receiver is a `Process`. Two such waits were found: `$process->run()` in
    `SymfonyProcessRunner` and `$process->mustRun()` in `GitPhpstanDocsFetcher`. The search
    found no `->wait(` call at all. `RunningProcesses` holds `Process` objects, but it only
    signals and stops them, which throws nothing. Every other `->run(` call was on a
    `ProcessRunnerInterface`, a tool or a command.
  - **Reading the call paths.** `Process::wait()` is the only method that throws the exception,
    and `run()` and `mustRun()` reach it through `wait()`. `waitUntil()`, `start()` and
    `getIterator()` do not reach it, so the method set is complete. Every other child process in
    `src/` goes through `ProcessRunnerInterface` to `SymfonyProcessRunner`, so the reading found
    no wait beyond the two. It also found the runner's callers that read an exit code as an
    answer, such as `ChangelogGit`. Those callers are why a runner with no record throws, naming
    the child, rather than passing a crash back as an exit code.
  - **The rule's sweep** found the same two, at `SymfonyProcessRunner.php:62` and
    `GitPhpstanDocsFetcher.php:51`, and nothing the two techniques had missed.
- **Instances at the sweep**: two, both in `src/`; test code is not swept.
- **Narrowing**: test code is excluded, because there the exception fails the one test that ran
  the child, and PHPUnit names that test. `start()`, `waitUntil()` and a first-class callable
  wait for nothing, so they are excluded too.
- **Next wider rule, not built**: any Symfony `Process` exception left uncaught at a wait,
  `ProcessFailedException` from `mustRun()` and `ProcessTimedOutException` as well. It was not
  built because those exceptions lack the hazard. Their messages name the command
  (`The command "…" failed.`, `The process "…" exceeded the timeout of … seconds.`), so neither
  surfaces unnamed. A program that relays a crash as an ordinary exit code 139, such as a shell or
  a tool that re-runs itself, is a plain integer that no static rule can tell apart. The runner
  handles that case at run time.
- **Runner check**: these tests pin the reported behaviour:
  - `SymfonyProcessRunnerTest`: a child that a signal kills is a result, and it is recorded and
    named. Without a record, the runner throws, naming the command and the signal.
  - `ToolExecutorTest`: the lane is reported as crashed, with the description.
  - `PipelineTest`: a segfault halts an aggregate run and the copy/paste report. Any other signal
    crashes the lane and the run carries on. A start-up probe or a hook whose child was killed
    halts the run.
  - `QaApplicationTest::aLaneWhoseChildASignalKillsIsNamedAsCrashed`: the same, end to end,
    through the real runner and a child that kills itself.
  - `GitPhpstanDocsFetcherTest::aGitKilledByASignalFailsNamingTheCommandAndTheSignal`.
- **Fix**: `SymfonyProcessRunner` catches the exception and records the child, and the pipeline
  reports the lane as crashed. `GitPhpstanDocsFetcher` throws, naming the command and the signal.
  The sweep now finds none.

## Why it is opt-in

A project whose code never waits for a child process has nothing to fix, and the right response to
a signalled child is the project's own: a QA tool halts, a worker may retry. php-qa-ci runs it on
itself.

## Implementation

- Rule: [`ForbidUnhandledProcessSignalRule`](../../src/PHPStan/Rules/ForbidUnhandledProcessSignalRule.php)
- Collector: [`ProcessSignalHandlingCollector`](../../src/PHPStan/Rules/ProcessSignalHandlingCollector.php)
