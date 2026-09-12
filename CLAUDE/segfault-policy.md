# Segfault policy: halt, debug, file

**A PHP segfault is never an acceptable outcome, and never something to route around.**
Not a flaky test, not a resource limit, not "the container being odd". A segfault means
the engine executed something it had no handler for, and the same defect that crashed
this process silently returns a wrong answer in the process where the bad address
happens to land inside the frame. The crash is the lucky case.

This holds wherever it appears: a test run, a QA lane, an Infection mutant, a CLI
script, FPM under load, CI. It holds for exit 139 and for any `segfault at …` line in
the kernel log, even when the surrounding command reported success.

## The procedure

1. **HALT.** Stop the work in flight. Do not retry, do not re-run to see if it
   recurs, do not disable the thing that triggered it, and do not accept a green
   pipeline that contains one. Nothing downstream of an unexplained segfault is
   trustworthy.
2. **DEBUG.** Reduce it to the smallest code that still crashes, and establish *what*
   in the runtime produces it — the opcodes, the ini setting, the extension. Reducing
   to the smallest code that shows a *symptom* is not the same as the smallest that
   *crashes*; the crash is the thing worth minimising, because it is the thing a
   maintainer can run. Take the crash apart before forming a theory about it.
3. **FILE.** Report it upstream, with the minimal reproduction, at
   <https://github.com/php/php-src/issues/new/choose>. How php-src wants it — public
   issue vs security advisory, required fields, house style, its LLM-disclosure rule,
   and the shape a fix PR takes — is
   [Plan 00009's filing guide](Plan/Completed/00009-upstream-php-src-bug-report-opcache-const-comparison/filing-guide.md).
   **Filing is authorised per-report by the owner, never standing** — it posts publicly
   under a real identity and cannot be retracted.
4. **DEFEND.** Where the defect class can be detected in bytecode or source, add the
   detection to php-qa-ci so a consumer cannot ship an instance while upstream works.
   That is [DefenceBeforeFix](DefenceBeforeFix.md) applied to an engine defect, and
   whether it is a new tool or an assertion inside an existing lane is decided by
   [tool-boundaries.md](tool-boundaries.md) — usually an assertion.

## The archetype

[Plan 00007](Plan/Completed/00007-opcache-optimizer-const-comparison-crash/PLAN.md) is the worked
example of all four steps, and
[Plan 00009](Plan/Completed/00009-upstream-php-src-bug-report-opcache-const-comparison/PLAN.md)
is the filing half. Read them before starting a fresh one; the sequence is the point,
not the specific defect.

Two things about it are worth carrying to the next occurrence:

- **It was invisible.** Five identical segfaults happened inside Infection mutant runs
  and every pipeline still exited 0, because Infection counts a non-zero exit as a
  killed mutant. Nothing in the pipeline reads the kernel log. **A green run is not
  evidence that nothing crashed** — check the host's log when anything smells wrong.
- **The first theory was wrong, and that was fine.** We reported it as the optimizer
  failing to fold a constant comparison. Upstream's verdict was that the fold is a
  missed optimisation and the actual defect is elsewhere (see
  [the report](Plan/Completed/00007-opcache-optimizer-const-comparison-crash/upstream-report.md)
  for both). A precise reproduction with an honest account of what was measured
  survives a wrong theory; a confident theory without one does not.

## Core dumps in a container

**Expect `coredumpctl` inside the container to be empty, and do not read that as "no
core was produced".** The kernel is the host's, so `/proc/sys/kernel/core_pattern` is
the host's too: on a systemd host it pipes to the host's `systemd-coredump`, and the
core lands in the host's journal, outside the container entirely.

So the evidence lives in two places the container cannot see:

- the **kernel log** on the host, which is where the `segfault at <addr> ip <addr>`
  lines are, and
- the **core file** in the host's coredump storage.

Both have to be fetched by whoever administers the host. **Ask for them explicitly —
name the PID and the approximate time, ask for the `segfault` lines and for the core to
be copied into the container, and say that the debug symbols for the *same build* are
needed with it.** That last part matters: a backtrace is only as good as the symbols,
and a build from a different distro packaging of the same PHP version produces
different line numbers in generated files such as `Zend/zend_vm_execute.h`.

Do not block on the core if the crash reproduces. A reproduction a maintainer can run
is worth more than a backtrace, and in the archetype the backtrace came from a
different build of the same version than the reproduction did — which had to be said
plainly in the report rather than glossed.
