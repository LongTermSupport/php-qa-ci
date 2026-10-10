# PHPQA Infection

[Infection](https://infection.github.io/) is a Mutation Testing Framework that runs PHPUnit tests and then makes small modifications to the code and sees if these cause the unit tests to fail.

Infection runs as a **PHAR** from `vendor-phar/infection.phar` (not as a Composer dependency). It requires Xdebug to be available for code coverage.

In PHPQA we run this after the normal PHPUnit run and pass in the coverage generated with PHPUnit. This means that it will only run this tool if you have the `phpUnitCoverage` environment variable set to 1.

## What is mutated

A full run of every source file takes hours on a real project, so by default a branch is held
only to what it changed. `infectionDiffBase` (or `withInfectionDiffBase()` /
`withInfectionFullRun()` in `qaConfig/qa.php`) chooses:

| Setting                                                    | Scope                                                                                                                                 |
| ---------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| unset, `auto`, `withInfectionDiffBase(null)` (the default) | On any branch but the default one: the files changed since the merge base with the default branch. On the default branch: everything. |
| `full`, `withInfectionFullRun()`                           | Everything, on every branch.                                                                                                          |
| a git ref, `withInfectionDiffBase('origin/main')`          | The files committed since that ref.                                                                                                   |

Auto mode takes the default branch from the same detector as the branchNamePolicy lane
(`origin/HEAD`, else `git ls-remote --symref origin HEAD`), and the merge base with
`origin/<default>`, or the local `<default>` when the clone has no remote copy. A pull request
build (GitHub's `GITHUB_BASE_REF`) uses its target branch instead. When no base can be found
(a detached HEAD outside a pull request, an unknown default branch, a branch the clone lacks,
a shallow clone with no merge base) the run is full. The first line the lane prints always
says which scope ran and, for a full run in auto mode, why:

```text
Infection: auto diff mode — branch 'feature/x' against origin/main (merge base 1a2b3c4).
Infection: full run — auto diff mode does not apply: on the default branch 'main'.
Infection: full run — auto diff mode does not apply: HEAD and origin/main share no merge base in this clone, ...
Infection: full run — the change touches configuration every mutant depends on (qaConfig/infection.json), which a diff run cannot judge.
Infection: diff mode — comment-only change, not mutated: src/Foo.php
```

In CI, check out with `fetch-depth: 0` (the shipped workflow templates do), or every run is
full.

A diff run mutates:

- every PHP source file added, modified, renamed (under its new path) or copied since the
  base. A deleted one has nothing left to mutate;
- the source file each changed test is named after: `tests/Unit/Foo/BarTest.php` brings
  `src/Foo/Bar.php` into scope, so a weakened test is checked against the code it pins. The
  match is by name alone, so other source files a test happens to cover are not mutated, and a
  changed test-directory file named after no source (support code, fixtures) is listed in the
  output as not checked.

A change to the configuration every mutant depends on can change the outcome of a mutant in
any file, so it turns the run into a full one, and the lane prints which files caused it.
Those files are exactly:

- the resolved Infection config, and `qaConfig/infection.json`, `infection.json5`,
  `infection.json.dist` and `infection.json5.dist` (adding or removing an override changes
  which config resolves);
- the resolved PHPUnit config, and `qaConfig/phpunit.xml`, `phpunit.xml.dist` and
  `phpunit.dist.xml`;
- the test bootstrap the resolved PHPUnit config names in its `bootstrap` attribute, read from
  the XML.

Only files inside the project count. `composer.json`, `composer.lock` and every other file
under `qaConfig/` neither make the run full nor are mentioned.

A modified or renamed PHP file whose change is only comments, docblocks or whitespace is not
mutated: its tokens (`PhpToken::tokenize()` with `TOKEN_PARSE`, less `T_COMMENT`,
`T_DOC_COMMENT` and `T_WHITESPACE`) are compared with its version at the merge base (a
rename's old path), read with `git show`. String contents and attributes count as code. The
lane names comment-only files on one line. An added or copied file, or one either version of
which cannot be read or parsed, is always mutated. A comment-only change to a test brings
nothing into scope.

A file with a directive the tools read in a comment is the exception. Infection skips code
under `@infection-ignore-all`, and php-code-coverage leaves code marked `@codeCoverageIgnore`,
`@codeCoverageIgnoreStart` / `@codeCoverageIgnoreEnd`, or `@deprecated` (when
`ignoreDeprecatedCodeUnits` is on) out of coverage; which code that is depends on the
comment's form, text and line. When either version of a file has a comment containing
`@infection`, `@codeCoverageIgnore` or `@deprecated`, every comment is compared verbatim with
its line, so any comment change in that file mutates it. Files without such a comment keep
the rule above.

Infection's `ignore` setting also accepts `Class::method::<line>` patterns. A comment-only
change that shifts line numbers can make such a pattern match a different mutant; the file is
still left out, since its code did not change, and the shifted pattern takes effect on the
next run that mutates the file. Pin by `Class::method` rather than by line where you can.

With nothing to mutate the lane skips before any coverage run and says so.

Uncommitted work under `src/`, `tests/` or the files above, untracked files included, is
treated differently by the two diff modes:

- **Auto mode** mutates it as it is on disk, alongside the committed change, and prints a
  `WARNING` naming every such file: that verdict cannot be reproduced from committed history,
  so commit (a WIP commit will do) for the result CI will see. Local work in progress never
  fails the run on this account, and an uncommitted configuration change makes it full. A
  file deleted from disk is left out whether or not its deletion is staged (a committed file
  removed or renamed away, or a staged addition, edit or rename since deleted), since only what
  is on disk can be mutated. Paths are read relative to the project, so a project in a
  subdirectory of its repository is scoped the same way.
- **An explicit base** refuses to run, listing the files, so its verdict is always the one
  committed history gives.

## Configuration

You may need to tell infection where the configuration directory for PHPUnit is. The shipped
[./configDefaults/generic/infection.json](./../../configDefaults/generic/infection.json) already
contains `"phpUnit": {"configDir": "./"}`; to point it elsewhere, override that file in your
`qaConfig/` and change the existing value:

```json
"phpUnit": {
    "configDir": "path/to/directory/with/phpunit.xml"
}
```

### Ignored paths

Infection always runs from a copy of the resolved config the lane writes to
`var/qa/infection-config/infection.json`: every setting Infection resolves against the config
file's directory (source directories, logs, `tmpDir`, the PHPUnit, PHPStan and Mago paths, the
debug log file) made absolute, so it means what the original means, and a `logs.json` added
when the config names none (`var/qa/infection/infection-log.json`), which the lane reads to
check its kills (see [How to fix a failure](#how-to-fix-a-failure)). A log sent to a stream
(`php://stdout`, `php://stderr`, `php://output`) is kept as written, as Infection writes to it
rather than resolving it as a file. The one exception is `logs.json`: the lane cannot read a
stream back, and Infection takes a single JSON log target, so a `logs.json` naming a stream is
replaced in the copy by `var/qa/infection/infection-log.json`, and the run prints a line saying
so.

A `withIgnoredPaths()` entry under one of `infection.json`'s source directories is never
mutated. Infection takes no exclusion on its command line, so the copy adds each ignored path
to `source.excludes` as a regex anchored at its source directory. Ignoring `src/Legacy`
drops `src/Legacy/` and keeps `src/Domain/Legacy/`. An ignored source directory is dropped from
the list, and when every one is ignored the lane is skipped. In diff mode, a changed file under
an ignored path is not mutated either.

Here are the environment variables that you might decide to override:

- **Use Infection** `useInfection`: Set this to 0 to disable Infection
- **Minimum MSI Percentage** `mutationScoreIndicator`: The minimum [MSI](https://infection.github.io/guide/#Mutation-Score-Indicator-MSI) required for PHPQA to pass
- **Minimum Covered MSI Percentage** `coveredCodeMSI`: The minimum [covered MSI](https://infection.github.io/guide/#Covered-Code-Mutation-Score-Indicator) level required for PHPQA to pass
- **Scope** `infectionDiffBase`: unset or `auto` (the default), `full`, or a git ref; see [What is mutated](#what-is-mutated)
- **Diff floor** `infectionDiffCoveredMsi`: the floor of a diff run, for both its MSI and its covered MSI; defaults to `coveredCodeMSI`

#### Minimum Mutation Score Indicators

Infection has been configured to require both a minimum MSI and covered MSI to be achieved for the test to pass.

By default these are set to 60% for MSI, and 80% for covered MSI. These values can be overwritten by using environment
variables. To do this, simply export the following before running qa:

- `mutationScoreIndicator` to set the MSI level
- `coveredCodeMSI` to set the covered MSI level

See the following page for more information on MSIs being used in CI [https://infection.github.io/guide/using-with-ci.html](https://infection.github.io/guide/using-with-ci.html)

##### Setting Minimum Score Indicators

Set the floors permanently for your project in `qaConfig/qa.php`:

```php
return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
    ->withInfectionFloors(msi: 82, coveredMsi: 82);
```

You can see that this is being done in the phpqa project itself in its own [qaConfig/qa.php](./../../qaConfig/qa.php). For a single run the `mutationScoreIndicator` and `coveredCodeMSI` environment variables still work.

A diff run holds the changed files to one floor, the covered-MSI floor above unless given its own (`infectionDiffCoveredMsi`, or the second argument of `withInfectionDiffBase()`), applied to both scores. It mutates uncovered code too (`--with-uncovered`), so a mutant in a line no test runs counts as escaped: a changed file with no test fails its MSI floor rather than passing unseen. Its covered MSI is the score of the covered mutants alone, so the two numbers differ exactly when the change has uncovered lines. Changed files with no mutable code at all (an interface, constants, a DTO of promoted properties) generate no mutant; Infection says so and the run passes rather than scoring 0%. Both kinds of run write the same log files, `var/qa/infection/log.txt` and `summary-log.txt`.

Every floor must be below 100: the configuration refuses 100 or more. Real code has equivalent mutants, mutations no test can tell from the original, so a 100% floor is met only by suppressing mutants or contorting code. 90 to 95 is a healthy gate.

## How to fix a failure

The lane fails when a score falls under its floor. The escaped mutants are listed, each with
its file, line and diff, in `var/qa/infection/log.txt`. For each one, the diff shows a change
to the code that every test still passed with. Write the test that tells the two apart: an
assertion on the exact value, boundary or branch the mutant altered. A covered mutant that
escapes is an assertion the suite is missing; an uncovered one is code no test runs.

Some mutants cannot be killed because they change nothing observable — a `>=` that behaves as
`>` for every reachable input, a cast of a value already of that type. Simplify the code so the
mutation point does not exist rather than writing a test that pretends to pin it.

Lowering a floor is an owner decision recorded in `qaConfig/qa.php`, not a way to make a
change pass.

### Mutants killed although no test ran

Infection scores a mutant as killed whenever the test process exits non-zero, and the lane
skips Infection's initial test run, so a suite that cannot start under Infection "kills" every
mutant and the MSI reads 100%. The lane therefore reads each killed mutant's test output from
Infection's JSON log and recognises two shapes of a run in which no test ran: PHPUnit's
`No tests executed!`, and PHPUnit stopping before the suite started (its banner with no
`Runtime:` line, which is how it reports a bootstrap script or configuration it cannot load).
A run that started the suite and then ended without a summary is not judged, since a test ran
into the mutated code; nor is a mutant Infection scored as an error, nor another test
framework's output.

What it does with them depends on whether any kill was made by a test:

- **No test ran for any killed mutant**: the lane crashes, with no score, whatever the floors
  say. It names the mutants, prints the first one's output and points at the JSON log, which
  holds every mutant's output.
- **Some kills were made by tests**: the suite demonstrably starts under Infection, so a mutant
  that stopped it before any test ran broke code the test bootstrap runs (a bootstrap that
  boots a kernel or seeds a schema from `src/`), and PHPUnit refusing to start is a genuine
  kill. The lane prints how many there were and the first one, and reports the scores as usual.
- **No mutant was killed**: there is nothing to judge.

When the lane crashes, the cause is almost always in the test setup, not in the code or the
tests of it: something that works in a plain `phpunit` run fails under Infection's generated
PHPUnit configuration and bootstrap (`var/qa/infection/tmp/`). Read the printed output: a
PHPUnit extension whose bootstrap failed, a bootstrap script that cannot be found or throws, an
environment the wrapper sets differently. Make the suite start there; a score is only a
measurement once it does. The other cause is a diff run whose every mutant sits in code the test
bootstrap runs: each one stops the bootstrap, so none can reach a test. A full run
(`infectionDiffBase=full`) mutates code a test reaches as well, and tells the two apart. A
passing run that wrote no JSON log crashes too, because nothing then shows that its kills were
made by tests.

One limit of the judgement comes from PHPUnit extensions that replace its output
(`replaceOutput()`). PHPUnit 13 then prints neither its version banner nor the `Runtime:` line
for a run that starts, nor its `No tests executed!` summary, so such a run's kills are judged
only by what the extension prints. An extension that prints a line in the form of PHPUnit's
banner (`PHPUnit <version> by Sebastian Bergmann`) and no `Runtime:` line would make every kill
look as though no test ran, and the lane would crash on a sound suite; one that prints neither
leaves a suite that runs no test unrecognised. PHPUnit's own report of a bootstrap or
configuration it cannot load is printed before any extension is loaded, so it is recognised
either way.

### Per-thread resources: `TEST_TOKEN`

Infection starts every mutant run with `TEST_TOKEN` set to its thread number (`1` to the thread
count), the ParaTest convention. The lane's coverage run sets no `TEST_TOKEN`. A suite that
derives a resource's name from it, typically a Doctrine test database such as
`dbname_suffix: '_test%env(default::TEST_TOKEN)%'`, therefore connects to a different database
in each mutant run than in the coverage run. If those per-thread databases do not exist, every
database-backed test errors on connect and Infection scores each error as a killed mutant: the
run passes on an inflated score.

The lane's check above does not catch this, because the tests do start and PHPUnit reports
their errors. Create and migrate one database (or other per-thread resource) for each thread,
`1` to `withInfectionThreads()`, before the run. Killed mutants whose test output in
`var/qa/infection/infection-log.json` shows connection errors are the sign that they are missing.

#### Disabling Infection

If you would like to disable infection, simply export the environment variable `useInfection` with the value `0`:

```bash
export useInfection=0
vendor/bin/qa
```

## How the lane runs

The lane is `LTS\PHPQA\Pipeline\Lane\InfectionTool` (identifier `phpqaci.infection`). Its pure parts are split out under `Lane/Infection/`: `InfectionDiffBaseResolver` (the auto-mode decision), `InfectionDiffFilter` and `TestSourceMirror` (the changed-file list from `git diff`), `InfectionFullRunTriggers` (the files that force a full run), `CommentOnlyChange` (the token comparison) and `InfectionArguments` (the argv for the full and diff lanes), all unit-tested without a real process.

1. Without Xdebug there is no coverage, so the lane skips.
2. The scope is decided and printed (see [What is mutated](#what-is-mutated)).
3. A diff run checks `git status --porcelain=v1 -z --untracked-files=all` over `src`, `tests` and the full-run triggers (`InfectionFullRunTriggers`): an explicit base refuses a dirty tree; auto mode adds the uncommitted files (made project-relative with `git rev-parse --show-prefix`) to the change and names them in a warning.
4. A diff run lists the change with `git diff <base>...HEAD -z -M --name-status --diff-filter=AMRCD --relative` over the same paths. A trigger in it makes the run full. Each modified or renamed PHP file is compared with `git show <merge base>:<path>` and left out when only its comments or whitespace changed, unless either version has a comment carrying a tool directive. Otherwise the PHP files are passed to Infection as positional absolute paths; an empty list skips before any coverage is generated, so a docs-only change costs no test run. A failing `git diff` or `git status` fails.
5. Coverage is reused when the PHPUnit lane produced it this run (a full pipeline run with a non-empty `var/qa/phpunit_logs/coverage-xml`); otherwise (`-t infection`, or nothing on disk) one Xdebug coverage run generates it. A failing coverage run fails the lane.
6. `var/qa/infection/` is emptied and `vendor-phar/infection.phar` runs without Xdebug at low CPU priority with `--skip-initial-tests`, `--coverage`, `--threads`, `--configuration`, `--log-verbosity=all`, then either `--min-msi --min-covered-msi` (full) or `--with-uncovered --min-msi=<diff floor> --min-covered-msi=<diff floor> --ignore-msi-with-no-mutations` and the paths (diff). Any non-zero exit fails.
7. `VacuousKillDetector` reads the JSON log, before the exit code is judged: no test ran for any killed mutant, an unreadable log, or a passing run with no log crashes the lane; some kills with no test run are named and the exit code then decides.
