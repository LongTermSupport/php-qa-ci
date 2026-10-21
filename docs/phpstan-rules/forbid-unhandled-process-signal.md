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

## Why it is opt-in

A project whose code never waits for a child process has nothing to fix, and the right response to
a signalled child is the project's own: a QA tool halts, a worker may retry. php-qa-ci runs it on
itself.

## Implementation

- Rule: [`ForbidUnhandledProcessSignalRule`](../../src/PHPStan/Rules/ForbidUnhandledProcessSignalRule.php)
- Collector: [`ProcessSignalHandlingCollector`](../../src/PHPStan/Rules/ProcessSignalHandlingCollector.php)
