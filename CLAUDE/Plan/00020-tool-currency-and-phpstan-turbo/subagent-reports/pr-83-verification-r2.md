# PR #83 verification, round 2: PHPStan Turbo (Plan 00020)

- Pull request: LongTermSupport/php-qa-ci#83, `feature/plan-00020-phpstan-turbo` into `php8.5`
- Head verified: `86d2dba95ce651f0e164a1c5370be5934e1bd4b6` (matches `origin/feature/plan-00020-phpstan-turbo`);
  base read: `origin/php8.5` at `b81709b70ff6bb9e554c162d3ff3b802a638f5f9`, an ancestor of the head
- Round 1: `untracked/agent-reports/pr-83-verification.md`, FAIL on B1 at `1eb9dca`
- Verifier: fresh general-purpose sub-agent, read-only towards the PR. Reproduction ran in my own throwaway
  detached worktree `untracked/worktrees/verify-83-r2` (`composer install`, which also ran the Turbo
  installer), removed afterwards. The author's worktree `plan-00020-turbo` was only read. Scratch
  files, scripts and logs are under `untracked/scratch/verify-83-r2/`.
- Each finding is marked **[reproduced]** or **[read]**.
- No segfault, bus error, exit 139 or exit 135 occurred in any command I ran. I could not read the kernel
  log, because `dmesg` is not permitted in the container.

## Blocking findings

None.

## Non-blocking notes

Notes 1 to 4 are new in this round. Notes 5 to 10 carry over from round 1.

01. **[read] The B1 defect class has a second, unfixed instance: `ShellCheckInstaller`.**
    `src/ShellCheck/ShellCheckInstaller.php:112` stages in `TemporaryDirectory::create()` (the system temp
    dir) and `:126` does `\Safe\rename()` into `vendor-bin/shellcheck`, followed by `chmod`. This is the
    same shape B1 fixed in `TurboInstaller`: across filesystems it copies into the existing file. The
    consequence is milder. An executing `shellcheck` makes the open fail with ETXTBSY, so the install
    fails rather than a process crashing. But a lane that starts during the copy can exec a partly
    written binary. The code predates this PR and runs only on the maintainer `update` path, so it does
    not hold this merge. Under DefenceBeforeFix 3.4 ("fix every instance") it should be raised as an
    issue, or fixed with `TemporaryDirectory::besides()` as Turbo now is.

02. **[read] Neither fix records the class or builds a Detector.** Both B1 and #82 part 2 are defended by red
    tests (filters) only. Since round 1, the plan folder has gained no class/hazard record, no
    two-technique search, no "next wider rule", and no toolchain-gap record. DefenceBeforeFix.md 3.1 and
    3.2 say "A test MUST NOT serve as the Detector". `pr-verification.md` check 3 asks only for "the
    detector or red test before the fix", and both are present and genuine (below), so this is a note.
    The method-level gap should still be recorded: either as a toolchain gap with its reason, or as a
    rule. One candidate rule: a `rename()` whose source is under `TemporaryDirectory::create()` and whose
    target is a published path. That rule would have found note 1.

03. **[reproduced] The #82 class has another member in the PHPStan gate, and it appears benign today.**
    `src/Pipeline/Lane/PhpstanTool.php:340-367` writes `phar://` `scanDirectories` for `phparkitect.phar`
    and `composer-dependency-analyser.phar` into the gate's wrapper neon. My generated
    `var/qa/phpstan_logs/phpstan-parallel.neon` lists both. With Turbo the gate forks, and `src/Arkitect/*`
    references `Arkitect\` classes, so workers do read from `phparkitect.phar`. Two pieces of evidence
    show no shared-descriptor race:

    - 6 of 6 cold-cache `bin/qa -t phpstan` runs passed with no internal error.
    - An fd probe polled every PHPStan process (parent plus 4 forked workers) every 50 ms through a whole
      cold analysis (`fdwatch.bash`). It found only `phpstan.phar` held open (231 samples) and never
      `phparkitect.phar`. Each worker evidently opens and closes that archive itself.

    The lane's own invariant ("no `phar://` reaches a forked PHPStan") is asserted only for the deadCode
    wrapper, not here. A consumer `phpstan.neon` that includes a PHAR extension or bootstrap is also
    outside the defence. Worth a line in the class record that note 2 asks for.

04. **[reproduced] `DetectorUnpacker` cannot recover from a target directory without its autoloader.**
    Reuse is decided only by `is_file($target/vendor/autoload.php)`
    (`src/Pipeline/Lane/DeadCode/DetectorUnpacker.php:36`). If the hash directory exists without that
    file (hand-pruned, or partly deleted by a cache cleaner), the `rename()` at `:45` fails with
    `Directory not empty` on every run. The exception escapes the lane and aborts the whole `bin/qa`
    run, which `QaApplication` catches at the top level, until someone deletes `var/qa/cache`. Probe
    (`unpacker-probe.php`):
    `2 partial target: THROWS Safe\Exceptions\FilesystemException: rename(.../.unpackj74rba79j63k0TP2idf,.../f56decce6db76e92): Directory not empty`.
    The rename makes a partial copy unreachable by the code's own path, so only outside interference
    gets there. Other properties of `DetectorUnpacker`:

    - **Concurrency:** two concurrent unpacks into one project would hit the same error. The run lock
      prevents that.
    - **Stale copies:** older hash directories (about 560 KB each) are never pruned.
    - **Interrupted extracts:** a SIGKILL during extract leaves a hidden `.unpack*` directory behind,
      and the Turbo installer can leave a `.phpqa-turbo*` directory the same way. Both are harmless.
    - **Permissions:** directories are created `0700`, which is fine for a single user.
    - **Missing cache directory:** `TemporaryDirectory::besides()` creates the cache directory when it
      is absent. Checked against a non-existent `.../absent/var/qa/cache`: it unpacked completely.

05. **(Round-1 note 1, still stands) [read] Red-first ordering is not complete for every behaviour**
    (`93d191a`, and the `0644` chmod in `2901b4e`). That history is unchanged. In this round the #82 red
    (`81883eb`) fails on `Class "LTS\PHPQA\Pipeline\Lane\DeadCode\DetectorUnpacker" not found` (4 errors).
    It never fails on its behavioural pin, the `assertStringNotContainsString('phar://', ...)` lines in
    `DeadCodeToolTest`, because the test errors before it reaches them. The pin is correct and passes
    at the fix, but it was never seen red.

06. **(Round-1 note 2, still stands) [read] A `phpstan.phar` bump without a matching turbo-ext release
    blocks every run.** The PR body now raises this with the Owner next to D2.

07. **(Round-1 note 3, still stands) [read] A malformed `vendor-phar/turbo-ext.json` is not a guided
    preflight refusal.** `PharToolsVerifier` has not changed since `1eb9dca`.

08. **(Round-1 note 4, still stands) [reproduced] The stamp file follows the umask.**
    `phpstan_turbo-8.5.so.source` is `-rw-------`, while the binary is `-rw-r--r--`.

09. **(Round-1 note 5, still stands) [read] Freshness is decided by the stamp only.**

10. **(Round-1 note 6, still stands; one addition) [read] Test edits in fix commits.** `ea343e6` changes the
    red test's `realpath()` to `\Safe\realpath()`. It weakens nothing.

## Checks

### 1. B1: the Turbo binary is replaced atomically [reproduced]

**Fix read.** `TurboInstaller::fetch` (`src/Turbo/TurboInstaller.php:189-202`) now stages in
`TemporaryDirectory::besides($binary, '.phpqa-turbo')`, which is `tempnam()` and `mkdir 0700` in
`dirname($binary)` (the directory is created if absent). It then `chmod 0644`s the extracted file and
only after that `rename()`s it over the binary. Source and target are on one filesystem, so the rename
is atomic and installs a new inode. The staging directory is removed on both paths.

**The red test is genuine.** I ran `bin/phpunit -c qaConfig/phpunit.xml --no-coverage tests/Large/Turbo/TurboInstallerReplacesTheBinaryAtomicallyTest.php`
in my worktree, at both commits:

- At `a7b6835` (the red commit; its `src/` is `ad93c26`'s parent): exit 1.
  `the old binary was rewritten in place, so a PHPStan that has it mapped would crash`, with
  `-'\u{007F}ELF the binary a running PHPStan has mapped'` and `+'\u{007F}ELF the next release's binary'`.
- At `ad93c26` (the fix): `OK (1 test, 9 assertions)`.

The test works as follows:

- It asserts that `/dev/shm` and `sys_get_temp_dir()` are on different devices.
- It places the library on `/dev/shm`, installs one binary and hard-links it.
- It reinstalls, then asserts that the hard link still holds the old bytes.

That models the mapped-file hazard directly. In-place copy changes the shared inode, while an atomic
replace leaves it alone. On a host where `/dev/shm` shared a device with the temp dir the test would
fail loudly rather than pass vacuously.

**The race re-run.** Here, `/tmp` is device 192 (overlay) and the worktree is device 51 (`/dev/sda3`),
which is the cross-device condition. `race.bash` does the following:

1. Clears the result cache.
2. Runs `php vendor-phar/phpstan.phar analyse -c qaConfig/phpstan.neon --no-progress --error-format=raw src tests`
   in the background. Turbo is loaded: `diagnose` reports `Turbo extension: enabled (version 6351afb)`,
   worker binary `.../vendor-phar/turbo-ext/linux-gnu-x86_64/phpstan_turbo-8.5.so (loaded via process restart)`.
3. While that runs, loops `rm -f <so>.source; php bin/turbo-install install` and records the inode after
   each install.

| run | PHPStan exit | reinstalls during the analysis | distinct inodes            |
| --- | ------------ | ------------------------------ | -------------------------- |
| 1   | 1            | 17                             | new inode on every install |
| 2   | 1            | 11                             | new inode on every install |
| 3   | 1            | 11                             | new inode on every install |

Exit 1 in each run is the deliberate fixture finding only:
`tests/assets/psr4/projectInValid/tests/ParseError.php:5:Syntax error, unexpected T_STRING on line 5 [identifier=phpstan.parse]`.
There was no internal error, no crash and no signal. Round 1 crashed 3 of 3 with 3 to 15 reinstalls,
and this round had 11 to 17 per run. No `.phpqa-turbo*` staging directory was left in
`vendor-phar/turbo-ext/linux-gnu-x86_64/`. I did not run a pre-fix crash control deliberately. A
segfault is a halt condition, and round 1 has the crash evidence.

### 2. #82 part 2: the dead-code detector reaches PHPStan as plain files

**[read] The claimed cause matches phpstan.phar's source**, read via `phar://` from the shipped 2.3.0 phar
(dump in `phpstan-src.txt`):

- `src/Turbo/TurboExtensionEnabler.php`: "arm the pthread_atfork hooks that keep phar:// reads safe in
  pcntl_fork()ed workers — libphar serves them through one shared archive fd whose seek cursor forked
  processes would otherwise race on". The guard is
  `Runtime::enablePharForkGuard(Phar::running(false))`, that is, for phpstan.phar only.
- `src/Parallel/ForkParallelChecker.php`: fork is used whenever possible, and from a phar only with
  Turbo active (`'running from a phar without the active turbo extension (its fork guard protects phar:// reads in forked children)'`).
- `phpstan diagnose -c var/qa/deadCode/dead-code.neon --autoload-file <unpacked>/vendor/autoload.php`
  reports `Mechanism: fork (pcntl_fork)`.

A second PHAR opened in the parent before the fork, as `--autoload-file phar://...dead-code-detector.phar/vendor/autoload.php`
did, is therefore unguarded.

**[reproduced] Before and after.** Each run cleared the result cache first, then ran
`QA_READONLY=1 CI=true bin/qa -t deadCode` (`deadcode.bash`). The clear command was
`php vendor-phar/phpstan.phar clear-result-cache -c var/qa/deadCode/dead-code.neon --autoload-file <autoload>`,
which printed `Result cache cleared from directory: /tmp/phpstan`.

| commit                                  | runs | internal-error runs                  | detail                                                                                                                                                                          |
| --------------------------------------- | ---- | ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ad93c26` (pre-fix, `phar://` autoload) | 4    | **2** (exit 1)                       | see below                                                                                                                                                                       |
| `86d2dba` (head, unpacked)              | 6    | **0** (all exit 0, `[OK] No errors`) | autoload from `var/qa/cache/dead-code-detector/f56decce6db76e92/vendor/autoload.php`; the wrapper includes `.../f56decce6db76e92/vendor/shipmonk/dead-code-detector/rules.neon` |

The pre-fix internal errors, each "while analysing file" a valid file:

- `Class "ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin" not found`
- `syntax error, unexpected token "<"`
- `Unclosed '('`
- `Unterminated comment starting line 9`

These are garbled reads of the detector's classes, as claimed.

The author measured 6 of 6 failing before the fix and I measured 2 of 4. That is a lower rate, but
the cause and the fix are confirmed either way.

**[reproduced] Tests.** At the head, `tests/Large/Turbo tests/Small/Turbo tests/Small/Filesystem tests/Small/Pipeline/Lane/DeadCode tests/Small/Pipeline/Lane/DeadCodeToolTest.php tests/Small/Pipeline/Runner`
gives `OK (101 tests, 271 assertions)`. At `81883eb` the red tests error with
`Class ... DetectorUnpacker not found` (see note 5).

`DetectorUnpacker` weaknesses are in note 4. None is reachable through normal use.

### 3. Rules, changelog, scope [read + reproduced]

- **Scope:** `git diff 1eb9dca 86d2dba` contains only the B1 fix and its test, the #82 fix and its tests,
  `TempDir::createUnder`, `docs/tools/deadCode.md`, one CHANGELOG paragraph, and the round-1 report
  copied into the plan folder.
- **Suppressions and test escapes:** none added (no `phpstan-ignore`, `ignoreErrors`,
  `markTestSkipped`, `@codeCoverageIgnore`, `var_dump` or `print_r` in the added lines).
- **Defence Before Fix ordering:** a red commit precedes each fix (`a7b6835` before `ad93c26`, `81883eb`
  before `ea343e6`), and each is individually reachable. See notes 2 and 5 on what the method asks
  beyond that.
- **Changelog:** each non-entry commit carries a `Changelog: none — <reason>` trailer. `ea343e6`
  extends the Unreleased Turbo entry. `GITHUB_BASE_REF=php8.5 QA_READONLY=1 CI=true bin/qa -t changelog`
  exits 0 with `18 watched files changed; recorded by 2 new "## Unreleased" entries.` (Without
  `GITHUB_BASE_REF`, a detached HEAD fails by design. That is an artefact of my checkout.)
- **Issue references:** the commits say `Addresses #82`, with no closing keyword. Part 1 of #82 is a
  separate PR, so this is correct.
- **Docs:** `docs/tools/deadCode.md` describes the unpacked copy accurately.

### 4. Mergeability

- `origin/php8.5` (`b81709b`) is an ancestor of the head, and the PR targets `php8.5`, so there is no
  conflict.
- `mergeStateStatus` is `UNSTABLE` while a check is pending. There are no PR comments.

### 5. CI on `86d2dba`

I waited for the run to finish (`gh run view 37849040951`):

- `Detect PHP Version`: success
- `PHP QA (8.5)`: success
- `Coverage Report`: skipped by its own job condition. It is a coverage-publishing job and did not run
  on this pull request build. I did not read its workflow condition.
- `QA Pipeline` (run 37849044690): success

## Cleanup

My throwaway worktree `untracked/worktrees/verify-83-r2` was removed after the runs. The shared
PHPStan result cache in `/tmp/phpstan` was cleared several times, which costs other sessions a cold
run and nothing else.

B1 is fixed and its red test is genuine. The #82 part 2 cause is confirmed against PHPStan's source
and fixed: before the fix 2 of 4 cold runs hit internal errors, after it 0 of 6. CI is green on the
head. The notes do not block the merge. Under `pr-verification.md` this is in substance PASS WITH
NOTES: notes 1 to 4 should be raised as issues or plan tasks, note 1 (the ShellCheckInstaller
instance of B1's class) above all.

VERDICT: PASS
