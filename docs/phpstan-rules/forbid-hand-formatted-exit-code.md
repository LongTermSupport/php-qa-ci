# An exit code is said the way the child ended

**Identifier**: `phpqaci.handFormattedExitCode` — opt-in, see [Optional Rules](../tools/phpstan.md#optional-rules)

## What it requires

The exit code of a `ProcessResultDto` reaches text only through
`ProcessResultDto::describeExit()`, or through `ToolResultDto::fromProcessResult()`, which uses it.
Reported:

- `$result->exitCode` as an argument of `sprintf()`, `printf()`, `fprintf()` or `strval()`, an
  operand of `.` or `.=`, a part of an interpolated string, a `(string)` cast, or an `echo` or
  `print`;
- `$result->exitCode` given to a `ChildExit` method that describes a bare code (`describe()`,
  `describePhpQaCiHelper()`, `killedBy()`, `describeExitedItself()`, `exitedItself()`) or to
  `ToolResultDto::fromExitCode()`;
- the same for a local variable the function assigns `$result->exitCode` to.

## Why

A code from 129 to 192 is how a shell reports a child that a signal killed: 128 plus the signal.
A child can also exit with such a code itself. A wrapper does this when a process below it
crashes: a shell whose command segfaulted exits 139, and so does a tool that re-runs itself and
relays its copy's crash. Only the result knows which happened. `SymfonyProcessRunner` sets
`$signal` only when a signal killed the child.

Text built from the bare code gets this wrong in one of two ways
([#288](https://github.com/LongTermSupport/php-qa-ci/issues/288)):

- **It says nothing of a crash.** `rejected the change (exit 139)` called the hooks daemon's
  `config-validate` a rejection when the daemon had crashed.
- **It calls a relayed crash a kill.** `killed by signal 11 (SIGSEGV)` was said of a shell that
  was never killed. It points the reader at the wrong process and makes them look for a core dump
  that the wrapper never produced.

## What is not reported

- **A code PHPStan proves is outside 129 to 192.** For example, `1 === $result->exitCode` before
  the text, or a branch after `if ($result->exitCode > 2) { return ...; }`. Such a code is no
  signal's code, so stating it bare cannot misstate how the child ended.
- **Code inside `ProcessResultDto`.** There the code is described beside `$signal`, which is what
  says whether a signal killed the child. `describeExit()` is that description.
- **A comparison, or a test of the code:** `ChildExit::killedBySignal($result->exitCode)`,
  `$result->exitCode > 1`, `in_array($result->exitCode, ...)`. Nothing is said, so nothing is
  misstated.
- **A shape the rule does not read**: the code given to `vsprintf()` or `json_encode()`, spread
  into a call, or copied through a second local (`$a = $result->exitCode; $b = $a;`). Only the
  functions and operators listed above, and one level of local copy, are followed.
- **An `exitCode` that is not a `ProcessResultDto`'s**, and a bare `int` exit code of unknown
  origin, such as one from `$?` or Composer's `ProcessExecutor`. See the record below.

## How to fix it

```php
// WRONG: 139 relayed by a wrapper reads as an ordinary failure
return ToolResultDto::failed(sprintf('Yaml Lint failed (exit %d)', $result->exitCode));

// WRONG: 139 relayed by a wrapper reads as the wrapper being killed
$io->writeError('failed with ' . ChildExit::describe($result->exitCode));

// RIGHT: "exit 1"; "exit 143, killed by signal 15 (SIGTERM)"; or "exit 139, which it exited
// with itself: the code a shell gives for signal 11 (SIGSEGV), so a process below it crashed ..."
return ToolResultDto::failed(sprintf('Yaml Lint failed (%s)', $result->describeExit()));

// RIGHT: 0 passed, the listed codes failed, anything else crashed, each described as it ended
return ToolResultDto::fromProcessResult($result, 'Yaml Lint', 1);
```

A plain exit code keeps its old wording: `describeExit()` gives `exit 1`.

## Defence Before Fix record

- **Class**: a crash report whose text misstates how the process ended. The mechanism the rule
  reads is an exit code put into text without the record of whether a signal killed the process.
- **Hazard**: the report names the wrong event. A relayed segfault reads as a rejection, a plain
  failure or nothing at all. A wrapper that was never killed reads as killed, sending the reader
  to the wrong process for a crash that halts the run and must be debugged and filed.
- **Search, two techniques**: a text search, and a reading of the code paths that make and
  consume a `ProcessResultDto`.
  - **Text search.** The search covered `src/`, `bin/`, `scripts/`, `templates/`,
    `configDefaults/`, `qaConfig/`, `git-hooks/` and `build/`. It looked for `exit %d`,
    `exit code %d`, `exited %d`, `(exit '` and every `exitCode` token. A Bash pass looked for `$?`
    and the `bin/describe-exit` calls. It found:
    - 35 sites that format a `ProcessResultDto`'s exit code by hand. One is `config-validate`'s
      "rejected the change (exit %d)" in `FullQaBlockerConfigurator`. One is a copy in a local,
      `PhpunitTool`'s `$exitCode`. The other 33 are the summary and console lines of the lanes,
      plus `ArkitectRuleProbe`, `ChangelogGit` and `IssuePoller`.
    - `ChildExit::killedBy($result->exitCode)` in `OpcacheTool` and `PhpstanCrash`, which calls a
      relayed 139 a kill.
    - `PhpunitTool`'s failure line, whose code is at most 2 there.
    - The pipeline's own exit code in `RunLock` and `QaApplication`, which is no child's.
    - The bare-int describers: the Composer plugins, `ToolResultDto::fromExitCode()`, and every
      Bash site through `bin/describe-exit`.
  - **Reading the code paths.** `SymfonyProcessRunner` is the only producer of a real
    `ProcessResultDto`. It sets `$signal` for a kill, and leaves it null for a child that exited
    139 itself, which it records as `SignalledChildDto(exitedItself: true)`. The reading followed
    every consumer and every reader of that record. It found what the text search found, and three
    reports that misstate how a process ended without formatting a code at all:
    - `SignalledChildDto::describe()` called a relayed 139 "killed by signal 11";
    - the probe and hook halt header said "was killed by a signal" for every recorded child;
    - in agent mode, a lane's terse "clean" stood on stdout beside exit 3.
  - **The rule's sweep** found 37 findings at 35 sites before the fix: the 35 above, with
    `OpcacheTool:99` and `PhpstanCrash:94` matched twice each, for the code and for the
    `killedBy()` argument. That is nothing the two techniques had missed, and it did not fire on
    `tests/`.
- **Instances at the sweep**: 35 sites in `src/`, one of them a local copy.
- **Narrowing**:
  - A value PHPStan proves is outside 129 to 192 cannot be read as a signal's code, so stating it
    bare cannot misstate how the child ended.
  - Inside `ProcessResultDto` the code is described beside `$signal`, which says whether a signal
    killed the child.
- **Next wider rules, not built**:
  - **A copy passed out of the function**, as an argument or a property, and formatted elsewhere.
    Following it needs data flow across calls. None of the 35 sites does this, so the hazard was
    not found in that form.
  - **Prose that asserts how a process ended without consulting the record**, such as the halt
    header's "was killed by a signal" or agent mode's "clean". This is a claim in a string, which
    no Detector can tell from any other sentence. The class is a toolchain gap there, and those
    instances are pinned by the runner checks below.
  - **A bare `int` exit code of unknown origin.** This is `$?` in Bash, or the code Composer's
    `ProcessExecutor` returns. `ChildExit::describe()` calls a code from 129 to 192 a kill, the
    shell's own convention, but a wrapper that relays a crash gives the same code. The caller
    holds no record that could say which. Whether that wording should hedge changes every
    Composer plugin and Bash message that reports a child, so it is the Owner's decision, and is
    not taken here.
- **Runner check**: these tests pin the reported behaviour:
  - `ProcessResultDtoTest`, `ChildExitTest::aCodeTheProcessExitedWithItselfIsNotCalledAKill`, and
    `SignalledChildDtoTest`: the descriptions;
  - `SymfonyProcessRunnerTest::aChildThatExitsWithASegfaultsCodeIsRecordedAsOne` and
    `ToolExecutorTest`: the `!!` and `crashed:` lines;
  - `PipelineTest`: the halt header, and agent mode's stdout;
  - `FullQaBlockerConfiguratorTest::aValidatorThatRelaysACrashIsNotCalledARejection`;
  - `ToolResultDtoTest::processResultsMapToOutcomesDescribedAsTheyEnded`;
  - `QaApplicationTest::aLaneWhoseChildExitsWithASegfaultsCodeHaltsTheRun` and
    `agentModeSaysCrashedOnStdoutWhenTheLanesChildRelaysASegfault`, end to end.
- **Fix**: every site says the code with `describeExit()`. A relayed 139 is described as one in
  the `!!`, `crashed:` and halt lines. `config-validate`'s crash is no verdict. Agent mode's
  stdout says crashed whenever the exit code does. The sweep now finds none.

## Why it is opt-in

`ProcessResultDto` is php-qa-ci's own type, so the rule has nothing to say in a project that
neither uses the pipeline's process runner nor registers a lane of its own. A project with lanes in
`qaConfig/pipeline.php` gets the same check over them. php-qa-ci runs it on itself.

## Implementation

- Rule: [`ForbidHandFormattedExitCodeRule`](../../src/PHPStan/Rules/ForbidHandFormattedExitCodeRule.php)
- Collector: [`ExitCodeTextCollector`](../../src/PHPStan/Rules/ExitCodeTextCollector.php)
