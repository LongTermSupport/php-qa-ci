# Task 0.3: per-file PHPStan latency on edit

Measured by the coordinator on 2026-10-19 in a fresh worktree of `php8.5` (8 cores, PHP 8.5.11,
PHPStan Turbo enabled, load average about 2.5–3.9 while two research agents ran Mago probes).
The script that took the timings is `untracked/scratch/t03-measure.bash`: wall clock around one
`bin/qa -t phpstan -p FILE`, output kept per run under `untracked/scratch/t03-*.log`.

## Facts

### Whole command, `bin/qa -t phpstan -p FILE`

| Case                                                          | File                                                | Exit | Wall time    |
| ------------------------------------------------------------- | --------------------------------------------------- | ---- | ------------ |
| Cold: `var/qa/cache/phpstan` removed first                    | `src/ShellCheck/InstallDecider.php` (51)            | 0    | 6233 ms      |
| Warm, unchanged file, 8 runs                                  | `src/ShellCheck/InstallDecider.php`                 | 0    | 5074–6238 ms |
| Warm, unchanged file, 5 runs                                  | `src/Pipeline/Runner/RunInterruptHandler.php` (115) | 0    | 4562–6491 ms |
| After a real edit that stays clean (one string literal)       | `src/ShellCheck/InstallDecider.php`                 | 0    | 4829 ms      |
| After a real edit that introduces a type error                | `src/ShellCheck/InstallDecider.php`                 | 1    | 6310 ms      |
| Run lock held by a whole-project `bin/qa -t phpstan`          | `src/ShellCheck/InstallDecider.php`                 | 75   | 285 ms       |
| Same, with `PHPQACI_AGENT_MODE=1` (the command consumers set) | `src/ShellCheck/InstallDecider.php`                 | 2    | under 1 s    |

Over all 15 warm samples the range is 4.6–6.5 s, the median about 5.4 s. Cold costs little
more than warm. A whole-project PHPStan run that held the lock took 20 s.

### Where the time goes

One `-p` run makes three PHPStan calls. Timed separately, warm:

| Call                                                                 | Wall time |
| -------------------------------------------------------------------- | --------- |
| `phpstan.phar dump-parameters -c qaConfig/phpstan.neon --json`       | 2091 ms   |
| `phpstan.phar diagnose -c …/phpstan-parallel.neon` (the Turbo probe) | 1351 ms   |
| `phpstan.phar analyse FILE -c …/phpstan-parallel.neon`               | 1824 ms   |
| `php -r 'echo 1;'` (interpreter start, for scale)                    | 45 ms     |
| `bin/qa -h` (pipeline start, for scale)                              | 104 ms    |

The analysis itself is about 1.8 s, which matches the research report's 1.2–1.9 s. About 3.4 s
of every edit-time run goes on `dump-parameters` and `diagnose`, which depend only on the
configuration and the installed PHPStan, not on the edited file.

### Lock contention on edit

- In agent mode, `qa` prints to stdout: `PHPSTAN AGENT MODE: lock-held — another QA run holds the project lock (…). No analysis ran and no report was written; … Wait and re-run.` It exits 2.
- The daemon's `lint_on_edit` (v3.70.0, `handlers/post_tool_use/lint_on_edit.py`) DENYs on any
  non-zero exit. The exceptions are a strategy that classifies the output as "tool unavailable"
  or "mid-edit", and the PHP strategy has neither classifier. So every PHP edit made while a
  full run holds the lock is reported as `PHP lint FAILED`. The text is accurate (nothing ran),
  but the decision is a denial of an edit that was never checked.
- A timeout, by contrast, ALLOWs (fail-open) after the 15 s default, configurable per language
  through `options.timeouts.PHP`.

### This repository's own edit-time lint

Every Edit in this session drew `PHP lint tool not found (phpstan) - looked in /workspace/vendor/bin, …`. This repository's Composer bin-dir is `bin/`, so the daemon's PHP
extended lint never runs here. Task 4.4 already names this; it is now observed.

## Against the plan's numbers

- The daemon's 15 s per-command limit: met with about 8 s spare at the worst observed sample,
  on a lightly loaded host.
- The Success Criterion "p95 of the wrapper under 5 s": **not met by the PHPStan step alone**
  as it stands. The median is about 5.4 s before any Mago step is added.
- A Mago lint step (about 15 ms per file, research §5) adds nothing measurable. The edit-time
  budget is spent by PHPStan's per-run setup, not by Mago.

## Recommendations (not decisions)

1. Before Phase 4, cut the per-run setup. The two setup calls depend only on configuration and
   the PHAR, so their results can be cached and keyed by a hash of the resolved config files and
   the PHAR. That would bring a warm edit-time run to about 2 s. It is a php-qa-ci change, with
   its own test, filed as [#219](https://github.com/LongTermSupport/php-qa-ci/issues/219), since
   it speeds up every consumer's edit-time lint whether or not Mago lands.
2. Give the Phase 4 wrapper (Task 4.1) a defined lock-held outcome: a Mago-only result marked
   as such, with an explicit "PHPStan did not run: lock held" line. The alternative is a bounded
   wait for the lock. Either way the daemon must not read it as a lint failure. Also raise
   upstream that the PHP strategy should classify the agent-mode `lock-held` line as "not
   analysed" (the same ALLOW-with-warning branch as tool-unavailable), with the body generated
   by `hooks-daemon issue-report`.
3. Re-measure under Task 4.4 once (1) lands. Keep the 5 s p95 target; do not loosen it to fit
   today's number.
