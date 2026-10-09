# PHPQA Infection

[Infection](https://infection.github.io/) is a Mutation Testing Framework that runs PHPUnit tests and then makes small modifications to the code and sees if these cause the unit tests to fail.

Infection runs as a **PHAR** from `vendor-phar/infection.phar` (not as a Composer dependency). It requires Xdebug to be available for code coverage.

In PHPQA we run this after the normal PHPUnit run and pass in the coverage generated with PHPUnit. This means that it will only run this tool if you have the `phpUnitCoverage` environment variable set to 1.

## What is mutated

A full run of every source file takes hours on a real project, so by default a branch is held
only to what it changed. `infectionDiffBase` (or `withInfectionDiffBase()` /
`withInfectionFullRun()` in `qaConfig/qa.php`) chooses:

| Setting                                                    | Scope                                                                                                                                   |
| ---------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| unset, `auto`, `withInfectionDiffBase(null)` (the default) | On any branch but the default one: the files committed since the merge base with the default branch. On the default branch: everything. |
| `full`, `withInfectionFullRun()`                           | Everything, on every branch.                                                                                                            |
| a git ref, `withInfectionDiffBase('origin/main')`          | The files committed since that ref.                                                                                                     |

Auto mode takes the default branch from the same detector as the branchNamePolicy lane
(`origin/HEAD`, else `git ls-remote --symref origin HEAD`), and the merge base with
`origin/<default>`, or the local `<default>` when the clone has no remote copy. A pull request
build (GitHub's `GITHUB_BASE_REF`) uses its target branch instead. When no base can be found
(a detached HEAD outside a pull request, an unknown default branch, a branch the clone lacks,
a shallow clone with no merge base) the run is full. The first line the lane prints always
says which scope ran and, for a full run in auto mode, why:

```text
Infection: auto diff mode — branch 'feature/x' against origin/main (merge base 1a2b3c4), committed history only.
Infection: full run — auto diff mode does not apply: on the default branch 'main'.
Infection: full run — auto diff mode does not apply: HEAD and origin/main share no merge base in this clone, ...
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

It reads committed history only. Uncommitted work under `src/` or `tests/` is outside its
scope: an explicit base refuses to run with any, so its verdict is reproducible; auto mode
lists the uncommitted files as not mutated and carries on, so local work in progress never
fails a run. With nothing to mutate the lane skips before any coverage run and says so.
Changes outside `src/` and `tests/` (`phpunit.xml`, `infection.json`, `composer.lock`) are
not checked by a diff run; when one could change every mutant's outcome, run with
`infectionDiffBase=full`.

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

A `withIgnoredPaths()` entry under one of `infection.json`'s source directories is never
mutated. Infection takes no exclusion on its command line, so the lane writes a copy of the
resolved config to `var/qa/infection-config/infection.json` and runs with that: every setting
Infection resolves against the config file's directory (source directories, logs, `tmpDir`,
the PHPUnit, PHPStan and Mago paths, the debug log file) made absolute, and each ignored path
added to `source.excludes` as a regex anchored at its source directory. Ignoring `src/Legacy`
drops `src/Legacy/` and keeps `src/Domain/Legacy/`. An ignored source directory is dropped from
the list, and when every one is ignored the lane is skipped. In diff mode, a changed file under
an ignored path is not mutated either. With no ignored path under a source directory the
resolved config is used as it is.

Here are the environment variables that you might decide to override:

- **Use Infection** `useInfection`: Set this to 0 to disable Infection
- **Minimum MSI Percentage** `mutationScoreIndicator`: The minimum [MSI](https://infection.github.io/guide/#Mutation-Score-Indicator-MSI) required for PHPQA to pass
- **Minimum Covered MSI Percentage** `coveredCodeMSI`: The minimum [covered MSI](https://infection.github.io/guide/#Covered-Code-Mutation-Score-Indicator) level required for PHPQA to pass
- **Scope** `infectionDiffBase`: unset or `auto` (the default), `full`, or a git ref; see [What is mutated](#what-is-mutated)
- **Diff floor** `infectionDiffCoveredMsi`: the covered-MSI floor of a diff run; defaults to `coveredCodeMSI`

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

A diff run holds the changed files to the covered-MSI floor above unless given its own (`infectionDiffCoveredMsi`, or the second argument of `withInfectionDiffBase()`). It has no separate MSI floor: Infection mutates only covered code, so a run's MSI and covered MSI are the same number, and code no test runs is the coverage report's finding rather than this lane's. Changed files with no mutable code (an interface, a DTO of promoted properties) generate no mutant; Infection says so and the run passes rather than scoring 0%. Both kinds of run write the same log files, `var/qa/infection/log.txt` and `summary-log.txt`.

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

#### Disabling Infection

If you would like to disable infection, simply export the environment variable `useInfection` with the value `0`:

```bash
export useInfection=0
vendor/bin/qa
```

## How the lane runs

The lane is `LTS\PHPQA\Pipeline\Lane\InfectionTool` (identifier `phpqaci.infection`). Its pure parts are split out under `Lane/Infection/`: `InfectionDiffBaseResolver` (the auto-mode decision), `InfectionDiffFilter` and `TestSourceMirror` (the changed-file list from `git diff`) and `InfectionArguments` (the argv for the full and diff lanes), all unit-tested without a real process.

1. Without Xdebug there is no coverage, so the lane skips.
2. The scope is decided and printed (see [What is mutated](#what-is-mutated)).
3. A diff run checks `git status --porcelain -- src tests`: an explicit base refuses a dirty tree; auto mode lists it as not mutated.
4. A diff run scopes mutation to `git diff <base>...HEAD -z -M --name-status --diff-filter=AMRCD --relative -- src tests`, passed to Infection as positional absolute paths. An empty list skips before any coverage is generated, so a docs-only or config-only change costs no test run; a failing `git diff` fails.
5. Coverage is reused when the PHPUnit lane produced it this run (a full pipeline run with a non-empty `var/qa/phpunit_logs/coverage-xml`); otherwise (`-t infection`, or nothing on disk) one Xdebug coverage run generates it. A failing coverage run fails the lane.
6. `var/qa/infection/` is emptied and `vendor-phar/infection.phar` runs without Xdebug at low CPU priority with `--skip-initial-tests`, `--coverage`, `--threads`, `--configuration`, `--log-verbosity=all`, then either `--min-msi --min-covered-msi` (full) or `--min-covered-msi=<infectionDiffCoveredMsi> --ignore-msi-with-no-mutations` and the paths (diff). Any non-zero exit fails.
