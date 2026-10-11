# `phpqaci.processStopOnThrow` — stop a running child in a finally

**Rule**: `RequireProcessStopOnThrowRule` (with `ProcessLiveCodeCallCollector`,
`ProcessStoppingFinallyCollector` and `ProcessStoppingTearDownCollector`)
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

A call on a `Symfony\Component\Process\Process` (or a subtype, decided by the receiver's type,
never its name) after which first-party code runs while the child is alive:

- `start()`, because the code after it runs with the child running;
- `run()`, `mustRun()`, `wait()` or `waitUntil()` given a callback, because the callback runs
  with the child running;

unless the call is in the `try` block, or a `catch` block, of a `try` statement whose `finally`
calls `stop()` on the same receiver expression (`$process`, `$this->process`), or, in a PHPUnit
test case, the receiver is a `$this->` property that the `tearDown()` or an `#[After]` method
PHPUnit runs after the test stops.

```php
$this->running->add($process);

try {
    $process->run(function (string $type, string $buffer): void {
        $this->output->write($buffer); // may throw
    });
} finally {
    $this->running->remove($process); // the child is unregistered, but never stopped
}
```

A call inside the `finally` itself fires: code there that throws skips the rest of the
`finally`. So does a call in a closure defined inside the `try`, because the closure runs later,
outside it.

## Why this is a hazard

If the callback, or the code after `start()`, throws, nothing stops the child. A `Process`
stops its child in its destructor, but it holds its own wrapped callback, which refers back to
it, so the object sits in a reference cycle and the destructor runs only when PHP's cycle
collector happens to free it. Until then the child keeps running, with nothing in the caller
able to reach it: in php-qa-ci's own runner it had already been removed from the registry an
interrupted run stops.

## The correct construction

Put the call inside the `try`, and stop the child in the `finally` when it is still running.
`stop()` on a process that has already finished does nothing, so the `isRunning()` check only
saves the call:

```php
try {
    $process->run(function (string $type, string $buffer): void {
        $this->output->write($buffer);
    });
} finally {
    if ($process->isRunning()) {
        $process->stop(0.0);
    }

    $this->running->remove($process);
}
```

The same shape holds for `start()`: start inside the `try`, do the work, `wait()`, and stop in
the `finally`.

Where no code of yours needs to run while the child is alive, call `run()` or `mustRun()` with no
callback and read the output afterwards with `getOutput()`; the rule does not apply.

In a test, where the child outlives one method (a helper starts it, the test asserts against
it), hold it in a property and stop it in `tearDown()`, which PHPUnit runs after a failing test:

```php
private ?Process $child = null;

protected function tearDown(): void
{
    $this->child?->stop(0);
}
```

## What is still allowed

`run()`, `mustRun()` and `wait()` with no callback, or a `null` one. This is a Narrowing:
no first-party code runs while the child is alive, and Symfony stops the child itself before
throwing its timeout exception.

A `start()` with no callback that is the statement immediately before a `try` whose `finally`
stops the same receiver. This is a Narrowing: nothing of the caller's runs between `start()`
returning and the `try` being entered, and with no callback, `start()` runs only Symfony's own
code, so the child is never alive outside the guard. A `start($callback)` there is still
reported, because the callback can run inside `start()`; so is a statement between the two.

```php
$process->start();
try {
    $process->wait();
} finally {
    $process->stop(0.0);
}
```

A `$this->` property of a PHPUnit test case that the `tearDown()` PHPUnit runs, or any
`#[After]` method in its hierarchy, stops. This is a Narrowing: PHPUnit runs those after a test
method that throws, so the child is stopped. Only the nearest `tearDown()` runs, and an
ancestor's only through `parent::tearDown()`, and the rule follows that. A base test case is
seen only when its file is analysed in the same run, which a full run always does.

A call on anything that is not a `Process` is not looked at, whatever its method names.

Not covered: a call whose method name is built at run time (`$process->{$method}($callback)`).
The rule cannot tell which method it is, so it does not report it; write the method name out.

## Defence Before Fix record

Raised by [#154](https://github.com/LongTermSupport/php-qa-ci/issues/154).

- **Class**: first-party code starts a Symfony `Process` and runs its own code while the child is
  alive (a callback given to `run`, `mustRun`, `wait` or `waitUntil`, or anything after `start`),
  without a `finally` that stops it.
- **Hazard**: if that code throws, the child is left running, unstoppable by the caller, until the
  garbage collector happens to free the `Process`.
- **Search, two techniques**: a text search across tracked PHP for every `Process` call given a
  callback and every `start(` call; and a reading of every file that imports
  `Symfony\Component\Process\Process`. In `src/` both found one instance,
  `SymfonyProcessRunner::run()` in `src/Pipeline/Process/SymfonyProcessRunner.php`;
  `GitPhpstanDocsFetcher` calls `mustRun()` with no callback, so it is not an instance. The
  first pass looked at `src/` only. The rule's sweep of `tests/` reported eleven `start()` calls
  as well, and both techniques, repeated over `tests/`, found the same eleven. Four, in
  `ProcessTreeTest`, start `$this->parent`, which the class's `tearDown()` stops; they are not
  instances, and the tearDown Narrowing below excludes them. That leaves eight instances: the
  runner, and seven `start()` calls in `ProcessTreeTest` (`$finished`), `RunningProcessesTest`
  (two), `RunLockSignalTest`, `ProjectTreeLedgerTest` and `TempLeakDirectoryTest` (two).
- **Narrowing**: `run()`/`mustRun()`/`wait()` with NO callback are excluded, because no
  first-party code runs while the child is alive, and Symfony stops the child itself before
  throwing its timeout exception. A `$this->` property of a PHPUnit test case that the running
  `tearDown()` or an `#[After]` method stops is excluded, because PHPUnit runs those after a
  test method that throws, so the child is stopped. A call in a `catch` block of a `try` whose
  `finally` stops the receiver is not reported, because PHP runs the `finally` after the catch
  block whether it completes or throws. A `start()` with no callback that is the statement
  immediately before a `try` whose `finally` stops the receiver is excluded, because nothing of
  the caller's runs between `start()` returning and the `try` being entered.
- **Next wider rule, not built**: any child process that keeps running past a throw without a
  `finally` that ends it, whatever started it: `proc_open()` without
  `proc_terminate()`/`proc_close()` in a `finally`, as well as `Process`. It is not built because
  the hazard is absent at every instance of the wider pattern. No other process library is
  used, and `proc_open()` appears three times, all in test code: the polling loop in
  `tests/Large/Markdown/LinksCheckerTest.php` catches every `Throwable` while the server is
  alive and its caller terminates the server in a `finally`, and the two `tests/assets/deploySkills/` runners run only global stream functions,
  which return `false` rather than throw, between `proc_open()` and `proc_close()`.
- **Runner check**: `SymfonyProcessRunnerTest::aRunThatThrowsLeavesNothingRegistered` pins the
  reported behaviour: a run whose output write throws must leave nothing registered, and no
  child of the test process may be left alive once the run has thrown.
  `aRunThatThrowsKillsItsChildWithoutWaiting` adds that a child ignoring SIGTERM is killed at
  once rather than waited for.
